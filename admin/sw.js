const CACHE_NAME = 'civetta-bakker-v3';
const PRECACHE_URLS = [
    '/admin/bakker/bakker-dashboard.php',
    '/admin/bakker/bereiden.php',
    '/admin/bakker/leveren.php',
    '/admin/bakker/bakcalculator.php',
    '/css/admin-bakker.css',
    '/js/bakker-calendar.js',
    '/img/icon-192.png',
    '/img/icon-512.png',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(event.request).catch(() =>
                new Response(JSON.stringify({ success: false, error: 'Offline' }), {
                    headers: { 'Content-Type': 'application/json' }
                })
            )
        );
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});

self.addEventListener('push', event => {
    let data = { title: 'Civetta Bakker', body: 'Nieuwe bestelling ontvangen!' };
    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {}
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/img/icon-192.png',
            badge: '/img/icon-192.png',
            data: { url: data.url || '/admin/bakker/bakker-dashboard.php' },
            vibrate: [200, 100, 200],
            tag: 'civetta-order',
            renotify: true
        })
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data?.url || '/admin/bakker/bakker-dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if (client.url.includes('/admin/') && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
