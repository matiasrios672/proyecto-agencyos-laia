// Auditoría headless de rutas clave: errores 5xx, errores de consola/JS, requests fallidas,
// overflow horizontal, permisos por rol y capturas (claro/oscuro, desktop/móvil).
//   docker compose -f docker-compose.dev.yml --profile qa run --rm playwright
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://localhost:8080';
const PASSWORD = 'Laia-demo-2026';
const SHOTS = 'tests/e2e/screenshots';
mkdirSync(SHOTS, { recursive: true });

const users = {
    sysadmin: 'admin@laia.test',
    dueno: 'direccion@laia.test',
    empleado: 'camila@laia.test',
};

// Rutas por rol: [ruta, esperado]  (esperado: 200 o 403)
const matrix = {
    sysadmin: [['/contenido', 200], ['/mis-tareas', 200], ['/mensajes', 200], ['/admin/laiatime', 200], ['/clientes/1/contenido', 200], ['/clientes/1/tablero', 200], ['/clientes-eliminados', 200], ['/finanzas', 200], ['/cobros', 200], ['/liquidaciones', 200], ['/egresos', 200], ['/comunicados', 200], ['/presupuestos', 200], ['/presupuestos/1', 200], ['/catalogo', 200], ['/', 200], ['/admin/configuracion', 200], ['/admin/permisos', 200], ['/admin/sistema', 200], ['/clientes', 200], ['/clientes/create', 200], ['/equipo', 200], ['/perfil', 200]],
    dueno: [['/contenido', 200], ['/mis-tareas', 200], ['/mensajes', 200], ['/admin/laiatime', 200], ['/clientes/1/contenido', 200], ['/clientes/1/tablero', 200], ['/clientes-eliminados', 200], ['/finanzas', 200], ['/cobros', 200], ['/liquidaciones', 200], ['/egresos', 200], ['/comunicados', 200], ['/comunicados/1', 200], ['/presupuestos', 200], ['/presupuestos/create', 200], ['/presupuestos/1', 200], ['/presupuestos/1/edit', 200], ['/catalogo', 200], ['/', 200], ['/admin/configuracion', 403], ['/admin/permisos', 403], ['/admin/sistema', 403], ['/clientes', 200], ['/clientes/create', 200], ['/clientes/1', 200], ['/clientes/1/edit', 200], ['/equipo', 200], ['/perfil', 200]],
    empleado: [['/contenido', 200], ['/mis-tareas', 200], ['/mensajes', 200], ['/admin/laiatime', 403], ['/clientes-eliminados', 403], ['/clientes/1/contenido', 200], ['/clientes/1/tablero', 200], ['/finanzas', 403], ['/cobros', 403], ['/liquidaciones', 200], ['/egresos', 403], ['/comunicados', 403], ['/presupuestos', 403], ['/presupuestos/create', 403], ['/catalogo', 403], ['/', 200], ['/admin/configuracion', 403], ['/admin/permisos', 403], ['/admin/sistema', 403], ['/clientes', 200], ['/clientes/create', 403], ['/equipo', 403], ['/perfil', 200]],
};

const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];

const problems = [];
const report = (role, route, msg) => problems.push(`[${role}] ${route} → ${msg}`);
let checks = 0;

// El navegador entra como http://localhost:8080 (igual que APP_URL) para que las URLs firmadas validen;
// la regla de resolución lo redirige internamente al contenedor nginx.
const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP localhost:8080 nginx:80, MAP localhost:8081 reverb:8081'] });

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await Promise.all([page.waitForURL(`${BASE}/`, { timeout: 15000 }), page.click('button[type=submit]')]);
}

// Login: página de invitados + credenciales erróneas
{
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message));
    page.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
    const res = await page.goto(`${BASE}/login`);
    checks++;
    if (res.status() !== 200) report('guest', '/login', `status ${res.status()}`);
    await page.fill('#email', 'nadie@laia.test');
    await page.fill('#password', 'incorrecta-123');
    await page.click('button[type=submit]');
    await page.waitForSelector('[role=alert]', { timeout: 8000 }).catch(() => report('guest', '/login', 'no muestra error de credenciales'));
    await page.screenshot({ path: `${SHOTS}/guest-login.png` });
    // 401/422 del login erróneo son esperables; solo contamos errores JS reales
    errs.filter((e) => !/status of (401|419|422)/.test(e)).forEach((e) => report('guest', '/login', `consola: ${e}`));
    await ctx.close();
}

