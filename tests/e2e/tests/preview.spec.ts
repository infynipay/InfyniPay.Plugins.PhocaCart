import { test, expect } from '@playwright/test';

/**
 * Visual preview — no credentials required.
 *
 * Drives the REAL local site so you can watch the harness work end-to-end
 * against your environment before supplying the admin/console credentials for
 * the full install→connect→buy scenario in infynipay.e2e.spec.ts.
 *
 * Run headed + slow:
 *   npx playwright test preview --headed
 */
const BASE = (process.env.JOOMLA_BASE_URL || 'http://localhost/joomla').replace(/\/+$/, '');
const ADMIN = (process.env.JOOMLA_ADMIN_URL || `${BASE}/administrator`).replace(/\/+$/, '');

test('preview: tour the real storefront and admin login', async ({ page }) => {
  test.setTimeout(120_000);

  await test.step('open the storefront home', async () => {
    await page.goto(`${BASE}/`);
    await expect(page).toHaveTitle(/.+/);
    await page.waitForTimeout(1500);
  });

  await test.step('open the PhocaCart shop listing', async () => {
    await page.goto(`${BASE}/index.php?option=com_phocacart&view=categories`);
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
  });

  await test.step('open the Joomla administrator login', async () => {
    await page.goto(`${ADMIN}/index.php`);
    // Stock Joomla admin login field — visible proof the admin is reachable.
    await expect(page.locator('#mod-login-username, #username').first()).toBeVisible();
    await page.waitForTimeout(2000);
  });

  await test.step('show the Extensions → Install screen (login required beyond here)', async () => {
    await page.goto(`${ADMIN}/index.php?option=com_installer&view=install`);
    await page.waitForTimeout(2000);
  });
});
