import { defineConfig, devices } from '@playwright/test';
import 'dotenv/config';

/**
 * The e2e flow is a single ordered story (install → connect → buy → verify),
 * so we run a single worker, never in parallel, and keep retries off — a retry
 * mid-story would re-install/re-pay and muddy the result.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    headless: true,
    viewport: { width: 1440, height: 900 },
    actionTimeout: 20_000,
    navigationTimeout: 45_000,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
    // Local dev servers frequently use self-signed certs / mixed hosts.
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chrome',
      // Use the real Google Chrome installed on this machine, headed by default.
      use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    },
  ],
});
