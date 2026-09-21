import { defineConfig, devices } from '@playwright/test'

// E2E contra el stack de docker-compose.test.yml (make test-e2e), con el Provider C forzado a HTTP 500
// para que el resultado sea determinista y nunca dependa del azar (testing.md §8.2).
export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8080',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
})
