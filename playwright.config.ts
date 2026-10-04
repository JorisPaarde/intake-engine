import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL
  ?? process.env.APP_URL
  ?? 'http://127.0.0.1:8000';

export default defineConfig({
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      // Align with CI job "E2E (Playwright)" / tests/e2e/playwright.config.ts
      name: 'e2e',
      testDir: './tests/e2e/specs',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'browser',
      testDir: './tests/Browser',
      timeout: 30_000,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
