<?php

/**
 * Plantillas de correo de Agency OS: valores por defecto. El Superadministrador puede editar asunto y cuerpo,
 * y activar o desactivar cada correo desde Administración → Plantillas de correo (se guarda en `mail_templates`).
 *
 * Campos: group, label, description, audience (cliente|equipo), motive (sales|billing|support|null → bloque de
 * WhatsApp), locked (no se puede desactivar: sin ese correo alguien quedaría sin acceso), enabled (por defecto),
 * subject, body (Markdown), variables [nombre => [label, sample, raw?]].
 *
 * En asunto y cuerpo, {variable} se reemplaza por su valor (escapado; las `raw` ya vienen formateadas).
 * Un botón se escribe: [[boton: Texto | {url}]]
 */

return [
    // Variables disponibles en todos los correos (datos de Configuración → Marca y contacto).
    'common_variables' => [
        'empresa' => 'Nombre de la empresa',
        'sitio_web' => 'Sitio web',
        'email_contacto' => 'Email de contacto',
        'telefono' => 'Teléfono',
    ],

    'groups' => ['Clientes', 'Finanzas', 'Equipo', 'Seguridad y accesos', 'Sistema', 'Personalizadas'],

    // Texto con el que arranca una plantilla nueva (variables: {nombre}, {asunto}, {mensaje} + las comunes).
    'custom_starter' => [
        'subject' => '{asunto}',
        'body' => <<<'MD'
# Hola, {nombre}

{mensaje}

Saludos,<br>
Equipo {empresa}
MD,
    ],

    'templates' => [
        // ---------------------------------------------------------------- Clientes
        'quote.sent' => [
            'group' => 'Clientes',
            'label' => 'Envío de presupuesto',
            'description' => 'Se manda al cliente cuando enviás un presupuesto por email (con el PDF adjunto).',
            'audience' => 'cliente',
            'motive' => 'sales',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Presupuesto {numero} · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Gracias por tu interés en **{empresa}**. Te compartimos el presupuesto **{numero}** por un total de **{total}**.

{vigencia_texto}

Adjuntamos el detalle en PDF, y también podés verlo online:

[[boton: Ver presupuesto | {url}]]

Si querés ajustar algo o tenés dudas, respondé este correo y lo vemos juntos.

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre del destinatario', 'sample' => 'Camila Fernández'],
                'numero' => ['label' => 'Número de presupuesto', 'sample' => 'P-2026-0042'],
                'total' => ['label' => 'Total formateado', 'sample' => 'ARS 450.000,00'],
                'vigencia_texto' => ['label' => 'Frase de vigencia (vacía si no tiene)', 'sample' => 'La propuesta es válida hasta el 30 de diciembre de 2026.'],
                'url' => ['label' => 'Enlace para ver el presupuesto', 'sample' => 'https://agencyos.example.com/p/demo'],
            ],
        ],

        'announcement.sent' => [
            'group' => 'Clientes',
            'label' => 'Comunicado a clientes',
            'description' => 'Aviso masivo que enviás desde Finanzas → Comunicados.',
            'audience' => 'cliente',
            'motive' => null,
            'locked' => false,
            'enabled' => true,
            'subject' => '{asunto}',
            'body' => <<<'MD'
# Hola, {nombre}

{mensaje}

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre del cliente', 'sample' => 'Estudio Rivera'],
                'asunto' => ['label' => 'Asunto cargado en el comunicado', 'sample' => 'Novedades de diciembre'],
                'mensaje' => ['label' => 'Texto cargado en el comunicado', 'sample' => "Les contamos que el 24 y 31 de diciembre no habrá atención.\nRetomamos el 2 de enero."],
            ],
        ],

        // ---------------------------------------------------------------- Finanzas
        'invoice.reminder_before' => [
            'group' => 'Finanzas',
            'label' => 'Recordatorio de pago (antes del vencimiento)',
            'description' => 'Se manda al cliente unos días antes de que venza un cobro (los días se definen en Configuración → Mora y suspensión).',
            'audience' => 'cliente',
            'motive' => 'billing',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Recordatorio de pago · vence el {vencimiento_corto} · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Te recordamos que el pago de **{concepto}** (cobro {numero}) vence el **{vencimiento}**.

<x-panel>
**Importe:** {importe}
</x-panel>

{datos_bancarios}

Si ya realizaste el pago, avisanos respondiendo este correo con el comprobante y lo registramos. ¡Gracias!

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre del cliente', 'sample' => 'Estudio Rivera'],
                'concepto' => ['label' => 'Concepto del cobro', 'sample' => 'Abono mensual de redes sociales'],
                'numero' => ['label' => 'Número de cobro', 'sample' => 'C-2026-0118'],
                'importe' => ['label' => 'Importe formateado', 'sample' => 'ARS 180.000,00'],
                'vencimiento' => ['label' => 'Fecha de vencimiento (larga)', 'sample' => '10 de diciembre de 2026'],
                'vencimiento_corto' => ['label' => 'Fecha de vencimiento (dd/mm)', 'sample' => '10/12'],
                'datos_bancarios' => ['label' => 'Bloque con datos bancarios (vacío si no están cargados)', 'sample' => "Podés abonar por transferencia a:\n\n- **Titular:** Laia Estudio Creativo Digital\n- **Banco:** Banco Ejemplo\n- **Alias:** laia.estudio", 'raw' => true],
            ],
        ],

        'invoice.reminder_overdue' => [
            'group' => 'Finanzas',
            'label' => 'Aviso de pago vencido (mora)',
            'description' => 'Se manda al cliente cuando un cobro ya venció y no está acreditado.',
            'audience' => 'cliente',
            'motive' => 'billing',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Tu pago está vencido · {numero} · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Te escribimos porque el pago de **{concepto}** (cobro {numero}) venció el {vencimiento} y todavía no lo vemos acreditado.

<x-panel>
**Importe:** {importe}
</x-panel>

{datos_bancarios}

Si ya realizaste el pago, avisanos respondiendo este correo con el comprobante y lo registramos. ¡Gracias!

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre del cliente', 'sample' => 'Estudio Rivera'],
                'concepto' => ['label' => 'Concepto del cobro', 'sample' => 'Abono mensual de redes sociales'],
                'numero' => ['label' => 'Número de cobro', 'sample' => 'C-2026-0118'],
                'importe' => ['label' => 'Importe formateado', 'sample' => 'ARS 180.000,00'],
                'vencimiento' => ['label' => 'Fecha de vencimiento (larga)', 'sample' => '10 de diciembre de 2026'],
                'datos_bancarios' => ['label' => 'Bloque con datos bancarios (vacío si no están cargados)', 'sample' => "Podés abonar por transferencia a:\n\n- **Titular:** Laia Estudio Creativo Digital\n- **Banco:** Banco Ejemplo\n- **Alias:** laia.estudio", 'raw' => true],
            ],
        ],

        'finance.payout_generated' => [
            'group' => 'Finanzas',
            'label' => 'Liquidación generada (al equipo)',
            'description' => 'Avisa a la persona del equipo cuando se cobra un cliente y le corresponde una liquidación.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => true,
            'subject' => 'Se generó una liquidación a tu favor · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Se cobró **{cliente}** y te corresponden **{importe}**.

[[boton: Ver mis liquidaciones | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'cliente' => ['label' => 'Cliente que pagó', 'sample' => 'Estudio Rivera'],
                'importe' => ['label' => 'Importe de la liquidación', 'sample' => 'ARS 54.000,00'],
                'url' => ['label' => 'Enlace a liquidaciones', 'sample' => 'https://agencyos.example.com/liquidaciones'],
            ],
        ],

        'finance.payout_settled' => [
            'group' => 'Finanzas',
            'label' => 'Liquidación pagada (al equipo)',
            'description' => 'Avisa a la persona del equipo cuando se le paga una liquidación.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => true,
            'subject' => 'Te liquidaron {importe} · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Te liquidamos **{importe}**. Podés ver el detalle en tu panel.

[[boton: Ver mis liquidaciones | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'importe' => ['label' => 'Importe liquidado', 'sample' => 'ARS 54.000,00'],
                'url' => ['label' => 'Enlace a liquidaciones', 'sample' => 'https://agencyos.example.com/liquidaciones'],
            ],
        ],

        // ---------------------------------------------------------------- Equipo
        'assignment.client' => [
            'group' => 'Equipo',
            'label' => 'Cliente asignado',
            'description' => 'Avisa a la persona del equipo cuando le asignan un cliente.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => true,
            'subject' => 'Te asignaron un cliente: {cliente}',
            'body' => <<<'MD'
# Hola, {nombre}

**{asignado_por}** te asignó al cliente **{cliente}**.

[[boton: Abrir el cliente | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona asignada', 'sample' => 'Julián Pérez'],
                'cliente' => ['label' => 'Nombre del cliente', 'sample' => 'Estudio Rivera'],
                'asignado_por' => ['label' => 'Quién hizo la asignación', 'sample' => 'Matías Ríos'],
                'url' => ['label' => 'Enlace al cliente', 'sample' => 'https://agencyos.example.com/clientes/1'],
            ],
        ],

        'assignment.card' => [
            'group' => 'Equipo',
            'label' => 'Tarjeta asignada',
            'description' => 'Avisa cuando asignan una tarjeta de un tablero Kanban. Puede ser frecuente: activalo solo si lo necesitan.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => false,
            'subject' => 'Te asignaron una tarjeta en {cliente}',
            'body' => <<<'MD'
# Hola, {nombre}

**{asignado_por}** te asignó la tarjeta **«{tarjeta}»** en el tablero de **{cliente}**.

[[boton: Abrir el tablero | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona asignada', 'sample' => 'Julián Pérez'],
                'tarjeta' => ['label' => 'Título de la tarjeta', 'sample' => 'Diseñar pieza de Navidad'],
                'cliente' => ['label' => 'Cliente del tablero', 'sample' => 'Estudio Rivera'],
                'asignado_por' => ['label' => 'Quién hizo la asignación', 'sample' => 'Matías Ríos'],
                'url' => ['label' => 'Enlace al tablero', 'sample' => 'https://agencyos.example.com/clientes/1/tablero'],
            ],
        ],

        'message.mention' => [
            'group' => 'Equipo',
            'label' => 'Mención en mensajería',
            'description' => 'Avisa por email cuando mencionan a alguien en un chat. Viene desactivado: ya llega la notificación dentro de la app.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => false,
            'subject' => '{autor} te mencionó en un mensaje',
            'body' => <<<'MD'
# Hola, {nombre}

**{autor}** te mencionó:

> {vista_previa}

[[boton: Abrir la conversación | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona mencionada', 'sample' => 'Julián Pérez'],
                'autor' => ['label' => 'Quién escribió el mensaje', 'sample' => 'Matías Ríos'],
                'vista_previa' => ['label' => 'Inicio del mensaje', 'sample' => '@Julián ¿podés revisar la grilla de enero cuando puedas?'],
                'url' => ['label' => 'Enlace a la conversación', 'sample' => 'https://agencyos.example.com/mensajes?c=3'],
            ],
        ],

        // ---------------------------------------------------------------- Seguridad y accesos
        'auth.invitation' => [
            'group' => 'Seguridad y accesos',
            'label' => 'Invitación al equipo',
            'description' => 'Alta de una persona nueva: enlace para crear su contraseña. No se puede desactivar (sin este correo no podrían entrar).',
            'audience' => 'equipo',
            'motive' => 'support',
            'locked' => true,
            'enabled' => true,
            'subject' => 'Te invitamos a Agency OS · {empresa}',
            'body' => <<<'MD'
# ¡Hola, {nombre}!

Te dieron de alta en Agency OS, el backoffice de {empresa}.

Para empezar, configurá tu contraseña con el siguiente botón:

[[boton: Configurar mi contraseña | {url}]]

El enlace es personal y vence en {horas} horas. Si vence, pedile a Dirección que te reenvíe la invitación.

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'url' => ['label' => 'Enlace para crear la contraseña', 'sample' => 'https://agencyos.example.com/invitacion/demo'],
                'horas' => ['label' => 'Horas de validez del enlace', 'sample' => '72'],
            ],
        ],

        'auth.password_reset' => [
            'group' => 'Seguridad y accesos',
            'label' => 'Restablecer contraseña',
            'description' => 'Enlace para elegir una contraseña nueva. No se puede desactivar.',
            'audience' => 'equipo',
            'motive' => 'support',
            'locked' => true,
            'enabled' => true,
            'subject' => 'Restablecé tu contraseña · {empresa}',
            'body' => <<<'MD'
# ¡Hola, {nombre}!

Recibimos un pedido para restablecer la contraseña de tu cuenta en Agency OS.

[[boton: Elegir una contraseña nueva | {url}]]

El enlace es personal, se puede usar una sola vez y vence en {minutos} minutos.

Si no lo pediste vos, ignorá este correo: tu contraseña actual sigue siendo la misma.

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'url' => ['label' => 'Enlace para restablecer', 'sample' => 'https://agencyos.example.com/restablecer/demo'],
                'minutos' => ['label' => 'Minutos de validez del enlace', 'sample' => '60'],
            ],
        ],

        'auth.recovery_code_used' => [
            'group' => 'Seguridad y accesos',
            'label' => 'Ingreso con código de recuperación',
            'description' => 'Alerta de seguridad: alguien entró con un código de recuperación de dos pasos.',
            'audience' => 'equipo',
            'motive' => 'support',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Ingresaste con un código de recuperación · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

Ingresaste a Agency OS con un código de recuperación. Cada código se usa una sola vez. {restantes_texto}

**Si no fuiste vos, cambiá tu contraseña ahora.**

[[boton: Ir a mi perfil | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'restantes_texto' => ['label' => 'Cuántos códigos quedan', 'sample' => 'Te quedan 7.'],
                'url' => ['label' => 'Enlace al perfil', 'sample' => 'https://agencyos.example.com/perfil'],
            ],
        ],

        'auth.two_factor_reset' => [
            'group' => 'Seguridad y accesos',
            'label' => 'Verificación en dos pasos restablecida',
            'description' => 'Alerta de seguridad: alguien desactivó los dos pasos de una cuenta.',
            'audience' => 'equipo',
            'motive' => 'support',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Restablecieron tu verificación en dos pasos · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

**{quien}** desactivó tu verificación en dos pasos (por ejemplo, por un dispositivo perdido). Volvé a activarla desde tu perfil.

[[boton: Ir a mi perfil | {url}]]

Si no esperabas este cambio, avisanos.

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'quien' => ['label' => 'Quién hizo el cambio', 'sample' => 'Matías Ríos'],
                'url' => ['label' => 'Enlace al perfil', 'sample' => 'https://agencyos.example.com/perfil'],
            ],
        ],

        'auth.password_changed_by_admin' => [
            'group' => 'Seguridad y accesos',
            'label' => 'Contraseña cambiada por un administrador',
            'description' => 'Alerta de seguridad: Dirección o el sysadmin cambiaron la contraseña de alguien del equipo. Nunca incluye la clave.',
            'audience' => 'equipo',
            'motive' => 'support',
            'locked' => false,
            'enabled' => true,
            'subject' => 'Cambiaron tu contraseña · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

**{quien}** cambió la contraseña de tu cuenta. Te la va a pasar por un medio seguro. Cuando ingreses, te recomendamos cambiarla desde tu perfil.

[[boton: Ir a mi perfil | {url}]]

Si no esperabas este cambio, avisanos de inmediato.

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Julián Pérez'],
                'quien' => ['label' => 'Quién hizo el cambio', 'sample' => 'Matías Ríos'],
                'url' => ['label' => 'Enlace al perfil', 'sample' => 'https://agencyos.example.com/perfil'],
            ],
        ],

        // ---------------------------------------------------------------- Sistema
        'system.laiatime_alert' => [
            'group' => 'Sistema',
            'label' => 'Alerta de LaiaTime',
            'description' => 'Avisos de la integración con LaiaTime (sincronización, deudas). Viene desactivado: llega dentro de la app.',
            'audience' => 'equipo',
            'motive' => null,
            'locked' => false,
            'enabled' => false,
            'subject' => '{titulo} · {empresa}',
            'body' => <<<'MD'
# Hola, {nombre}

**{titulo}**

{detalle}

[[boton: Abrir LaiaTime | {url}]]

Saludos,<br>
Equipo {empresa}
MD,
            'variables' => [
                'nombre' => ['label' => 'Nombre de la persona', 'sample' => 'Matías Ríos'],
                'titulo' => ['label' => 'Título de la alerta', 'sample' => 'Cliente suspendido por mora'],
                'detalle' => ['label' => 'Detalle de la alerta', 'sample' => 'Estudio Rivera pasó a estado «Suspendido» por falta de pago.'],
                'url' => ['label' => 'Enlace a LaiaTime', 'sample' => 'https://agencyos.example.com/admin/laiatime'],
            ],
        ],
    ],
];
