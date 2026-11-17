# Infynipay × PhocaCart — End-to-End Tests

Playwright tests that exercise the whole plugin story against a **real** local
Joomla + PhocaCart install and the **real** Infynipay sandbox:

1. **Install** the plugin package into Joomla and enable it.
2. **Set up** — switch to sandbox and connect to Infynipay through the console
   OAuth grant until the plugin reports **Connected**.
3. **Buy** — as a customer, add a test product, check out with Infynipay, pay
   on the sandbox hosted page, return to the shop, and assert the order is
   **confirmed**.

## Prerequisites

- A local Joomla 4/5 site with **PhocaCart** installed and a published **test
  product**.
- In PhocaCart, a published **Payment Method** whose plugin is Infynipay
  (alias `infynipay`). Note its title for `PAYMENT_METHOD_NAME`.
- A **Infynipay sandbox account** at <https://console.infynipay.com> you can
  sign in to and grant the app.
- Node.js 18+.

> **Webhooks & localhost:** Infynipay's server-to-server webhook can't reach a
> `localhost`/private address, so on a purely local box the order status is
> finalised by the plugin's *return-page fallback* (`CatchProviderReturn`) when
> the buyer comes back. That's exactly what these tests assert. To exercise the
> webhook too, expose the site with a tunnel (ngrok / Cloudflare Tunnel) and set
> `JOOMLA_BASE_URL` to the public URL.

## Setup

```bash
cd tests/e2e
npm install
npx playwright install chromium
cp .env.example .env        # then edit .env with your values
npm run build:zip           # builds dist/plg_pcp_infynipay.zip from the repo
```

Fill in `.env` — every site URL, credential, and the test card live there (it is
git-ignored). See the comments in `.env.example`.

## Run

```bash
npm test            # headless
npm run test:headed # watch it drive the browser
npm run test:ui     # Playwright UI mode (step through)
npm run report      # open the HTML report after a run
```

## Layout

```
tests/e2e/
  playwright.config.ts        # single worker, serial, traces on failure
  .env.example                # all environment-specific values
  utils/env.ts                # validated config loader (fails fast on missing vars)
  scripts/build-plugin-zip.ps1# packages the plugin into an installable .zip
  pages/
    JoomlaAdminPage.ts        # login, install package, enable plugin
    PluginSetupPage.ts        # sandbox toggle + "Connect" (our markup — stable)
    InfynipayConsolePage.ts # console sign-in + grant (external — see note)
    ShopPage.ts               # storefront checkout + result assertions
    HostedPaymentPage.ts      # sandbox hosted-page payment (external — see note)
  tests/
    infynipay.e2e.spec.ts   # the 2-phase ordered scenario
```

## Selector stability — read this if a step times out

Locators fall into two groups:

- **Stable (the plugin renders them):** the Connect button, the sandbox/connected
  cards (`.ph-card-connected`, `.ph-gateway-card--sandbox`), the payment radio
  `input[name="phpaymentopt"]`, the **Continue to Pay** button `#ph_pay_btn`, and
  the result card `.phrc` / `.phrc-badge`. These match the plugin source and
  shouldn't need changes.

- **Best-effort (external / template-dependent):** the Joomla template's checkout
  field names, the **Infynipay console** sign-in + grant screen, and the
  **sandbox hosted page** card form / approve button. These are written
  defensively but target apps/markup we don't control.

If one of those can't be found, capture fresh selectors with codegen and update
the relevant page object:

```bash
npx playwright codegen "http://localhost/joomla/index.php?option=com_phocacart&view=checkout"
npx playwright codegen "https://console.infynipay.com/auth/signin"
```

The most common tweaks are in `ShopPage.fillCustomerDetails`/`selectInfynipayPayment`
(PhocaCart field names differ by version/template) and `HostedPaymentPage`
(depends on which checkout method your sandbox app uses).
