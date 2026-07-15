/**
 * YeveaCaptura service worker. Served through /capturar?file=sw so its scope
 * covers the app URL; all precache URLs are relative to that location.
 * Strategy: network-first for the app shell (fresh form token), cache-first
 * for static assets. Saving always requires a connection.
 */
var CACHE = 'yevea-captura-v1';
var SHELL = [
    'capturar',
    'capturar?file=manifest',
    'Plugins/YeveaStore/Assets/CSS/yeveacaptura.css',
    'Plugins/YeveaStore/Assets/JS/YeveaCaptura.js',
    'Plugins/YeveaStore/Assets/Images/yeveacaptura-icon.svg'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE)
            .then(function (cache) { return cache.addAll(SHELL); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.map(function (key) {
                    return key === CACHE ? null : caches.delete(key);
                }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET') {
        return;
    }

    var url = new URL(event.request.url);

    // App shell navigation: network first, cached shell as offline fallback
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(function (response) {
                    var copy = response.clone();
                    caches.open(CACHE).then(function (cache) {
                        cache.put(event.request, copy);
                    });
                    return response;
                })
                .catch(function () {
                    return caches.match(event.request, { ignoreSearch: true });
                })
        );
        return;
    }

    // Static plugin assets: cache first
    if (url.pathname.indexOf('/Plugins/YeveaStore/Assets/') !== -1) {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                return cached || fetch(event.request).then(function (response) {
                    var copy = response.clone();
                    caches.open(CACHE).then(function (cache) {
                        cache.put(event.request, copy);
                    });
                    return response;
                });
            })
        );
    }
});
