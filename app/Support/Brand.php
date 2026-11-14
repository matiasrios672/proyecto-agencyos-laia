<?php

namespace App\Support;

/**
 * Identidad y datos de contacto de Laia para los correos (todo editable desde Configuración del sistema).
 *
 * Los correos se arman con estos datos: cabecera con logo, pie con contacto y un bloque de atención por
 * WhatsApp según el motivo (comercial, cobranzas o soporte).
 */
class Brand
{
    /** Motivos de contacto: título del bloque, texto del botón y mensaje precargado por defecto. */
    public const MOTIVES = [
        'sales' => [
            'title' => '¿Querés avanzar o ajustar la propuesta?',
            'text' => 'Escribinos y lo vemos juntos, sin vueltas.',
            'message' => 'Hola Laia, quiero consultar por el presupuesto que me enviaron.',
        ],
        'billing' => [
            'title' => '¿Dudas con tu pago o ya transferiste?',
            'text' => 'Escribinos y lo resolvemos. Si ya pagaste, mandanos el comprobante.',
            'message' => 'Hola Laia, les escribo por un pago.',
        ],
        'support' => [
            'title' => '¿Necesitás ayuda con tu acceso?',
            'text' => 'Nuestro equipo te da una mano.',
            'message' => 'Hola Laia, necesito ayuda con mi acceso a Agency OS.',
        ],
    ];

    public static function company(): string
    {
        return self::value('brand.company') ?? 'Laia Estudio Creativo Digital';
    }

    public static function tagline(): ?string
    {
        return self::value('brand.tagline');
    }

    /** URL pública del logo (blanco, para la cabecera azul). Configurable; si no, el logo oficial incluido. */
    public static function logoUrl(): string
    {
        return self::value('brand.logo_url') ?? rtrim((string) config('app.url'), '/').'/brand/logo-white.png';
    }

    /** @return array{website: ?string, email: ?string, phone: ?string, instagram: ?string, address: ?string} */
    public static function contact(): array
    {
        return [
            'website' => self::value('brand.website'),
            'email' => self::value('brand.email'),
            'phone' => self::value('brand.phone'),
            'instagram' => self::value('brand.instagram'),
            'address' => self::value('brand.address'),
        ];
    }

    /** Datos para el bloque de WhatsApp de un motivo, o null si no hay número configurado. */
    public static function whatsapp(?string $motive): ?array
    {
        if ($motive === null || ! isset(self::MOTIVES[$motive])) {
            return null;
        }

        $number = self::digits(self::value("brand.whatsapp_{$motive}") ?? self::value('brand.whatsapp'));
        if ($number === null) {
            return null;
        }

        $message = self::value("brand.whatsapp_msg_{$motive}") ?? self::MOTIVES[$motive]['message'];

        return [
            'title' => self::MOTIVES[$motive]['title'],
            'text' => self::MOTIVES[$motive]['text'],
            'url' => 'https://wa.me/'.$number.'?text='.rawurlencode($message),
            'number' => $number,
        ];
    }

    /** Enlace de WhatsApp general del pie (sin mensaje precargado). */
    public static function whatsappGeneralUrl(): ?string
    {
        $number = self::digits(self::value('brand.whatsapp'));

        return $number ? 'https://wa.me/'.$number : null;
    }

    /** Teléfono en formato legible para mostrar. */
    public static function phoneHref(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return $digits ? 'tel:+'.$digits : null;
    }

    /** Deja solo dígitos (con código de país). Devuelve null si queda vacío. */
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }

    private static function value(string $key): ?string
    {
        try {
            $value = Settings::get($key);
        } catch (\Throwable) {
            return null; // Redis/DB aún no disponibles: se usan los valores por defecto.
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
