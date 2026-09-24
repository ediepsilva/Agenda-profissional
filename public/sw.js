/* Service worker: guarda apenas arquivos estáticos. Páginas do painel e a API
   nunca são armazenadas (dados pessoais e horários precisam estar sempre atualizados). */
const CACHE = 'agenda-static-v1';
const scope = self.registration.scope;
const STATIC = ['assets/css/app.css', 'assets/js/app.js', 'assets/js/booking.js', 'assets/icons/icon.svg', 'assets/icons/icon-192.png', 'manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(STATIC.map((p) => new URL(p, scope).toString()))).catch(() => {}));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    if (url.pathname.includes('/assets/')) {
        // Estáticos: cache primeiro, atualizando em segundo plano.
        event.respondWith(caches.open(CACHE).then(async (cache) => {
            const cached = await cache.match(req, { ignoreSearch: true });
            const fresh = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => cached);
            return cached || fresh;
        }));
        return;
    }

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => new Response(
            '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
            '<title>Sem conexão</title><body style="font-family:system-ui;padding:2rem;text-align:center;color:#2d2226">' +
            '<h1>Você está sem conexão</h1><p>Verifique a internet e tente novamente.</p></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        )));
    }
});
