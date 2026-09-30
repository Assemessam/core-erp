import { defineConfig } from '@playwright/test'

export default defineConfig({
  outputDir: process.env.CI
    ? '/tmp/coreerp-playwright-results'
    : 'test-results',
  testDir: './e2e',
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174',
    browserName: 'chromium',
    channel: process.env.CI ? undefined : 'chrome',
    trace: 'retain-on-failure',
  },
})
