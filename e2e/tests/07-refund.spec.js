import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Place a normal Sale order, then refund it through PrestaShop's own
 * back-office refund UI (the "Partial refund" control on the order's
 * Products panel — PS9 only exposes a separate "Standard refund" trigger
 * when the order has already been (partially) refunded once; for a fresh
 * order the single "Partial refund" button IS the standard refund entry
 * point, and selecting the product's full refundable quantity through it
 * is how a merchant fully refunds an order in this UI).
 *
 * The module hooks actionProductCancel for both STANDARD_REFUND and
 * PARTIAL_REFUND action types (see hookActionProductCancel in
 * inoviopayment.php), so this exercises the same code path either way.
 */
test('Refund a Sale order from the back office', async ({ page }) => {
  const shot = ck.shotter('08-refund');

  // Shopper places a normal Sale order.
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

  // Merchant opens the order.
  await admin.loginAdmin(page, ADMIN_DIR);
  await admin.openOrder(page, ADMIN_DIR, ref);
  await shot(page, 'admin-order-detail');

  await expect(page.locator('#inovio-order-panel')).toBeVisible();

  // Enter refund mode via the real "Partial refund" control.
  const refundTrigger = page.locator('button.partial-refund-display');
  await expect(refundTrigger).toBeVisible();
  await refundTrigger.click();
  await shot(page, 'refund-mode-opened');

  // In "Partial refund" mode the merchant types the quantity to refund
  // directly (the per-row selector checkbox is only shown in Standard
  // refund / Return / Cancel modes — confirmed by reading the module's
  // OrderViewPageMap.ts: .cancel-product-selector isn't part of the
  // partialRefund toggle set). Type the product's full refundable quantity,
  // exactly as a merchant issuing a full refund through this control would.
  const quantityInput = page.locator('input[id^="cancel_product_quantity_"]').first();
  await expect(quantityInput).toBeVisible();
  const maxQty = await quantityInput.getAttribute('max');
  await quantityInput.fill(maxQty || '1');
  // Typing the quantity fires the same 'change' handler as a checkbox would
  // and auto-fills the amount input with the full refundable amount.
  await quantityInput.dispatchEvent('change');

  const amountInput = page.locator('input[id^="cancel_product_amount_"]').first();
  await expect(amountInput).not.toHaveValue('0.00');
  await shot(page, 'product-selected-for-refund');

  // Save. This is a real form POST (form[name="cancel_product"], route
  // swapped by JS to admin_orders_partial_refund by the button click above).
  await page.locator('#cancel_product_save').click();
  await page.waitForLoadState('networkidle');
  await shot(page, 'after-refund-submit');

  console.log('REFUND_ORDER=' + ref);

  await expect(page.locator('body')).not.toContainText(/error occurred|An error/i);

  // PrestaShop's real, UI-visible record of the refund: the order's own
  // "Documents" tab lists a generated credit-slip PDF with a real download
  // link. This is created only by a successful refund submit — unlike the
  // pre-refund "Generate a credit slip" checkbox label, which is present
  // whether or not a refund ever happens.
  await page.locator('#orderDocumentsTab').click();
  const docsTab = page.locator('#orderDocumentsTabContent');
  await expect(docsTab).toContainText(/Credit slip/i, { timeout: 30000 });
  await expect(docsTab.locator('a[href*="generateOrderSlipPDF"]')).toHaveCount(1);
  await shot(page, 'refund-recorded');
});
