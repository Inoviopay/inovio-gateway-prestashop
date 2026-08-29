import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

/**
 * 3D Secure with Cardinal's challenge PAN.
 *
 * The ACS step-up renders in a cross-origin iframe. Playwright CAN drive a
 * cross-origin frame via frameLocator, so the OTP is typed here for real —
 * no POSTing to our own return controller, no DB writes.
 */
test('3DS: challenge is presented and completed by the shopper', async ({ page }) => {
  const shot = ck.shotter('04-3ds');

  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.challenge);
  await ck.acceptTerms(page);
  await shot(page, 'challenge-pan-entered');

  await ck.placeOrder(page);

  // The challenge host page renders, then the ACS iframe appears.
  await page.waitForTimeout(6000);
  await shot(page, 'after-place-order');

  const frames = page.frames().map((f) => f.url());
  console.log('FRAMES=' + JSON.stringify(frames, null, 1));

  /*
   * Cardinal nests the real challenge: our iframe loads Cruise/StepUp, which
   * itself embeds the ACS `creq` frame holding the OTP field. Playwright can
   * drive a cross-origin frame, so find the frame that actually contains an
   * input and type into it — no injected state anywhere.
   */
  const acsFrame = page.frames().find((f) => /creq/.test(f.url()))
    || page.frames().find((f) => /cardinal/.test(f.url()));
  expect(acsFrame, 'Cardinal ACS frame should be present').toBeTruthy();

  const otpField = acsFrame.locator('input[type="text"], input[type="tel"], input[type="password"], input[name*="challenge" i]').first();
  await otpField.waitFor({ state: 'visible', timeout: 45000 });
  await shot(page, 'acs-challenge-visible');

  // Cardinal's sandbox OTP.
  await otpField.fill('1234');
  await shot(page, 'otp-entered');

  await acsFrame.locator('input[type="submit"], button[type="submit"], #submitButton').first().click();

  await ck.expectConfirmed(page);
  await shot(page, 'confirmed-after-challenge');
});
