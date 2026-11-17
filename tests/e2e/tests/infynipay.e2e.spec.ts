import { test, expect } from '../fixtures/persistentChrome.js';
import { JoomlaAdminPage } from '../pages/JoomlaAdminPage.js';
import { PluginSetupPage } from '../pages/PluginSetupPage.js';
import { InfynipayConsolePage } from '../pages/InfynipayConsolePage.js';
import { ShopPage } from '../pages/ShopPage.js';
import { HostedPaymentPage } from '../pages/HostedPaymentPage.js';

/**
 * Full Infynipay × PhocaCart story, run as one ordered scenario:
 *
 *   1. Install the plugin package into Joomla and enable it.
 *   2. Complete the setup: switch to sandbox + connect via the real
 *      Infynipay console (OAuth grant) until the plugin shows "Connected".
 *   3. As a customer, buy a test product, pay on the real sandbox hosted page,
 *      get redirected back to the shop, and assert the order is confirmed.
 *
 * Phases share state (the plugin's extension_id), so they run serially.
 */
test.describe.configure({ mode: 'serial' });

let extensionId = 0;

test.describe('Infynipay PhocaCart — end to end', () => {
  test('1) install the plugin and complete the setup (connect to Infynipay)', async ({
    page,
  }) => {
    test.setTimeout(180_000); // installing + external OAuth can be slow

    const admin = new JoomlaAdminPage(page);
    const setup = new PluginSetupPage(page);
    const console_ = new InfynipayConsolePage(page);

    await test.step('log into Joomla administrator', async () => {
      await admin.login();
    });

    await test.step('install the plugin package', async () => {
      await admin.installPlugin();
    });

    await test.step('enable the plugin', async () => {
      extensionId = await admin.enablePlugin();
      expect(extensionId).toBeGreaterThan(0);
    });

    await test.step('open plugin settings and switch to sandbox', async () => {
      await setup.open(extensionId);
      await setup.enableSandboxMode();
    });

    await test.step('connect to Infynipay via the console OAuth grant', async () => {
      if (await setup.isConnected()) {
        test.info().annotations.push({ type: 'note', description: 'Already connected — skipping OAuth.' });
        return;
      }
      await setup.clickConnect();
      await console_.signInAndGrant();
      await setup.expectConnected();
    });
  });

  test('2) customer buys a test product and the order is marked paid', async ({ page }) => {
    test.setTimeout(180_000);

    const shop = new ShopPage(page);
    const hosted = new HostedPaymentPage(page);

    await test.step('add the test product to the cart', async () => {
      await shop.addTestProductToCart();
    });

    await test.step('check out and choose Infynipay', async () => {
      await shop.gotoCheckout();
      await shop.fillCustomerDetails();
      await shop.selectInfynipayPayment();
      await shop.confirmOrder();
    });

    await test.step('pay on the Infynipay sandbox hosted page', async () => {
      await shop.continueToPay();
      // If we navigated off-site to Infynipay, complete the sandbox payment.
      if (/infynipay\.com/i.test(page.url())) {
        await hosted.completeSandboxPayment();
      }
    });

    await test.step('return to the shop and validate the order status', async () => {
      const orderId = await shop.expectPaymentSuccess();
      expect(orderId, 'a real order id should be present on the confirmation card').toBeGreaterThan(
        0
      );
      test.info().annotations.push({
        type: 'order',
        description: `Confirmed order #${orderId}`,
      });
    });
  });
});
