<?php
require_once __DIR__ . '/config/app.php';
header('Content-Type: application/javascript');
$base = BASE_PATH;
$cssVersion = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();
?>
const CACHE_NAME = 'solecraft-v1';
const BASE = '<?= $base ?>';
const urlsToCache = [
  BASE + '/assets/css/style.css?v=<?= $cssVersion ?>',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  // Only intercept GET requests for our own static assets.
  // Everything else (PHP pages, /api/*, POST requests, cart/checkout/admin)
  // always goes straight to the network so data stays live.
  if (event.request.method !== 'GET') return;

  const isStaticAsset = urlsToCache.some(url =>
    event.request.url.indexOf(url.split('?')[0]) !== -1
  );
  if (!isStaticAsset) return;

  event.respondWith(
    caches.match(event.request).then(response => response || fetch(event.request))
  );
});
