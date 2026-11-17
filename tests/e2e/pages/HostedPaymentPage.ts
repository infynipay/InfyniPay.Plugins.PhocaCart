import { type Page } from '@playwright/test';
import { env } from '../utils/env.js';

/**
 * Drives the REAL Infynipay sandbox hosted payment page: enters the test card
 * (or picks the sandbox "approve" control) and submits, after which Infynipay
 * redirects the browser back to the shop's return URL.
 *
 * ⚠️ External app we do not control. The hosted page may render a card form, a
 * provider redirect, or a sandbox "simulate success" button depending on the
 * checkout method configured in your Infynipay console. The logic below tries
 * a card form first, then falls back to an approve/success button. Adjust to
 * match your sandbox. `npm run codegen` against a live hosted-page URL is the
 * fastest way to capture exact selectors.
 */
export class HostedPaymentPage {
  constructor(private readonly page: Page) {}

  /** Completes the sandbox payment so it captures and redirects back. */
  async completeSandboxPayment(): Promise<void> {
    // Some sandboxes drop you straight on a "Pay now / Simulate success" screen.
    if (await this.tryCardForm()) {
      // card filled — fall through to submit
    }
    await this.submitPayment();
  }

  /** Fills a card form if one is present. Returns true when a form was found. */
  private async tryCardForm(): Promise<boolean> {
    const cardNumber = this.page
      .locator('input[name*="card" i][name*="number" i], input[autocomplete="cc-number"], #cardNumber')
      .first();

    // Card fields are often inside iframes (PCI). Search frames too.
    if (!(await cardNumber.count())) {
      const inFrame = await this.fillCardInFrames();
      if (inFrame) return true;
      return false;
    }

    await cardNumber.fill(env.sandboxCard.number);
    await this.page
      .locator('input[autocomplete="cc-exp"], input[name*="exp" i], #cardExpiry')
      .first()
      .fill(env.sandboxCard.exp)
      .catch(() => {});
    await this.page
      .locator('input[autocomplete="cc-csc"], input[name*="cvc" i], input[name*="cvv" i], #cardCvc')
      .first()
      .fill(env.sandboxCard.cvc)
      .catch(() => {});
    await this.page
      .locator('input[autocomplete="cc-name"], input[name*="holder" i], #cardName')
      .first()
      .fill(env.sandboxCard.name)
      .catch(() => {});
    return true;
  }

  private async fillCardInFrames(): Promise<boolean> {
    for (const frame of this.page.frames()) {
      const num = frame.locator('input[autocomplete="cc-number"], input[name*="number" i]').first();
      if (await num.count()) {
        await num.fill(env.sandboxCard.number).catch(() => {});
        await frame
          .locator('input[autocomplete="cc-exp"], input[name*="exp" i]')
          .first()
          .fill(env.sandboxCard.exp)
          .catch(() => {});
        await frame
          .locator('input[autocomplete="cc-csc"], input[name*="cvc" i], input[name*="cvv" i]')
          .first()
          .fill(env.sandboxCard.cvc)
          .catch(() => {});
        return true;
      }
    }
    return false;
  }

  private async submitPayment(): Promise<void> {
    const submit = this.page
      .getByRole('button', {
        name: /pay|approve|success|confirm|complete|simulate|authori[sz]e/i,
      })
      .first();
    await submit
      .waitFor({ state: 'visible', timeout: 20_000 })
      .then(() => submit.click())
      .catch(async () => {
        await this.page.locator('button[type="submit"], input[type="submit"]').first().click();
      });
  }
}
