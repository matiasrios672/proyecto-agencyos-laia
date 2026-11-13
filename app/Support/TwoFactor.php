<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verificación en dos pasos (TOTP, RFC 6238) con códigos de recuperación de un solo uso.
 *
 *  - El secreto y los códigos de recuperación se guardan cifrados (casts del modelo User).
 *  - Los códigos de recuperación se guardan como HMAC (nunca en claro): se muestran una sola vez al generarlos.
 *  - Un código TOTP no se puede reutilizar: se recuerda el último paso aceptado (`two_factor_last_step`).
 */
class TwoFactor
{
    public const RECOVERY_CODES = 10;

    /** Ventana de tolerancia: ±1 paso de 30 s (reloj desfasado). */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google) {}

    public function newSecret(): string
    {
        return $this->google->generateSecretKey(32);
    }

    /** URI otpauth:// que escanean las apps (Google Authenticator, Authy, 1Password…). */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google->getQRCodeUrl(config('app.name', 'Agency OS'), $user->email, $secret);
    }

    /** ¿El rol del usuario obliga a tener 2FA según la configuración del sysadmin? */
    public static function requiredFor(User $user): bool
    {
        return Settings::get('security.require_2fa_privileged') === '1' && $user->hasAnyRole(['sysadmin', 'dueno']);
    }

    /**
     * Confirma el alta: valida el primer código contra el secreto pendiente y activa el 2FA.
     *
     * @return list<string>|null los 10 códigos de recuperación en claro (se muestran una sola vez), o null si el código no sirve
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if (! $user->hasPendingTwoFactor()) {
            return null;
        }

        $step = $this->newerStep($user, $this->digits($code));
        if ($step === null) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map($this->hash(...), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Valida el segundo factor del login y lo consume de forma atómica (dos envíos simultáneos del mismo código
     * no pasan los dos).
     *
     * @return 'totp'|'recovery'|null cómo se validó, o null si no es válido
     */
    public function attempt(int $userId, string $input): ?string
    {
        return DB::transaction(function () use ($userId, $input) {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            if (! $user || ! $user->hasTwoFactorEnabled()) {
                return null;
            }

            $digits = $this->digits($input);
            if (preg_match('/^\d{6}$/', $digits)) {
                $step = $this->newerStep($user, $digits);
                if ($step === null) {
                    return null;
                }
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return 'totp';
            }

            return $this->consumeRecoveryCode($user, $input) ? 'recovery' : null;
        });
    }

    /** Códigos nuevos (reemplazan a todos los anteriores). @return list<string> en claro */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->save();

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** Quita todo rastro del 2FA del usuario. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * Paso TOTP en el que el código es válido, solo si es ESTRICTAMENTE posterior al último aceptado.
     * (`verifyKeyNewer` de la librería vuelve a aceptar el mismo paso, por eso la comparación es nuestra.)
     */
    private function newerStep(User $user, string $digits): ?int
    {
        // Se busca el paso exacto dentro de la ventana (no se usa el valor de retorno de verifyKeyNewer: devuelve
        // `true` en vez del paso cuando no hay uno previo). Se recorre toda la ventana para no filtrar por tiempo.
        $current = $this->google->getTimestamp();
        $matched = null;

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals($this->google->oathTotp($user->two_factor_secret, $step), $digits)) {
                $matched = $step;
            }
        }

        $last = $user->two_factor_last_step;

        return ($matched === null || ($last !== null && $matched <= $last)) ? null : $matched;
    }

    private function consumeRecoveryCode(User $user, string $input): bool
    {
        $hash = $this->hash($input);
        $codes = $user->two_factor_recovery_codes ?? [];

        // hash_equals en cada comparación: no filtra por tiempo cuál era el código correcto.
        $index = null;
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                $index = $i;
            }
        }
        if ($index === null) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /** @return list<string> formato xxxxx-xxxxx (10 caracteres, ~52 bits) */
    private function generateRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // sin caracteres ambiguos (i, l, o, 0, 1)
        $codes = [];

        while (count($codes) < self::RECOVERY_CODES) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return array_values(array_unique($codes));
    }

    /** HMAC con la clave de la app: rápido (los códigos tienen mucha entropía) y sin guardar el código en claro. */
    private function hash(string $code): string
    {
        $normalized = strtolower(preg_replace('/[\s-]+/', '', $code));

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    private function digits(string $input): string
    {
        return preg_replace('/\s+/', '', $input);
    }
}
