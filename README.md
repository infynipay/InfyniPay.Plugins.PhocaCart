# Infynipay — Phoca Cart Payment Plugin

A Phoca Cart payment plugin that integrates **Infynipay** checkout with
**Phoca Cart on Joomla 4 / 5**. Shoppers pay with any provider you connect in your
Infynipay dashboard, and you switch providers without changing your store.

| Item | Value |
| --- | --- |
| Joomla | 4.x / 5.x |
| Phoca Cart | 4.x+ |
| PHP | 8.0+ |
| Plugin | `plg_pcp_infynipay` (group `pcp`) |
| Namespace | `Joomla\Plugin\PhocacartPayment\Infynipay` |
| Version | 1.0.0 |
| License | GNU General Public License v2 or later |

## Features

- Hosted-page checkout — PCI-friendly redirect flow.
- Multi-provider checkout — offer every provider you connect, in one place.
- One-click OAuth activation — no manual API keys.
- Sandbox + Live credentials with a one-toggle switch.
- Server-to-server payment-status confirmation, with a return-page re-check as a fallback.
- Configurable Phoca Cart order-status IDs for success / pending / failed / cancelled.
- Supported-providers grid and inline log viewer in the plugin settings.

## Installation

1. Install the plugin zip via **System → Install → Extensions** in Joomla.
2. Enable it at **System → Plugins → "plg_pcp_infynipay"** (group `pcp`).
3. Open the plugin settings and click **Connect** to run the OAuth flow — sandbox and live credentials are stored automatically.
4. In Phoca Cart, enable Infynipay as a payment method and, optionally, set the order-status IDs.

📖 Full step-by-step guide:
<https://www.infynipay.com/integrations/phoca-cart/>

## Configuration

- **OAuth connect** — link your Infynipay account (no manual API keys).
- **Sandbox / Live** toggle for credential selection.
- **Order-status mapping** — Phoca Cart status IDs for success (default `2`), pending (`1`), failed (`3`) and cancelled (`3`).
- **Log viewer** — shows the plugin log-file path.

## Payment confirmation

After the buyer pays on the Infynipay hosted page, the plugin re-checks the
authoritative payment state with Infynipay before it updates the Phoca Cart order,
so an order is only marked paid once Infynipay confirms it server-side. A re-check on
the buyer's return page acts as a fallback.

## Support

- **Email:** support@infynipay.com
- **Docs:** <https://docs.infynipay.com>

## License

GNU General Public License v2 or later.

## About InfyniPay

[InfyniPay](https://www.infynipay.com) is a payment orchestration platform that connects your store to 30+ payment providers through a single free, open-source plugin — with automatic routing, failover, webhook verification and server-side confirmation handled for you. Add or switch providers from a dashboard without touching code.

- 🔌 **One integration, 30+ providers** — PayPal, Stripe, Adyen, Paystack, Razorpay, Binance Pay and more
- 🆓 **Free & open-source** — no per-transaction fee from InfyniPay; you pay only your provider's processing fees
- 🔁 **Automatic routing & failover** between providers
- 🛡️ **Signed webhooks + server-side verification** built in

**Links:** [Website](https://www.infynipay.com) · [Phoca Cart integration](https://www.infynipay.com/integrations/phoca-cart/) · [Docs](https://docs.infynipay.com) · [All integrations](https://www.infynipay.com/integrations/) · [Create a free account](https://console.infynipay.com/auth/signup)
