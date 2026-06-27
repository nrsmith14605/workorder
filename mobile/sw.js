var VERSION = '2';
var CACHE = 'wcsc-v' + VERSION;
var OFFLINE_URL = '/workorder/mobile/offline.html';

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE).then(function(cache) {
            return cache.addAll([
                OFFLINE_URL,
                '/workorder/mobile/icon.php?size=192',
                '/workorder/mobile/icon.php?size=180'
            ]);
        }).then(function() { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(k) { return k !== CACHE; })
                    .map(function(k) { return caches.delete(k); })
            );
        }).then(function() { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function(event) {
    if (event.request.method !== 'GET') return;

    var url = new URL(event.request.url);

    // Cache-first for fonts and icons — these rarely change
    if (url.hostname === 'fonts.googleapis.com' ||
        url.hostname === 'fonts.gstatic.com' ||
        url.pathname.includes('icon.php')) {
        event.respondWith(
            caches.match(event.request).then(function(cached) {
                if (cached) return cached;
                return fetch(event.request).then(function(response) {
                    if (response.ok) {
                        var clone = response.clone();
                        caches.open(CACHE).then(function(c) { c.put(event.request, clone); });
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Network-first for page navigations — fall back to offline page if network is unavailable
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(function() {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }
});

self.addEventListener('push', function(event) {
    if (!event.data) return;
    var data = event.data.json();
    event.waitUntil(
        self.registration.showNotification(data.title || 'Work Order Update', {
            body:              data.body || '',
            icon:              '/workorder/mobile/icon.php?size=192',
            data:              { url: data.url || '/workorder/mobile/dashboard.php' },
            requireInteraction: true
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : '/workorder/mobile/dashboard.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url === url && 'focus' in list[i]) return list[i].focus();
            }
            return clients.openWindow(url);
        })
    );
});