for (const [role, email] of Object.entries(users)) {
    for (const vp of viewports) {
        for (const scheme of ['light', 'dark']) {
            const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: scheme });
            const page = await ctx.newPage();
            const errs = [];
            page.on('pageerror', (e) => errs.push(`pageerror: ${e.message}`));
            page.on('console', (m) => m.type() === 'error' && errs.push(`console.error: ${m.text()}`));
            // Se ignoran los abortos por navegar con peticiones en vuelo y las fuentes de CDN externa (opcionales).
            page.on('requestfailed', (r) => {
                if (/ERR_ABORTED/.test(r.failure()?.errorText ?? '') || /cdn\.jsdelivr\.net|fonts\.bunny\.net/.test(r.url())) return;
                errs.push(`request fallida: ${r.url()} (${r.failure()?.errorText})`);
            });
            page.on('response', (r) => r.status() >= 500 && errs.push(`HTTP ${r.status()}: ${r.url()}`));

            await login(page, email);

            for (const [route, expected] of matrix[role]) {
                checks++;
                errs.length = 0;
                const res = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });
                const status = res.status();

                if (status !== expected) report(role, route, `esperaba ${expected} y devolvió ${status} (${vp.name}/${scheme})`);
                if (status === 200) {
                    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
                    if (overflow) report(role, route, `overflow horizontal (${vp.name}/${scheme})`);
                    // El visor de logs de /admin/sistema muestra errores históricos a propósito: se excluye del chequeo.
                    const bodyText = await page.evaluate(() => {
                        const clone = document.body.cloneNode(true);
                        clone.querySelectorAll('pre').forEach((n) => n.remove());
                        return clone.innerText;
                    });
                    if (/Whoops|Server Error|Undefined variable|SQLSTATE/i.test(bodyText)) report(role, route, 'texto de error visible');
                    const isDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
                    if (isDark !== (scheme === 'dark')) report(role, route, `modo ${scheme} no aplicado`);
                }
                errs.filter((e) => !(status === 403 && /403/.test(e))).forEach((e) => report(role, route, `${e} (${vp.name}/${scheme})`));

                const name = route === '/' ? 'inicio' : route.replaceAll('/', '_').slice(1);
                await page.screenshot({ path: `${SHOTS}/${role}-${name}-${vp.name}-${scheme}.png`, fullPage: true });
            }
            await ctx.close();
        }
    }
}

// ───────────────────────── Flujos funcionales (Fase 2) ─────────────────────────
const MAIL_API = process.env.MAIL_API ?? 'http://mailpit:8025';
const flow = (name, msg) => report('flujo', name, msg);
const must = (cond, name, msg) => { checks++; if (!cond) flow(name, msg); return cond; };

async function newSession(email, opts = {}) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...opts });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(`pageerror: ${e.message}`));
    page.on('console', (m) => m.type() === 'error' && !/status of 403/.test(m.text()) && errs.push(`console.error: ${m.text()}`));
    if (email) await login(page, email);
    return { ctx, page, errs };
}

// 1) Empleado: solo ve sus clientes y no puede abrir uno ajeno
{
    const dueno = await newSession(users.dueno);
    await dueno.page.goto(`${BASE}/clientes`, { waitUntil: 'networkidle' });
    const allLinks = await dueno.page.$$eval('table a[href^="/clientes/"]', (as) => as.map((a) => ({ href: a.getAttribute('href'), text: a.textContent.trim() })));
    must(allLinks.length >= 8, 'listado-dueno', `dirección debería ver 8+ clientes y ve ${allLinks.length}`);
    const sinEquipo = allLinks.find((l) => l.text.includes('Don Raúl'));
    await dueno.ctx.close();

    const cam = await newSession(users.empleado);
    await cam.page.goto(`${BASE}/clientes`, { waitUntil: 'networkidle' });
    const mine = await cam.page.$$eval('table a[href^="/clientes/"]', (as) => as.map((a) => a.textContent.trim()));
    must(mine.length > 0 && mine.length < allLinks.length, 'aislamiento', `empleado ve ${mine.length} de ${allLinks.length} clientes`);
    must(!mine.some((t) => t.includes('Don Raúl')), 'aislamiento', 'empleado ve un cliente que no tiene asignado');
    if (sinEquipo) {
        const res = await cam.page.goto(`${BASE}${sinEquipo.href}`);
        must(res.status() === 403, 'aislamiento', `cliente ajeno devolvió ${res.status()} en vez de 403`);
    }

    // 2) Bóveda: revelar, ocultar, copiar
    await cam.ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
    await cam.page.goto(`${BASE}/clientes/1`, { waitUntil: 'networkidle' }).catch(() => {});
    const href = (await cam.page.$$eval('table a[href^="/clientes/"]', () => [])).length; void href;
    await cam.page.goto(`${BASE}/clientes`, { waitUntil: 'networkidle' });
    const firstHref = await cam.page.$eval('table a[href^="/clientes/"]', (a) => a.getAttribute('href'));
    await cam.page.goto(`${BASE}${firstHref}`, { waitUntil: 'networkidle' });
    await cam.page.getByRole('tab', { name: /Bóveda/ }).click();
    const masked = await cam.page.locator('code').first().innerText();
    must(/^•+$/.test(masked.trim()), 'boveda', `el secreto no arranca enmascarado: "${masked}"`);
    await cam.page.getByRole('button', { name: /Revelar/ }).first().click();
    await cam.page.waitForFunction(() => /Demo-|\d{4} \d{4}/.test(document.querySelector('code')?.textContent ?? ''), null, { timeout: 8000 })
        .catch(() => flow('boveda', 'no se reveló el secreto'));
    await cam.page.getByRole('button', { name: /Ocultar/ }).first().click();
    must(/^•+$/.test((await cam.page.locator('code').first().innerText()).trim()), 'boveda', 'Ocultar no vuelve a enmascarar');
    await cam.page.getByRole('button', { name: /Copiar/ }).first().click();
    // Chromium headless no siempre permite leer el portapapeles: se verifica el aviso de éxito de la app
    // (que solo aparece si writeText() resolvió) y, si es legible, también el contenido.
    must(await cam.page.getByText(/Copiado al portapapeles/).first().waitFor({ timeout: 5000 }).then(() => true).catch(() => false),
        'boveda', 'no apareció el aviso "Copiado al portapapeles" (¿falló writeText?)');
    const clip = await cam.page.evaluate(() => navigator.clipboard.readText()).catch(() => null);
    if (clip !== null && clip !== '') must(/Demo-|\d{4} \d{4}/.test(clip), 'boveda', `el portapapeles no contiene la credencial ("${clip}")`);
    must(await cam.page.getByRole('button', { name: /Nueva credencial/ }).count() === 0, 'boveda', 'empleado ve "Nueva credencial" sin vault.manage');
    await cam.page.screenshot({ path: `${SHOTS}/flujo-boveda-empleado.png`, fullPage: true });
    cam.errs.forEach((e) => flow('boveda', e));
    await cam.ctx.close();
}

