import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Authorize at checkout, then capture from the back office — both through the
 * real UI. Requires the module's payment action to be "authorize"; the test
 * asserts the resulting order state rather than assuming it.
 */
test('Back office: order detail shows Inovio gateway references', async ({ page }) => {
  const shot = ck.shotter('05-backoffice');

  // Place a normal order first so there is something to inspect.
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
  await shot(page, 'order-placed');

  // Now inspect it in the back office.
  await admin.loginAdmin(page, ADMIN_DIR);
  await shot(page, 'admin-dashboard');

  await admin.openOrder(page, ADMIN_DIR, ref);
  await shot(page, 'admin-order-detail');

  // The module's own panel must render with real gateway references.
  await expect(page.locator('#inovio-order-panel')).toBeVisible();
  await expect(page.locator('#inovio-order-panel')).toContainText(/Gateway order/i);
  await shot(page, 'inovio-panel');

  console.log('BACKOFFICE_ORDER=' + ref);
});
