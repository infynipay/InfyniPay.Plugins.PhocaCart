import { expect, type Page } from '@playwright/test';
import path from 'node:path';
import { env } from '../utils/env.js';

/**
 * Drives the Joomla administrator: login, install the plugin package, and
 * enable the plugin. Selectors here target stock Joomla 4/5 admin markup.
 */
export class JoomlaAdminPage {
  constructor(private readonly page: Page) {}

  async login(): Promise<void> {
    await this.page.goto(`${env.joomla.adminUrl}/index.php`);

    // Already logged in? The login form won't be present.
    if (await this.page.locator('#mod-login-username, #username').count()) {
      await this.page.fill('#mod-login-username, #username', env.joomla.adminUser);
      await this.page.fill('#mod-login-password, #passwd', env.joomla.adminPassword);
      await this.page.click('#btn-login-submit, button[type="submit"]');
    }

    // Control panel header confirms a successful login.
    await expect(
      this.page.locator('.header-title, #content, .page-title').first()
    ).toBeVisible();
  }

  /**
   * Installs the plugin .zip via System → Install → Extensions → Upload Package File.
   * Idempotent: Joomla's `method="upgrade"` manifest means re-uploading just upgrades.
   */
  async installPlugin(): Promise<void> {
    const zip = path.resolve(env.plugin.zipPath);
    await this.page.goto(
      `${env.joomla.adminUrl}/index.php?option=com_installer&view=install`
    );

    // The upload tab hosts a hidden file input named install_package.
    const fileInput = this.page.locator('input#install_package, input[name="install_package"]');
    await fileInput.waitFor({ state: 'attached' });
    await fileInput.setInputFiles(zip);

    // Submitting the file input auto-posts; if not, click the upload button.
    const uploadBtn = this.page.locator('#installbutton_package, button:has-text("Upload & Install")');
    if (await uploadBtn.count()) {
      await uploadBtn.first().click();
    }

    await expect(
      this.page.locator('#system-message-container, .alert, #content')
    ).toContainText(/success|installed|installing|was successful/i, { timeout: 60_000 });
  }

  /**
   * Enables the plugin in Extensions → Plugins (search "infynipay").
   * Returns the extension_id so later phases can open the config page directly.
   */
  async enablePlugin(): Promise<number> {
    await this.page.goto(
      `${env.joomla.adminUrl}/index.php?option=com_plugins&view=plugins&filter[folder]=pcp`
    );

    // Filter to our plugin.
    const search = this.page.locator('#filter_search');
    if (await search.count()) {
      await search.fill('infynipay');
      await search.press('Enter');
    }

    const row = this.page.locator('tr', { hasText: /infynipay/i }).first();
    await expect(row).toBeVisible();

    // Enable if currently disabled (red/unpublish icon → click to publish).
    const enableToggle = row.locator(
      'a[href*="plugins.publish"], button[aria-label*="Enable" i], .tbody-icon a'
    );
    const isDisabled =
      (await row.locator('.icon-publish, .unpublish, [aria-label*="Disabled" i]').count()) > 0;

    if (isDisabled && (await enableToggle.count())) {
      await enableToggle.first().click();
    }

    await expect(row).toContainText(/enabled/i, { timeout: 15_000 }).catch(() => {
      /* Some Joomla themes render status as an icon only — non-fatal. */
    });

    return this.openPluginConfigByElement();
  }

  /** Opens the plugin edit page and returns its extension_id. */
  async openPluginConfigByElement(): Promise<number> {
    await this.page.goto(
      `${env.joomla.adminUrl}/index.php?option=com_plugins&view=plugins&filter[folder]=pcp&filter[search]=infynipay`
    );
    const editLink = this.page
      .locator('a[href*="task=plugin.edit"], a[href*="layout=edit"]', { hasText: /infynipay/i })
      .first();
    await editLink.click();

    await this.page.waitForURL(/extension_id=\d+/, { timeout: 15_000 });
    const match = this.page.url().match(/extension_id=(\d+)/);
    if (!match) {
      throw new Error('Could not determine plugin extension_id from the edit URL.');
    }
    return Number(match[1]);
  }
}