// 3) Auditoría visible para el sysadmin
{
    const adm = await newSession(users.sysadmin);
    await adm.page.goto(`${BASE}/admin/sistema`, { waitUntil: 'networkidle' });
    const txt = await adm.page.evaluate(() => document.body.innerText);
    must(/Credencial revelada/.test(txt) && /Credencial copiada/.test(txt), 'auditoria', 'las lecturas/copias de la bóveda no figuran en la actividad');
    await adm.ctx.close();
}

// 4) Dirección: crear cliente, invitar empleado (correo real vía Mailpit), aceptar invitación, dar de baja
{
    const duena = await newSession(users.dueno);
    const stamp = Date.now();

    await duena.page.goto(`${BASE}/clientes/create`, { waitUntil: 'networkidle' });
    await duena.page.fill('#business_name', `Cliente Auditoría ${stamp}`);
    await duena.page.fill('#tax_id', '30714589632');
    await duena.page.click('button[type=submit]');
    await duena.page.waitForURL(/\/clientes\/\d+$/, { timeout: 10000 }).catch(() => flow('cliente', 'no redirigió a la ficha tras crear'));
    must((await duena.page.locator('h2').first().innerText()).includes(`Cliente Auditoría ${stamp}`), 'cliente', 'la ficha no muestra el cliente creado');

    await duena.page.goto(`${BASE}/clientes/create`, { waitUntil: 'networkidle' });
    await duena.page.fill('#business_name', 'CUIT inválido');
    await duena.page.fill('#tax_id', '30-71458963-9');
    await duena.page.click('button[type=submit]');
    await duena.page.waitForSelector('[role=alert]', { timeout: 6000 }).catch(() => flow('cliente', 'no valida el CUIT inválido'));

    const email = `auditoria.${stamp}@laia.test`;
    await duena.page.goto(`${BASE}/equipo`, { waitUntil: 'networkidle' });
    await duena.page.getByRole('button', { name: /Invitar empleado/ }).click();
    await duena.page.fill('#e-name', 'Persona Auditoría');
    await duena.page.fill('#e-email', email);
    await duena.page.getByRole('button', { name: /Enviar invitación/ }).click();
    await duena.page.getByText(/Invitación enviada/).waitFor({ timeout: 8000 }).catch(() => flow('equipo', 'no apareció el aviso de invitación enviada'));

    // El correo lo envía el worker: esperar a que llegue a Mailpit
    let link = null;
    for (let i = 0; i < 20 && !link; i++) {
        const list = await (await fetch(`${MAIL_API}/api/v1/messages`)).json();
        const msg = list.messages?.find((m) => m.To?.some((t) => t.Address === email));
        if (msg) {
            const full = await (await fetch(`${MAIL_API}/api/v1/message/${msg.ID}`)).json();
            const m = (full.HTML + full.Text).match(/https?:\/\/[^\s"'<>]+\/invitacion\/\d+\?[^\s"'<>]+/);
            link = m ? m[0].replaceAll('&amp;', '&') : null;
        }
        if (!link) await new Promise((r) => setTimeout(r, 1000));
    }
    if (must(link, 'invitacion', 'el correo de invitación no llegó a Mailpit (¿está corriendo el worker?)')) {
        const url = link.replace(/^https?:\/\/[^/]+/, BASE);
        const guest = await newSession(null);
        await guest.page.goto(url, { waitUntil: 'networkidle' });
        await guest.page.fill('#password', 'Auditoria-2026-ok');
        await guest.page.fill('#password_confirmation', 'Auditoria-2026-ok');
        await Promise.all([guest.page.waitForURL(`${BASE}/`, { timeout: 10000 }), guest.page.click('button[type=submit]')]);
        must(await guest.page.getByText(/(Buenos días|Buenas tardes|Buenas noches), Persona/).count() > 0, 'invitacion', 'tras aceptar la invitación no inicia sesión');
        guest.errs.forEach((e) => flow('invitacion', e));
        await guest.ctx.close();

        // Baja: el empleado pierde el acceso
        await duena.page.goto(`${BASE}/equipo`, { waitUntil: 'networkidle' });
        await duena.page.getByRole('button', { name: 'Dar de baja a Persona Auditoría' }).click();
        await duena.page.getByRole('button', { name: 'Dar de baja', exact: true }).click();
        await duena.page.getByText('Dado de baja').first().waitFor({ timeout: 8000 }).catch(() => flow('equipo', 'no muestra el estado "Dado de baja"'));
        const again = await newSession(null);
        await again.page.goto(`${BASE}/login`);
        await again.page.fill('#email', email);
        await again.page.fill('#password', 'Auditoria-2026-ok');
        await again.page.click('button[type=submit]');
        await again.page.waitForSelector('[role=alert]', { timeout: 6000 }).catch(() => flow('equipo', 'un empleado dado de baja pudo iniciar sesión'));
        must(!again.page.url().endsWith('/') || again.page.url().includes('login'), 'equipo', 'empleado dado de baja entró al sistema');
        await again.ctx.close();
    }
    await duena.page.goto(`${BASE}/equipo`, { waitUntil: 'networkidle' });
    await duena.page.screenshot({ path: `${SHOTS}/flujo-equipo-dueno.png`, fullPage: true });
    duena.errs.forEach((e) => flow('dueno', e));
    await duena.ctx.close();
}

// 6) Ventas: armar presupuesto → PDF → email con adjunto → aprobar y convertir → vista del empleado → catálogo
{
    const stamp = Date.now();
    const name = `Prospecto Auditoría ${stamp}`;
    const duena = await newSession(users.dueno);
    const p = duena.page;

    await p.goto(`${BASE}/presupuestos/create`, { waitUntil: 'networkidle' });
    await p.getByRole('tab', { name: 'Prospecto nuevo' }).click();
    await p.fill('#prospect_name', name);
    await p.fill('#prospect_email', `ventas.${stamp}@laia.test`);
    await p.fill('#prospect_phone', '+54 9 11 5555-0199');
    await p.getByLabel('Agregar desde el catálogo').selectOption({ index: 1 });
    await p.getByRole('button', { name: 'Ítem libre' }).click();
    await p.fill('#d-1', 'Gestión de reportes');
    await p.fill('#q-1', '2');
    await p.fill('#p-1', '15000');
    await p.fill('#discount', '10');
    const total = await p.locator('dd[aria-live=polite]').innerText();
    must(/\$\s?[\d.]+,\d{2}/.test(total), 'presupuesto', `el total en vivo no se calcula: "${total}"`);
    await p.screenshot({ path: `${SHOTS}/flujo-presupuesto-armador.png`, fullPage: true });
    await p.getByRole('button', { name: 'Crear presupuesto' }).click();
    await p.waitForURL(/\/presupuestos\/\d+$/, { timeout: 10000 }).catch(() => flow('presupuesto', 'no redirigió a la ficha tras crear'));
    const quoteId = p.url().split('/').pop();
    const number = (await p.locator('h2').first().innerText()).trim();
    must(/^P-\d{4}-\d{4}$/.test(number), 'presupuesto', `número inválido: "${number}"`);
    await p.screenshot({ path: `${SHOTS}/flujo-presupuesto-ficha.png`, fullPage: true });

    // PDF autenticado
    // (se descarga desde la página para usar la misma sesión y resolución de red que el navegador)
    const pdf = await p.evaluate(async (url) => {
        const r = await fetch(url);
        const bytes = new Uint8Array(await r.arrayBuffer());
        let bin = '';
        bytes.forEach((b) => (bin += String.fromCharCode(b)));
        return { status: r.status, type: r.headers.get('content-type') ?? '', b64: btoa(bin) };
    }, `/presupuestos/${quoteId}/pdf`);
    const body = Buffer.from(pdf.b64, 'base64');
    must(pdf.status === 200 && /application\/pdf/.test(pdf.type) && body.subarray(0, 4).toString() === '%PDF',
        'pdf', `PDF inválido (status ${pdf.status})`);
    (await import('node:fs')).writeFileSync(`${SHOTS}/presupuesto.pdf`, body);

    // Enlace público firmado de WhatsApp: abre sin sesión y un enlace adulterado no
    const wa = await p.locator('a[href^="https://wa.me/"]').first().getAttribute('href');
    const signed = decodeURIComponent(wa.split('text=')[1]).match(/https?:\/\/\S+/)?.[0];
    const probe = await newSession(null);
    await probe.page.goto(`${BASE}/login`);
    const okSigned = await probe.page.evaluate(async (u) => { const r = await fetch(u); return [r.status, r.headers.get('content-type')]; }, signed);
    must(okSigned[0] === 200 && /pdf/.test(okSigned[1]), 'pdf-publico', `el enlace firmado no abre sin sesión: ${okSigned}`);
    const tampered = await probe.page.evaluate(async (u) => (await fetch(u.replace(/signature=[a-f0-9]+/, 'signature=0000'))).status, signed);
    must(tampered === 403, 'pdf-publico', `un enlace adulterado devolvió ${tampered}`);
    await probe.ctx.close();

    // Email con PDF adjunto (lo envía el worker)
    await p.getByRole('button', { name: 'Email', exact: true }).click();
    await p.getByRole('dialog').getByRole('button', { name: 'Enviar' }).click();
    await p.getByText('Presupuesto enviado por email').first().waitFor({ timeout: 8000 }).catch(() => flow('presupuesto', 'no confirmó el envío por email'));
    let attachments = null;
    for (let i = 0; i < 20 && attachments === null; i++) {
        const list = await (await fetch(`${MAIL_API}/api/v1/messages`)).json();
        const msg = list.messages?.find((m) => m.Subject?.includes(number));
        if (msg) attachments = (await (await fetch(`${MAIL_API}/api/v1/message/${msg.ID}`)).json()).Attachments ?? [];
        else await new Promise((r) => setTimeout(r, 1000));
    }
    must(attachments !== null && attachments.some((a) => /\.pdf$/i.test(a.FileName)), 'email', 'el correo del presupuesto no llegó con el PDF adjunto');

    // Aprobar y convertir con empleado y reparto
    await p.getByRole('button', { name: 'Aprobar y convertir' }).click();
    const dialog = p.getByRole('dialog');
    const camilaValue = await dialog.locator('#u-0 option', { hasText: 'Camila' }).getAttribute('value');
    await dialog.locator('#u-0').selectOption(camilaValue);
    await dialog.locator('#pc-0').fill('40');
    await p.waitForTimeout(400);
    await p.screenshot({ path: `${SHOTS}/flujo-convertir.png` });
    await dialog.getByRole('button', { name: 'Aprobar y convertir' }).click();
    await p.waitForURL(/\/clientes\/\d+$/, { timeout: 10000 }).catch(() => flow('conversion', 'no redirigió al nuevo cliente'));
    const clientUrl = p.url();
    must((await p.locator('h2').first().innerText()).includes(name), 'conversion', 'el nuevo cliente no tiene el nombre del prospecto');
    await p.getByRole('tab', { name: /Servicios \(2\)/ }).click();
    const servicesText = await p.locator('main').innerText();
    must(servicesText.includes('Camila Sosa') && /40\s?%/.test(servicesText), 'conversion', 'los servicios no muestran empleado y reparto');
    await p.waitForTimeout(300);
    await p.screenshot({ path: `${SHOTS}/flujo-cliente-servicios.png`, fullPage: true });

    // No se puede volver a aprobar
    await p.goto(`${BASE}/presupuestos/${quoteId}`, { waitUntil: 'networkidle' });
    must(await p.getByRole('button', { name: 'Aprobar y convertir' }).count() === 0, 'conversion', 'sigue ofreciendo aprobar un presupuesto ya convertido');
    must(await p.getByText(/Es cliente activo/).count() === 1, 'conversion', 'la ficha no muestra el cliente convertido');

    // El empleado asignado ve los servicios, pero no los montos ni el reparto
    const cam = await newSession(users.empleado);
    await cam.page.goto(clientUrl, { waitUntil: 'networkidle' });
    await cam.page.getByRole('tab', { name: /Servicios/ }).click();
    const camText = await cam.page.locator('main').innerText();
    must(camText.includes('Community Management') || camText.includes('Gestión de reportes'), 'servicios-empleado', 'el empleado no ve los servicios de su cliente');
    must(!/\$\s?\d/.test(camText) && !/%/.test(camText), 'servicios-empleado', 'el empleado ve montos o porcentajes de reparto');
    must(await cam.page.getByRole('link', { name: 'Presupuestos' }).count() === 0, 'servicios-empleado', 'el empleado ve “Presupuestos” en el menú');
    cam.errs.forEach((e) => flow('servicios-empleado', e));
    await cam.ctx.close();

    // Catálogo
    await p.goto(`${BASE}/catalogo`, { waitUntil: 'networkidle' });
    await p.getByRole('button', { name: 'Nuevo servicio' }).click();
    await p.fill('#s-name', `Servicio Auditoría ${stamp}`);
    await p.fill('#s-price', '12345.67');
    await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
    await p.getByText(`Servicio Auditoría ${stamp}`).first().waitFor({ timeout: 8000 }).catch(() => flow('catalogo', 'el servicio nuevo no aparece en la lista'));
    must((await p.locator('main').innerText()).includes('12.345,67'), 'catalogo', 'el precio no se muestra con formato argentino');
    await p.screenshot({ path: `${SHOTS}/flujo-catalogo.png`, fullPage: true });

    duena.errs.forEach((e) => flow('ventas', e));
    await duena.ctx.close();
}

// 7) Finanzas: cobro → liquidación automática → vista del empleado → liquidar → dashboard → egresos → comunicados
{
    const stamp = Date.now();
    const duena = await newSession(users.dueno);
    const p = duena.page;

    // Registrar el pago de un cobro de un servicio con empleado asignado (cliente Sonríe → Camila, 20 %)
    await p.goto(`${BASE}/cobros`, { waitUntil: 'networkidle' });
    await p.getByLabel('Buscar cobros').fill('Sonrí');
    await p.waitForTimeout(1000);
    await p.screenshot({ path: `${SHOTS}/flujo-cobros.png`, fullPage: true });
    await p.getByRole('button', { name: 'Registrar pago' }).first().click();
    await p.waitForTimeout(300);
    await p.screenshot({ path: `${SHOTS}/flujo-registrar-pago.png` });
    await p.getByRole('dialog').getByRole('button', { name: 'Registrar pago' }).click();
    must(await p.getByText(/Se generó una liquidación de \$\s?[\d.]+,\d{2} para Camila Sosa/).first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false),
        'finanzas', 'al registrar el pago no avisó la liquidación automática para Camila');
    must(await p.locator('main li').filter({ hasText: /Pagada/ }).first().isVisible().catch(() => false), 'finanzas', 'el cobro no figura como pagado');

    // Cobro manual
    await p.getByRole('button', { name: 'Cobro manual' }).click();
    const clientValue = await p.locator('#m-client option').nth(1).getAttribute('value');
    await p.locator('#m-client').selectOption(clientValue);
    await p.fill('#m-desc', `Sesión extra ${stamp}`);
    await p.fill('#m-amount', '45000');
    await p.getByRole('dialog').getByRole('button', { name: 'Crear cobro' }).click();
    must(await p.getByText(/Cobro C-\d{4}-\d{4} creado/).first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false), 'finanzas', 'no se creó el cobro manual');

    // El empleado ve SU liquidación (y solo las suyas), con aviso en la campana
    const cam = await newSession(users.empleado);
    await cam.page.goto(`${BASE}/liquidaciones`, { waitUntil: 'networkidle' });
    const camText = await cam.page.locator('main').innerText();
    must(/Sonrí/.test(camText) && /Pendiente/.test(camText), 'liquidaciones', 'Camila no ve su liquidación pendiente del cobro de Sonríe');
    must(await cam.page.getByLabel('Filtrar por empleado').count() === 0, 'liquidaciones', 'el empleado ve el filtro por empleado (datos de otros)');
    must(await cam.page.getByRole('checkbox').count() === 0, 'liquidaciones', 'el empleado puede seleccionar liquidaciones para liquidar');
    must(await cam.page.getByRole('button', { name: /^Notificaciones, \d+ sin leer/ }).count() === 1, 'liquidaciones', 'Camila no recibió el aviso de la liquidación');
    await cam.page.screenshot({ path: `${SHOTS}/flujo-mis-liquidaciones.png`, fullPage: true });
    cam.errs.forEach((e) => flow('liquidaciones', e));
    await cam.ctx.close();

    // Dirección liquida
    await p.goto(`${BASE}/liquidaciones`, { waitUntil: 'networkidle' });
    await p.getByRole('checkbox', { name: /Seleccionar liquidación de Camila Sosa/ }).first().check();
    await p.getByRole('button', { name: /Marcar 1 como liquidada/ }).click();
    await p.getByRole('dialog').fill('#s-note', 'Transferencia de auditoría').catch(() => p.fill('#s-note', 'Transferencia de auditoría'));
    await p.getByRole('dialog').getByRole('button', { name: 'Confirmar' }).click();
    must(await p.getByText('Liquidación marcada como pagada.').first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false), 'liquidaciones', 'no confirmó la liquidación');

    // Dashboard: cifra principal, gráfico accesible, tabla, moneda
    await p.goto(`${BASE}/finanzas`, { waitUntil: 'networkidle' });
    let dash = await p.locator('main').innerText();
    must(/Ganancia neta real/.test(dash) && /MRR/.test(dash) && /En mora/.test(dash), 'dashboard', 'faltan la ganancia neta real o los indicadores');
    const columns = p.getByRole('button', { name: /cobrado .*liquidaciones .*egresos .*ganancia neta/i });
    must(await columns.count() === 6, 'dashboard', `el gráfico debería tener 6 meses y tiene ${await columns.count()}`);
    await columns.last().hover();
    must(await p.getByText('Liquidaciones al equipo').count() >= 2, 'dashboard', 'el tooltip del gráfico no aparece al pasar el mouse');
    await p.waitForTimeout(300);
    await p.screenshot({ path: `${SHOTS}/flujo-finanzas-dashboard.png`, fullPage: true });
    await columns.first().focus();
    must(await p.getByText('Cobrado', { exact: true }).count() >= 1, 'dashboard', 'el tooltip no aparece con el foco de teclado');
    await p.getByRole('button', { name: 'Ver como tabla' }).click();
    must(await p.locator('table tbody tr').count() === 6, 'dashboard', 'la vista de tabla no tiene los 6 meses');
    await p.getByRole('button', { name: 'Ver gráfico' }).click();
    await p.getByRole('tab', { name: /Dólares/ }).click();
    dash = await p.locator('main').innerText();
    must(/US\$/.test(dash), 'dashboard', 'al cambiar a dólares no se muestran importes en USD');
    await p.screenshot({ path: `${SHOTS}/flujo-finanzas-usd.png`, fullPage: true });

    // Egresos
    await p.goto(`${BASE}/egresos`, { waitUntil: 'networkidle' });
    await p.getByRole('button', { name: 'Nuevo egreso' }).click();
    await p.fill('#x-name', `Suscripción ${stamp}`);
    await p.fill('#x-amount', '10');
    await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
    must(await p.getByText('Egreso agregado.').first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false), 'egresos', 'no se agregó el egreso');
    must((await p.locator('main').innerText()).includes(`Suscripción ${stamp}`), 'egresos', 'el egreso nuevo no figura en la lista');
    await p.screenshot({ path: `${SHOTS}/flujo-egresos.png`, fullPage: true });

    // Comunicados: enviar a todos y verificar el correo real
    await p.goto(`${BASE}/comunicados`, { waitUntil: 'networkidle' });
    await p.getByRole('button', { name: 'Nuevo comunicado' }).click();
    const subject = `Aviso de auditoría ${stamp}`;
    await p.fill('#a-subject', subject);
    await p.fill('#a-body', 'Este es un aviso de prueba.\nSegunda línea.');
    await p.waitForTimeout(300);
    await p.screenshot({ path: `${SHOTS}/flujo-comunicado.png` });
    await p.getByRole('dialog').getByRole('button', { name: 'Enviar ahora' }).click();
    must(await p.getByText(/Comunicado enviado a \d+ clientes?/).first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false), 'comunicados', 'no confirmó el envío');
    let got = 0;
    for (let i = 0; i < 25 && got === 0; i++) {
        const list = await (await fetch(`${MAIL_API}/api/v1/messages?limit=200`)).json();
        got = list.messages?.filter((m) => m.Subject === subject).length ?? 0;
        if (!got) await new Promise((r) => setTimeout(r, 1000));
    }
    must(got >= 1, 'comunicados', 'ningún cliente recibió el correo del comunicado (¿está corriendo el worker?)');
    await p.getByText(subject).first().click();
    await p.waitForURL(/\/comunicados\/\d+$/, { timeout: 8000 }).catch(() => flow('comunicados', 'no abre el detalle'));
    must(await p.getByText(/Destinatarios \(\d+\)/).count() === 1, 'comunicados', 'el detalle no lista los destinatarios');

    duena.errs.forEach((e) => flow('finanzas', e));
    await duena.ctx.close();
}

