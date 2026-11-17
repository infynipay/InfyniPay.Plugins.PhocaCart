import { type Page } from '@playwright/test';
import { env } from '../utils/env.js';

/**
 * Drives the REAL Infynipay console sign-in + grant-authorization flow at
 * https://console.infynipay.com.
 *
 * ⚠️ These selectors target an external app we do not control. If the console's
 * markup changes, adjust the locators below. They are written defensively
 * (role + common attribute fallbacks) to survive minor UI tweaks. Use
 * `npm run codegen -- https://console.infynipay.com/auth/signin` to capture
 * fresh selectors if needed.
 */
export class InfynipayConsolePage {
  constructor(private readonly page: Page) {}

  /**
   * Completes sign-in (if a login form is shown) and approves the grant so the
   * console redirects back to the Joomla admin returnUrl with licenseId +
   * authorizationCode in the query string.
   */
  async signInAndGrant(): Promise<void> {
    await this.signInIfNeeded();
    await this.approveGrantIfNeeded();

    // The console redirects back to the Joomla admin (com_plugins) once granted.
    await this.page.waitForURL(/option=com_plugins/i, { timeout: 60_000 });
  }

  private async signInIfNeeded(): Promise<void> {
    const emailField = this.page
      .locator('input[type="email"], input[name="email"], input[name="username"], #email')
      .first();

    // Already authenticated (session cookie) — no login form rendered.
    if (!(await emailField.count())) return;
    await emailField.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => {});
    if (!(await emailField.isVisible().catch(() => false))) return;

    await emailField.fill(env.console.email);
    await this.page
      .locator('input[type="password"], input[name="password"], #password')
      .first()
      .fill(env.console.password);

    await this.page
      .getByRole('button', { name: /sign in|log ?in|continue/i })
      .first()
      .click()
      .catch(async () => {
        await this.page.locator('button[type="submit"]').first().click();
      });
  }

  private async approveGrantIfNeeded(): Promise<void> {
    // The grant screen presents an authorize/allow/approve/connect button.
    const grantBtn = this.page
      .getByRole('button', { name: /authori[sz]e|allow|approve|grant|connect|continue/i })
      .first();

    await grantBtn
      .waitFor({ state: 'visible', timeout: 20_000 })
      .then(() => grantBtn.click())
      .catch(() => {
        /* Some accounts auto-redirect without an explicit grant click. */
      });
  }
}
