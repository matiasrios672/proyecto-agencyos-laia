<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Ajustes del sistema editables desde la UI. Cacheados en Redis; los secretos se guardan cifrados.
 */
class Settings
{
    private const CACHE_KEY = 'laia.settings';

    /** @return array<string, array{group: string, label: string, type: string, secret: bool, default: ?string}> */
    public static function definitions(): array
    {
        $defs = [];
        foreach (config('laia.settings') as $group => $items) {
            foreach ($items as $key => $meta) {
                $defs[$key] = [
                    'group' => $group,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'secret' => (bool) ($meta['secret'] ?? false),
                    'default' => $meta['default'] ?? null,
                ];
            }
        }

        return $defs;
    }

    /** @return array<string, ?string> valores ya descifrados */
    public static function all(): array
    {
        return Cache::store('redis')->rememberForever(self::CACHE_KEY, function () {
            $values = [];
            foreach (Setting::all() as $row) {
                $values[$row->key] = $row->is_secret && $row->value !== null && $row->value !== ''
                    ? Crypt::decryptString($row->value)
                    : $row->value;
            }

            return $values;
        });
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }

        return self::definitions()[$key]['default'] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $def = self::definitions()[$key] ?? null;
        abort_if($def === null, 422, "Ajuste desconocido: {$key}");

        $stored = ($def['secret'] && $value !== null && $value !== '') ? Crypt::encryptString($value) : $value;

        Setting::updateOrCreate(
            ['key' => $key],
            ['group' => $def['group'], 'value' => $stored, 'is_secret' => $def['secret']],
        );

        self::flush();
    }

    public static function flush(): void
    {
        Cache::store('redis')->forget(self::CACHE_KEY);
    }
}
