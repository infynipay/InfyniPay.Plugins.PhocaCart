import 'dotenv/config';

/**
 * Centralised, validated access to the e2e environment configuration.
 * Every value the tests depend on is read here so a missing/typo'd variable
 * fails fast with a clear message instead of a confusing selector timeout.
 */

function required(name: string): string {
  const value = process.env[name];
  if (!value || value.trim() === '') {
    throw new Error(
      `Missing required env var "${name}". Copy tests/e2e/.env.example to ` +
        `tests/e2e/.env and fill it in.`
    );
  }
  return value.trim();
}

function optional(name: string, fallback = ''): string {
  const value = process.env[name];
  return value && value.trim() !== '' ? value.trim() : fallback;
}

function stripTrailingSlash(url: string): string {
  return url.replace(/\/+$/, '');
}

const baseUrl = stripTrailingSlash(required('JOOMLA_BASE_URL'));
const adminUrl = stripTrailingSlash(
  optional('JOOMLA_ADMIN_URL', `${baseUrl}/administrator`)
);

export const env = {
  joomla: {
    baseUrl,
    adminUrl,
    adminUser: required('JOOMLA_ADMIN_USER'),
    adminPassword: required('JOOMLA_ADMIN_PASSWORD'),
  },
  plugin: {
    zipPath: optional('PLUGIN_ZIP', './dist/plg_pcp_infynipay.zip'),
  },
  console: {
    // Optional: when CHROME_PROFILE is used the console session is already
    // signed in, so email/password aren't needed.
    email: optional('PH_CONSOLE_EMAIL'),
    password: optional('PH_CONSOLE_PASSWORD'),
  },
  // When set, tests launch the real Google Chrome with this user-data profile
  // (e.g. "Profile 5") so existing logins (Infynipay console) are reused.
  chromeProfile: {
    dir: optional('CHROME_PROFILE'),
    userDataDir: optional('CHROME_USER_DATA_DIR'),
  },
  shop: {
    productUrl: required('TEST_PRODUCT_URL'),
    paymentMethodName: optional('PAYMENT_METHOD_NAME', 'Infynipay'),
    shippingMethodName: optional('SHIPPING_METHOD_NAME'),
  },
  customer: {
    firstName: optional('TEST_CUSTOMER_FIRST_NAME', 'Test'),
    lastName: optional('TEST_CUSTOMER_LAST_NAME', 'Buyer'),
    email: optional('TEST_CUSTOMER_EMAIL', 'test.buyer@example.com'),
    address: optional('TEST_CUSTOMER_ADDRESS', '221B Baker Street'),
    city: optional('TEST_CUSTOMER_CITY', 'London'),
    zip: optional('TEST_CUSTOMER_ZIP', 'NW16XE'),
    phone: optional('TEST_CUSTOMER_PHONE', '+15555550123'),
    country: optional('TEST_CUSTOMER_COUNTRY', 'United Kingdom'),
  },
  sandboxCard: {
    number: optional('SANDBOX_CARD_NUMBER', '4111111111111111'),
    exp: optional('SANDBOX_CARD_EXP', '12/30'),
    cvc: optional('SANDBOX_CARD_CVC', '123'),
    name: optional('SANDBOX_CARD_NAME', 'Test Buyer'),
  },
  timeouts: {
    payment: Number(optional('PAYMENT_TIMEOUT_MS', '120000')),
  },
} as const;

/** Shared storage-state file so the admin session is reused across phases. */
export const ADMIN_STORAGE_STATE = '.auth/admin.json';
