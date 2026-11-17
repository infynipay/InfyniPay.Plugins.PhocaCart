import { test, expect } from '../fixtures/persistentChrome.js';

/**
 * Confirms the "amir" Chrome profile is reused and is already signed in to the
 * Infynipay console — proving the OAuth grant step won't need a password.
 *
 * Requires CHROME_PROFILE in .env (e.g. "Profile 5") and ALL Chrome windows closed.
 *
 *   npx playwright test profile-check --headed
 */
test('profile-check: Infynipay console session is reused from the amir profile', async ({
  page,
}) => {
  test.setTimeout(90_000);

  await page.goto('https://console.infynipay.com');
  await page.waitForLoadState('networkidle').catch(() => {});

  // If a sign-in form is visible we are NOT logged in on this profile.
  const signInForm = page.locator(
    'input[type="email"], input[name="email"], input[name="username"]'
  );
  const onSignIn = await signInForm
    .first()
    .isVisible()
    .catch(() => false);

  // Give a couple of seconds so you can see the console state in the window.
  await page.waitForTimeout(3000);

  expect(
    onSignIn,
    'Console shows a sign-in form — the amir profile is not signed in (or Chrome ' +
      'was open and the profile was locked). Sign in to console.infynipay.com ' +
      'in the amir profile, fully close Chrome, and re-run.'
  ).toBe(false);
});
