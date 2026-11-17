import { expect, type Page } from '@playwright/test';
import { env } from '../utils/env.js';

/**
 * Drives the PhocaCart storefront: add product → checkout → pick Infynipay →
 * confirm order → land on the plugin's "Continue to Pay" page.
 *
 * ⚠️ PhocaCart's checkout markup varies by version + Joomla template. The
 * Infynipay-specific hooks (payment radio name `phpaymentopt`, pay button
 * `#ph_pay_btn`, result card `.phrc`) are stable because the plugin renders
 * them. The generic storefront fields below use label/role-based locators with
 * fallbacks — tune them to your template if a step can't find an element.
 */
export class ShopPage {
  constructor(private readonly page: Page) {}

  /** Adds the configured test product to the cart from its detail page. */
  async addTestProductToCart(): Promise<void> {
    await this.page.goto(env.shop.productUrl);

    const addBtn = this.page
      .locator(
        '.pc-add-to-cart-button, .phAddToCartButton, button[name="task"], ' +
          'input[name="task"], a:has-text("Add to cart"), button:has-text("Add to cart")'
      )
      .first();
    await expect(addBtn).toBeVisible();
    await addBtn.click();

    // PhocaCart shows an "added to cart" confirmation modal/notice.
    await this.page
      .locator('.pc-cart-popup, .phMessageContainer, .ph-cart, [class*="cart"]')
      .first()
      .waitFor({ state: 'visible', timeout: 10_000 })
      .catch(() => {/* AJAX-add may not show a modal — non-fatal */});
  }

  /** Navigates to the checkout view. */
  async gotoCheckout(): Promise<void> {
    await this.page.goto(
      `${env.joomla.baseUrl}/index.php?option=com_phocacart&view=checkout`
    );
    await expect(this.page).toHaveURL(/view=checkout/);
  }

  /** Fills the billing/contact details required to place the order. */
  async fillCustomerDetails(): Promise<void> {
    const c = env.customer;

    await this.setField(['name_first', 'first_name', 'firstname'], c.firstName, /first ?name/i);
    await this.setField(['name_last', 'last_name', 'lastname'], c.lastName, /last ?name/i);
    await this.setField(['email', 'email_contact'], c.email, /e-?mail/i);
    await this.setField(['address_1', 'address1', 'street'], c.address, /address|street/i);
    await this.setField(['city'], c.city, /city|town/i);
    await this.setField(['zip', 'postcode', 'zip_code'], c.zip, /zip|post ?code/i);
    await this.setField(['phone_1', 'phone', 'phone_mobile'], c.phone, /phone|mobile/i);

    // Country is usually a <select>.
    const countrySelect = this.page
      .locator('select[name*="country" i], select#country')
      .first();
    if (await countrySelect.count()) {
      await countrySelect.selectOption({ label: c.country }).catch(() => {});
    }
  }

  /**
   * Selects the Infynipay payment method by its merchant-given title and any
   * shipping method if one is configured.
   */
  async selectInfynipayPayment(): Promise<void> {
    // Pick a shipping method first if a name was provided (some carts require it
    // before payment options render).
    if (env.shop.shippingMethodName) {
      const shipLabel = this.page
        .locator('label', { hasText: env.shop.shippingMethodName })
        .first();
      if (await shipLabel.count()) {
        await shipLabel.click().catch(() => {});
      }
    } else {
      // Otherwise select the first available shipping option.
      const firstShip = this.page.locator('input[name="phshippingopt"]').first();
      if (await firstShip.count()) await firstShip.check({ force: true }).catch(() => {});
    }

    // The Infynipay payment radio. Match by the label text the merchant set.
    const payLabel = this.page
      .locator('label', { hasText: env.shop.paymentMethodName })
      .first();
    await expect(payLabel, 'Infynipay payment method should be listed at checkout').toBeVisible({
      timeout: 15_000,
    });
    // Click the label (wraps the input[name="phpaymentopt"]).
    await payLabel.click();
  }

  /** Confirms the order; the plugin sets proceed=1 and we land on the pay page. */
  async confirmOrder(): Promise<void> {
    const confirmBtn = this.page
      .locator(
        '.pc-checkout-button, #phCheckoutButton, button[name="task"], ' +
          'button:has-text("Confirm"), button:has-text("Place order"), ' +
          'input[type="submit"][value*="Confirm" i]'
      )
      .first();
    await expect(confirmBtn).toBeVisible();
    await confirmBtn.click();
  }

  /** Clicks the plugin's "Continue to Pay" button → redirects to the hosted page. */
  async continueToPay(): Promise<void> {
    const payBtn = this.page.locator('#ph_pay_btn');
    await expect(payBtn, 'Plugin "Continue to Pay" button should render').toBeVisible({
      timeout: 30_000,
    });
    await payBtn.click();

    // handleAjaxPay creates the hosted payment and redirects off-site.
    await this.page.waitForURL(/infynipay\.com|checkout/i, {
      timeout: env.timeouts.payment,
    });
  }

  /**
   * Asserts the plugin's result card shows a successful (captured) payment and
   * returns the order id parsed from the URL / card.
   */
  async expectPaymentSuccess(): Promise<number> {
    const card = this.page.locator('.phrc');
    await expect(card, 'Plugin confirmation card should render').toBeVisible({
      timeout: env.timeouts.payment,
    });

    await expect(this.page.locator('.phrc-h')).toContainText(/payment successful/i);
    await expect(this.page.locator('.phrc-badge')).toContainText(/confirmed/i);

    const orderText = await this.page.locator('.phrc-val').first().innerText().catch(() => '');
    const fromCard = orderText.match(/#(\d+)/);
    const fromUrl = this.page.url().match(/order_id=(\d+)/);
    return Number((fromCard && fromCard[1]) || (fromUrl && fromUrl[1]) || 0);
  }

  /**
   * Helper: set a text input identified by any of `names` (name attribute) or a
   * label regex, tolerating template differences.
   */
  private async setField(names: string[], value: string, label: RegExp): Promise<void> {
    for (const name of names) {
      const byName = this.page.locator(`input[name="${name}"], input[name*="${name}"]`).first();
      if (await byName.count()) {
        await byName.fill(value).catch(() => {});
        return;
      }
    }
    const byLabel = this.page.getByLabel(label).first();
    if (await byLabel.count()) {
      await byLabel.fill(value).catch(() => {});
    }
  }
}
