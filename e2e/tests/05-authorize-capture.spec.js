import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Authorize at checkout, then CAPTURE from the back office — both real UI.
 *
 * The payment action is flipped through the module's own admin config page,
 * not by writing to the database, so this stays an end-to-end merchant flow.
 */
async function setPaymentAction(page, value) {
  await page.goto(`/${ADMIN_DIR}/index.php?controller=AdminModules&configure=inoviopayment`);
  await page.locator('select[name="INOVIOPAYMENT_PAYMENT_ACTION"]').selectOption(value);
  await page.locator('button[name="submitinoviopayment"]').click();
  await expect(page.locator('body')).toContainText(/Settings updated|successful/i, { timeout: 30000 });
}

test('Authorize then capture from the back office', async ({ page }) => {
  const shot = ck.shotter('06-authorize-capture');

  // Merchant switches the module to Authorize-only.
  await admin.loginAdmin(page, ADMIN_DIR);
  await setPaymentAction(page, 'authorize');
  await shot(page, 'payment-action-authorize');

  // Shopper checks out.
  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless);
  await ck.acceptTerms(page);
  await ck.placeOrder(page);
  const ref = await ck.expectConfirmed(page);
  expect(ref).toBeTruthy();
  await shot(page, 'authorized-order-confirmed');

  // Merchant captures it.
  await admin.loginAdmin(page, ADMIN_DIR);
  await admin.openOrder(page, ADMIN_DIR, ref);
  await shot(page, 'order-awaiting-capture');

  const panel = page.locator('#inovio-order-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText(/Capture/i);

  await panel.locator('button[name="inovio_capture"]').click();
  await page.waitForTimeout(4000);
  await shot(page, 'after-capture');

  console.log('AUTHORIZE_CAPTURE_ORDER=' + ref);

  // Restore Sale so other specs are unaffected.
  await setPaymentAction(page, 'sale');
});
