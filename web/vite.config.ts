/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Built into Laravel's public/spa; Laravel serves index.html for every app route.
// manifest.webmanifest, sw.js and icons live in Laravel's public/ root so the
// service worker can control the whole site.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: '/spa/',
  build: { outDir: '../public/spa', emptyOutDir: true, sourcemap: false },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/webhooks': 'http://127.0.0.1:8000',
      '/sw.js': 'http://127.0.0.1:8000',
      '/manifest.webmanifest': 'http://127.0.0.1:8000',
      '/icons': 'http://127.0.0.1:8000',
    },
  },
  test: { environment: 'jsdom', globals: true },
})
