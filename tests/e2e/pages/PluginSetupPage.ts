import { expect, type Page } from '@playwright/test';
import { env } from '../utils/env.js';

/**
 * The plugin's own configuration page (com_plugins edit view). Selectors here
 * target the markup rendered by SetupbuttonField / layouts/field/setupbutton.php,
 * which we control — so they are stable.
 */
export class PluginSetupPage {
  constructor(private readonly page: Page) {}

  async open(extensionId: number): Promise<void> {
    await this.page.goto(
      `${env.joomla.adminUrl}/index.php?option=com_plugins&task=plugin.edit&extension_id=${extensionId}`
    );
    await expect(this.page.locator('input[name="jform[params][sandbox]"]').first()).toBeAttached();
  }

  /** Force "Sandbox" mode on (radio switcher value 1). */
  async enableSandboxMode(): Promise<void> {
    // The radio switcher renders two labels; click the "Yes" (value=1) one.
    const yesRadio = this.page.locator('input[name="jform[params][sandbox]"][value="1"]');
    if (await yesRadio.count()) {
      await yesRadio.first().check({ force: true }).catch(async () => {
        await this.page.locator('label[for="jform_params_sandbox1"]').click();
      });
    }
  }

  /** True when the plugin already has credentials saved (license_id non-empty). */
  async isConnected(): Promise<boolean> {
    const license = this.page.locator('input[name="jform[params][license_id]"]');
    if (!(await license.count())) return false;
    const value = await license.first().inputValue().catch(() => '');
    if (value.trim() !== '') return true;
    // Fallback: the "Connected" card is rendered only when connected.
    return (await this.page.locator('.ph-card-connected').count()) > 0;
  }

  /**
   * Clicks the "Connect" button (rendered in the disconnected state) which
   * navigates the SAME page to console.infynipay.com/auth/signin?...grantAuthorization=true.
   */
  async clickConnect(): Promise<void> {
    const connect = this.page
      .locator('.ph-card-disconnected-body a.btn-primary, a.btn:has-text("Connect")')
      .first();
    await expect(connect).toBeVisible();
    await connect.click();
    await this.page.waitForURL(/infynipay\.com/i, { timeout: 30_000 });
  }

  /** Asserts the page has flipped to the "Connected" state for sandbox. */
  async expectConnected(): Promise<void> {
    await expect(this.page.locator('.ph-card-connected, .ph-card-connected-title')).toBeVisible({
      timeout: 30_000,
    });
    await expect(
      this.page.locator('.ph-gateway-card--sandbox')
    ).toBeVisible();
  }
}
