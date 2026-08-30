import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Place a normal Sale order, then FULLY refund it through PrestaShop's own
 * back-office refund UI (the "Partial refund" control on the order's
 * Products panel — PS9 only exposes a separate "Standard refund" trigger
 * when the order has already been (partially) refunded once; for a fresh
 * order the single "Partial refund" button IS the standard refund entry
 * point).
 *
 * The module hooks actionOrderSlipAdd (one invocation per refund operation,
 * carrying the created credit slip). A refund counts as FULL only when the
 * slip covers the entire amount paid — products AND shipping — so this spec
 * refunds the product's full quantity and enters the shipping refund too.
 * The full path issues a single gateway reverseCapture with CREDIT_ON_FAIL=1:
 * the GATEWAY reverses the unsettled capture (or auto-credits a settled one);
 * the module no longer pre-checks settlement or falls back client-side.
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

  // A FULL refund must cover shipping too — the module treats a slip that
  // equals the order's total paid (products + shipping) as full and issues
  // a single gateway-side reversal. The shipping field is part of the same
  // real cancel_product form (CancelProductType::shipping_amount). This
  // shop ships everything at a flat 5.00, the fallback when PS renders the
  // field without a max attribute.
  const shippingInput = page.locator(
    '#cancel_product_shipping_amount, input[name="cancel_product[shipping_amount]"]'
  ).first();
  await expect(shippingInput).toBeVisible();
  const maxShipping = await shippingInput.getAttribute('max');
  await shippingInput.fill(maxShipping || '5.00');
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

  // Read-only check that the module took the FULL path: one gateway
  // reverseCapture(creditOnFail) call, approved. (The gateway reverses the
  // unsettled capture; had it been settled, the same call would come back
  // re-routed as CCCREDIT — either way a single approved leg.)
  const { execSync } = await import('node:child_process');
  const logLine = execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "SELECT message FROM ps_log WHERE message LIKE '%refundOrderFull%' ORDER BY id_log DESC LIMIT 1;"`
  ).toString().trim();
  console.log('module log: ' + logLine);
  expect(logLine).toMatch(/refundOrderFull .*APPROVED/i);
});
