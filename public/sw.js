// PipStream service worker: makes the app installable and quick to open.
// - Built assets (/spa/assets/*, hashed names) are cached forever after first use.
// - Pages are network-first; offline you get the last app shell, which shows cached data.
// - The API is never cached: balances and trades must always be live.
const VERSION = 'ps-v1'
const SHELL = '/app'

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll([SHELL, '/manifest.webmanifest', '/icons/icon-192.png'])).catch(() => {}))
  self.skipWaiting()
})

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))))
  self.clients.claim()
})

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url)
  if (e.request.method !== 'GET' || url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/webhooks/')) return

  if (url.pathname.startsWith('/spa/assets/') || url.pathname.startsWith('/icons/')) {
    e.respondWith(
      caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {
        const copy = res.clone()
        caches.open(VERSION).then((c) => c.put(e.request, copy))
        return res
      })),
    )
    return
  }

  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request).then((res) => {
        const copy = res.clone()
        caches.open(VERSION).then((c) => c.put(SHELL, copy))
        return res
      }).catch(() => caches.match(SHELL)),
    )
  }
})
