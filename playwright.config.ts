import { defineConfig, devices } from '@playwright/test'

// Runs against a MODX site prepared by _build/ci/fixtures.php:
//   BASE_URL=http://localhost:8052 FIXTURES='{"custom":2,"formit":3}' npm run e2e
// With NOTIFIER=1 only the @notifier tests run; they need the
// fetchit.frontend.default.notifier setting on.
const notifier = !!process.env.NOTIFIER

export default defineConfig({
  testDir: 'tests/e2e',
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // A test that passes only on retry still fails the run.
  failOnFlakyTests: !!process.env.CI,
  grep: notifier ? /@notifier/ : undefined,
  grepInvert: notifier ? undefined : /@notifier/,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8052',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
