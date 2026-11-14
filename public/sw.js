// Service worker mínimo: habilita la instalación como PWA.
// No cachea datos ni páginas (la app es autenticada); solo muestra un aviso sin conexión.
const CACHE = 'agencyos-offline-v1';
const OFFLINE_HTML = `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin conexión</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#162660;color:#fff;text-align:center"><div><h1 style="font-size:1.4rem">Sin conexión</h1><p>Revisá tu internet y volvé a intentar.</p><button onclick="location.reload()" style="padding:.6rem 1.2rem;border:0;border-radius:8px;font-size:1rem">Reintentar</button></div></body></html>`;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});
self.addEventListener('fetch', (e) => {
    if (e.request.mode !== 'navigate') return;
    e.respondWith(
        fetch(e.request).catch(() => new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })),
    );
});