// 5) Barra superior: aviso emergente en vivo, campana, menú de perfil, tema y apagado
{
    const duena = await newSession(users.dueno);
    await duena.page.goto(`${BASE}/clientes`, { waitUntil: 'networkidle' });
    const raul = await duena.page.$$eval('table a[href^="/clientes/"]', (as) => as.find((a) => a.textContent.includes('Don Raúl'))?.getAttribute('href'));

    const cam = await newSession(users.empleado);
    await cam.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    must(await cam.page.getByRole('button', { name: /^Notificaciones/ }).count() === 1, 'topbar', 'falta la campana de notificaciones');
    must(await cam.page.getByRole('button', { name: 'Mensajes' }).count() === 1, 'topbar', 'falta el botón de mensajes');
    must(await cam.page.getByRole('button', { name: 'Menú de perfil' }).count() === 1, 'topbar', 'falta el menú de perfil');
    must(await cam.page.getByRole('button', { name: 'Cerrar sesión' }).count() === 1, 'topbar', 'falta el botón de apagado');

    // Dirección asigna a Camila mientras ella tiene la app abierta
    await duena.page.goto(`${BASE}${raul}`, { waitUntil: 'networkidle' });
    await duena.page.getByRole('tab', { name: /Equipo/ }).click();
    await duena.page.getByLabel(/Camila Sosa/).check();
    await duena.page.getByRole('button', { name: 'Guardar equipo' }).click();
    await duena.page.getByText('Equipo actualizado').first().waitFor({ timeout: 8000 }).catch(() => flow('topbar', 'no se guardó el equipo'));

    // El aviso emergente aparece sin recargar (la app consulta al volver el foco)
    await cam.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    must(await cam.page.locator('[data-sonner-toast]').filter({ hasText: 'Te asignaron un cliente' }).first().waitFor({ timeout: 8000 }).then(() => true).catch(() => false),
        'topbar', 'no apareció el aviso emergente de la nueva asignación');
    await cam.page.waitForTimeout(700); // que termine la animación de entrada antes de capturar
    await cam.page.screenshot({ path: `${SHOTS}/flujo-aviso-emergente.png` });

    // Camila puede tener avisos previos (p. ej. por una conversión anterior): se valida el contador de forma relativa.
    const bell = cam.page.getByRole('button', { name: /^Notificaciones, \d+ sin leer/ });
    must(await bell.count() === 1, 'topbar', 'la campana no muestra avisos sin leer');
    const unreadBefore = Number((await bell.getAttribute('aria-label')).match(/(\d+) sin leer/)[1]);
    await bell.click();
    await cam.page.getByText('Te asignó a', { exact: false }).first().waitFor({ timeout: 5000 }).catch(() => flow('topbar', 'el panel no lista la notificación'));
    await cam.page.waitForTimeout(500);
    await cam.page.screenshot({ path: `${SHOTS}/flujo-campana.png` });
    await cam.page.getByRole('button', { name: /Te asignaron un cliente/ }).first().click();
    await cam.page.waitForURL(/\/clientes\/\d+$/, { timeout: 8000 }).catch(() => flow('topbar', 'la notificación no lleva al cliente'));
    const expectedLabel = unreadBefore - 1 > 0 ? new RegExp(`^Notificaciones, ${unreadBefore - 1} sin leer`) : /^Notificaciones$/;
    must(await cam.page.getByRole('button', { name: expectedLabel }).waitFor({ timeout: 5000 }).then(() => true).catch(() => false),
        'topbar', 'la notificación no se marcó como leída (el contador no bajó)');

    await cam.page.getByRole('button', { name: 'Mensajes' }).click();
    must(await cam.page.getByRole('dialog').getByText('Mensajes', { exact: true }).count() === 1, 'topbar', 'el panel de mensajes no abre');
    await cam.page.keyboard.press('Escape');

    await cam.page.getByRole('button', { name: 'Menú de perfil' }).click();
    await cam.page.waitForTimeout(500);
    await cam.page.screenshot({ path: `${SHOTS}/flujo-menu-perfil.png` });
    await cam.page.getByRole('menuitemradio', { name: 'Oscuro' }).click();
    must(await cam.page.evaluate(() => document.documentElement.classList.contains('dark')), 'topbar', 'el tema oscuro no se aplicó desde el menú');
    await cam.page.getByRole('button', { name: 'Menú de perfil' }).click();
    await cam.page.getByRole('menuitemradio', { name: 'Claro' }).click();
    must(!(await cam.page.evaluate(() => document.documentElement.classList.contains('dark'))), 'topbar', 'el tema claro no se aplicó desde el menú');

    await cam.page.getByRole('button', { name: 'Menú de perfil' }).click();
    await cam.page.getByRole('menuitem', { name: 'Mi perfil' }).click();
    await cam.page.waitForURL(`${BASE}/perfil`, { timeout: 8000 }).catch(() => flow('topbar', 'Mi perfil no navega'));

    await Promise.all([cam.page.waitForURL(`${BASE}/login`, { timeout: 8000 }).catch(() => flow('topbar', 'el botón de apagado no cierra la sesión')),
        cam.page.getByRole('button', { name: 'Cerrar sesión' }).click()]);
    must((await cam.page.goto(`${BASE}/clientes`)).url().includes('/login'), 'topbar', 'tras apagar, /clientes sigue accesible');

    cam.errs.forEach((e) => flow('topbar', e));
    await cam.ctx.close();
    await duena.ctx.close();
}

await browser.close();

console.log(`\nAuditoría: ${checks} rutas/vistas verificadas × viewports/temas`);
if (problems.length) {
    console.error(`\n✗ ${problems.length} problema(s):\n` + [...new Set(problems)].map((p) => ` - ${p}`).join('\n'));
    process.exit(1);
}
console.log('✓ Sin errores 5xx, de consola, overflow ni fallos de permisos.');
