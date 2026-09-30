import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

const backend = process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8088'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: backend,
        changeOrigin: true,
      },
      '/sanctum': { target: backend, changeOrigin: true },
      '^/(login|logout|register|forgot-password|reset-password|email/verification-notification)$':
        {
          target: backend,
          changeOrigin: true,
          bypass: (request) =>
            request.method === 'GET' || request.method === 'HEAD'
              ? request.url
              : undefined,
        },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    clearMocks: true,
    restoreMocks: true,
  },
})
