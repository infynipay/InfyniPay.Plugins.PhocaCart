import { test as base, expect, chromium, type BrowserContext } from '@playwright/test';
import os from 'node:os';
import path from 'node:path';
import { env } from '../utils/env.js';

/**
 * A Playwright fixture that, when CHROME_PROFILE is set, launches the REAL
 * Google Chrome against an existing user-data profile (e.g. "Profile 5" =
 * "amir"). This reuses that profile's logins — notably the already-signed-in
 * Infynipay console session — so no console email/password is required.
 *
 * ⚠️ Chrome locks a profile while it is open. All Chrome windows must be fully
 *    closed before running, or launch fails with a ProcessSingleton error.
 *
 * When CHROME_PROFILE is empty, falls back to Playwright's default context
 * (fresh, no saved logins) so the suite still runs normally.
 */

const DEFAULT_USER_DATA_DIR = path.join(
  os.homedir(),
  'AppData',
  'Local',
  'Google',
  'Chrome',
  'User Data'
);

export const test = base.extend<{ context: BrowserContext }>({
  context: async ({}, use) => {
    const profileDir = env.chromeProfile.dir;

    if (!profileDir) {
      // No profile requested — use a normal ephemeral Chrome context.
      const context = await chromium.launchPersistentContext('', {
        headless: false,
        channel: 'chrome',
        viewport: { width: 1440, height: 900 },
        ignoreHTTPSErrors: true,
      });
      await use(context);
      await context.close();
      return;
    }

    const userDataDir = env.chromeProfile.userDataDir || DEFAULT_USER_DATA_DIR;
    const context = await chromium.launchPersistentContext(userDataDir, {
      headless: false,
      channel: 'chrome',
      viewport: { width: 1440, height: 900 },
      ignoreHTTPSErrors: true,
      args: [`--profile-directory=${profileDir}`],
    });

    await use(context);
    await context.close();
  },

  page: async ({ context }, use) => {
    const page = context.pages()[0] ?? (await context.newPage());
    await use(page);
  },
});

export { expect };
