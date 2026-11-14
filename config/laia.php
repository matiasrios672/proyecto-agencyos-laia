<?php

/**
 * Definición de los ajustes editables desde el panel de Configuración del Sistema.
 * Cada clave: label, type (text|password|number|boolean|textarea), secret (se cifra), default.
 */
return [
    'roles' => ['sysadmin', 'dueno', 'empleado'],

    'role_labels' => [
        'sysadmin' => 'Superadministrador',
        'dueno' => 'Dirección',
        'empleado' => 'Empleado',
    ],

    // Permisos granulares, agrupados para la matriz visual.
    'permissions' => [
        'Clientes' => [
            'clients.view' => 'Ver clientes asignados',
            'clients.view_all' => 'Ver todos los clientes',
            'clients.manage' => 'Crear / editar / asignar clientes',
        ],
        'Bóveda' => [
            'vault.view' => 'Leer y copiar credenciales',
            'vault.manage' => 'Crear / editar credenciales',
        ],
        'Contenido' => [
            'content.view' => 'Ver grillas y tableros',
            'content.manage' => 'Gestionar grillas y tableros',
        ],
        'Presupuestos' => [
            'quotes.view' => 'Ver presupuestos',
            'quotes.manage' => 'Crear / aprobar presupuestos',
        ],
        'Finanzas' => [
            'finance.view' => 'Ver finanzas completas',
            'finance.manage' => 'Gestionar cobros, egresos y liquidaciones',
            'payouts.view_own' => 'Ver mis liquidaciones',
        ],
        'Equipo' => [
            'employees.manage' => 'Alta / baja de empleados',
        ],
        'Mensajería' => [
            'messages.use' => 'Usar mensajería y notificaciones',
        ],
    ],

    // Permisos por defecto de cada rol (sysadmin pasa por Gate::before).
    'role_defaults' => [
        'dueno' => [
            'clients.view', 'clients.view_all', 'clients.manage', 'vault.view', 'vault.manage',
            'content.view', 'content.manage', 'quotes.view', 'quotes.manage',
            'finance.view', 'finance.manage', 'payouts.view_own', 'employees.manage', 'messages.use',
        ],
        'empleado' => [
            'clients.view', 'vault.view', 'content.view', 'content.manage', 'payouts.view_own', 'messages.use',
        ],
    ],

    'settings' => [
        'Correo (SMTP)' => [
            'smtp.host' => ['label' => 'Servidor SMTP', 'type' => 'text', 'default' => 'smtp.example.com'],
            'smtp.port' => ['label' => 'Puerto', 'type' => 'number', 'default' => '587'],
            'smtp.username' => ['label' => 'Usuario', 'type' => 'text', 'default' => ''],
            'smtp.password' => ['label' => 'Contraseña', 'type' => 'password', 'secret' => true, 'default' => ''],
            'smtp.encryption' => ['label' => 'Cifrado (tls / ssl)', 'type' => 'text', 'default' => 'tls'],
            'smtp.from_address' => ['label' => 'Remitente (email)', 'type' => 'text', 'default' => 'hola@laia.studio'],
            'smtp.from_name' => ['label' => 'Remitente (nombre)', 'type' => 'text', 'default' => 'Laia Estudio Creativo Digital'],
        ],
        'Marca y contacto (correos)' => [
            'brand.company' => ['label' => 'Nombre de la empresa', 'type' => 'text', 'default' => 'Laia Estudio Creativo Digital'],
            'brand.tagline' => ['label' => 'Bajada de la marca (cabecera del correo)', 'type' => 'text', 'default' => 'Estudio creativo digital'],
            'brand.logo_url' => ['label' => 'URL pública del logo blanco (vacío = logo oficial incluido)', 'type' => 'text', 'default' => ''],
            'brand.website' => ['label' => 'Sitio web (con https://)', 'type' => 'text', 'default' => ''],
            'brand.email' => ['label' => 'Email de contacto', 'type' => 'text', 'default' => ''],
            'brand.phone' => ['label' => 'Teléfono (como querés que se vea)', 'type' => 'text', 'default' => ''],
            'brand.instagram' => ['label' => 'Instagram (URL completa)', 'type' => 'text', 'default' => ''],
            'brand.address' => ['label' => 'Dirección / ciudad', 'type' => 'text', 'default' => ''],
            'brand.whatsapp' => ['label' => 'WhatsApp general (con código de país, solo números. Ej: 5493425551234)', 'type' => 'text', 'default' => ''],
            'brand.whatsapp_sales' => ['label' => 'WhatsApp Comercial / presupuestos (vacío = el general)', 'type' => 'text', 'default' => ''],
            'brand.whatsapp_billing' => ['label' => 'WhatsApp Cobranzas / pagos (vacío = el general)', 'type' => 'text', 'default' => ''],
            'brand.whatsapp_support' => ['label' => 'WhatsApp Soporte / accesos (vacío = el general)', 'type' => 'text', 'default' => ''],
            'brand.whatsapp_msg_sales' => ['label' => 'Mensaje precargado: Comercial', 'type' => 'text', 'default' => 'Hola Laia, quiero consultar por el presupuesto que me enviaron.'],
            'brand.whatsapp_msg_billing' => ['label' => 'Mensaje precargado: Cobranzas', 'type' => 'text', 'default' => 'Hola Laia, les escribo por un pago.'],
            'brand.whatsapp_msg_support' => ['label' => 'Mensaje precargado: Soporte', 'type' => 'text', 'default' => 'Hola Laia, necesito ayuda con mi acceso a Agency OS.'],
        ],
        'LaiaTime' => [
            'laiatime.base_url' => ['label' => 'URL de la API', 'type' => 'text', 'default' => ''],
            'laiatime.api_key' => ['label' => 'API Key', 'type' => 'password', 'secret' => true, 'default' => ''],
        ],
        'Mora y suspensión' => [
            'dunning.auto_suspend' => ['label' => 'Suspender automáticamente por mora', 'type' => 'boolean', 'default' => '0'],
            'dunning.grace_days' => ['label' => 'Días de gracia antes de suspender', 'type' => 'number', 'default' => '10'],
            'dunning.reminder_before_days' => ['label' => 'Recordatorio previo (días antes)', 'type' => 'number', 'default' => '3'],
            'dunning.reminder_after_days' => ['label' => 'Recordatorio de mora (días después)', 'type' => 'number', 'default' => '1'],
        ],
        'Seguridad' => [
            'security.require_2fa_privileged' => ['label' => 'Exigir verificación en dos pasos al Superadministrador y a Dirección', 'type' => 'boolean', 'default' => '0'],
        ],
        'Finanzas' => [
            'finance.invoice_lead_days' => ['label' => 'Emitir cobros mensuales con (días de anticipación)', 'type' => 'number', 'default' => '10'],
            'finance.usd_ars_rate' => ['label' => 'Cotización USD → ARS (para consolidar el dashboard)', 'type' => 'number', 'default' => ''],
        ],
        'Datos bancarios de Laia' => [
            'bank.holder' => ['label' => 'Titular', 'type' => 'text', 'default' => 'Laia Estudio Creativo Digital'],
            'bank.cuit' => ['label' => 'CUIT', 'type' => 'text', 'default' => ''],
            'bank.name' => ['label' => 'Banco', 'type' => 'text', 'default' => ''],
            'bank.cbu' => ['label' => 'CBU / CVU', 'type' => 'text', 'default' => ''],
            'bank.alias' => ['label' => 'Alias', 'type' => 'text', 'default' => ''],
        ],
    ],
];
