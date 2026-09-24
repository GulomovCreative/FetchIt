import { defineConfig, devices } from '@playwright/test'

// Runs against a MODX site prepared by _build/ci/fixtures.php, with
// fetchit.protection.min_time and fetchit.protection.rate_limit at 0:
//   BASE_URL=http://localhost:8052 FIXTURES="$(php fixtures.php)" npm run e2e
// Tagged tests need other settings and run alone with E2E_TAG:
//   E2E_TAG=notifier  fetchit.frontend.default.notifier 1
//   E2E_TAG=timing    fetchit.protection.min_time 3
const tag = process.env.E2E_TAG

export default defineConfig({
  testDir: 'tests/e2e',
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // A test that passes only on retry still fails the run.
  failOnFlakyTests: !!process.env.CI,
  grep: tag ? new RegExp(`@${tag}\\b`) : undefined,
  grepInvert: tag ? undefined : /@(notifier|timing)\b/,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:8052',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
