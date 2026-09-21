/// <reference types="vitest/config" />
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// El frontend llama a la ruta relativa /comparison y no conoce ninguna URL absoluta:
// en desarrollo la reenvía este proxy; en docker-compose, el servidor web (architecture.md §5.6).
const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: { '/comparison': backend },
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/unit/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,vue}'],
      exclude: ['src/main.ts'],
    },
  },
})
