import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Place a Sale order with product quantity >= 2, then refund only PART of
 * it (quantity 1 of 2) through PrestaShop's real back-office "Partial
 * refund" control. Unlike 07-refund.spec.js (which refunds the FULL
 * refundable quantity via the same control), this spec deliberately types a
 * strictly smaller quantity so the resulting credit slip is genuinely
 * partial.
 *
 * The module hooks actionOrderSlipAdd (one invocation per refund operation,
 * carrying the created credit slip). A slip smaller than the order's total
 * paid routes to a gateway CCCREDIT for exactly the slip amount — and the
 * gateway REFUSES a credit on an order that has not settled (service 536),
 * which the module surfaces as a loud error instead of silently reversing
 * the whole capture (the old bug). So this spec runs in two phases:
 *
 *   A. Pre-settlement: the partial refund must FAIL VISIBLY in the back
 *      office, with no gateway money movement.
 *   B. The capture is marked settled in the GATEWAY's database (settlement
 *      is an acquirer batch process with no UI anywhere — flipping
 *      trans_settled on the gateway side simulates the acquirer, not the
 *      shop; every shop interaction stays real UI). The same partial
 *      refund then succeeds as a gateway CCCREDIT for the slip amount.
 *
 * Known PS9 core caveat (verified in OrderSlipCreator::create): the credit
 * slip row is committed BEFORE actionOrderSlipAdd fires, so phase A leaves
 * an orphan slip on record even though the gateway refused the refund. The
 * module cannot prevent that from inside the hook; the loud error is the
 * merchant's signal that no money moved.
 */
function dbQuery(sql) {
  return execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "${sql}"`
  ).toString().trim();
}

test('Refund PART of a multi-quantity Sale order from the back office', async ({ page }) => {
  const shot = ck.shotter('14-partial-refund');

  // Shopper places a Sale order with quantity 2 of the same product — add
  // it to the cart twice, exactly as a shopper clicking "Add to cart" twice
  // would.
  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.addProduct(page);
  await shot(page, 'cart-quantity-two');

  // Confirm the cart really holds quantity 2 before proceeding — if the
  // second add-to-cart click merged into the same line instead of adding a
  // unit, the rest of this spec would silently degrade into a full refund.
  // The theme's own per-line quantity stepper
  // (checkout/_partials/cart-detailed-product-line.tpl,
  // class js-cart-line-product-quantity) is the authoritative value.
  await page.goto('/cart?action=show');
  await shot(page, 'cart-detail');
  const qtyInput = page.locator('.js-cart-line-product-quantity').first();
  await expect(qtyInput).toHaveValue('2');

  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless);
  await ck.acceptTerms(page);
  await ck.placeOrder(page);
  const ref = await ck.expectConfirmed(page);
  expect(ref).toBeTruthy();
  await shot(page, 'order-placed');

  const orderRow = dbQuery(
    `SELECT id_order, total_paid_tax_incl FROM ps_orders WHERE reference='${ref}';`
  );
  const [idOrderStr, totalPaidStr] = orderRow.split('\t');
  const idOrder = parseInt(idOrderStr, 10);
  const totalPaid = parseFloat(totalPaidStr);
  console.log('PARTIAL_REFUND_ORDER=' + ref, 'id_order=' + idOrder, 'total_paid=' + totalPaid);

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

  // Type quantity 1 (strictly less than the refundable max of 2) — a real
  // partial refund, not the full-quantity refund 07-refund.spec.js covers.
  const quantityInput = page.locator('input[id^="cancel_product_quantity_"]').first();
  await expect(quantityInput).toBeVisible();
  const maxQty = await quantityInput.getAttribute('max');
  expect(parseInt(maxQty || '0', 10)).toBeGreaterThanOrEqual(2);

  const PARTIAL_QTY = '1';
  await quantityInput.fill(PARTIAL_QTY);
  await quantityInput.dispatchEvent('change');

  const amountInput = page.locator('input[id^="cancel_product_amount_"]').first();
  await expect(amountInput).not.toHaveValue('0.00');
  const partialAmountStr = await amountInput.inputValue();
  await shot(page, 'partial-quantity-selected');

  // --- Phase A: pre-settlement, the partial refund must fail loudly. ------
  await page.locator('#cancel_product_save').click();
  await page.waitForLoadState('networkidle');
  await shot(page, 'phaseA-presettlement-refused');

  // The back office must SHOW an error — the silent-full-reversal era is over.
  await expect(page.locator('body')).toContainText(/error|not settled/i);

  const phaseALog = dbQuery(
    "SELECT message FROM ps_log WHERE message LIKE '%inovio%' ORDER BY id_log DESC LIMIT 3;"
  );
  console.log('phase A module log:\n' + phaseALog);
  expect(phaseALog).toMatch(/not settled/i);
  expect(phaseALog).toMatch(/refundOrderPartial .*FAILED/i);

  // --- Phase B: settle the capture gateway-side, then the same partial
  // refund succeeds as a CCCREDIT. -----------------------------------------
  const poId = dbQuery(
    `SELECT ref_value FROM ps_inovio_order_ref WHERE id_order=${idOrder} AND ref_key='po_id';`
  );
  expect(poId).toMatch(/^\d+$/);
  console.log('gateway PO_ID=' + poId + ' — marking settled (simulated acquirer batch)');
  // Local-stack SYS credentials come from the environment so they are not
  // committed; ORACLE_PWD matches stack/docker-compose.yml's default.
  const oraclePwd = process.env.ORACLE_PWD || 'Oracle21c!';
  const settleOut = execSync(
    `docker --context desktop-linux exec inovio-oracle bash -c "printf 'UPDATE pmt.transaction SET trans_settled=1 WHERE po_id=${poId};\\nCOMMIT;\\n' | sqlplus -s \\"sys/${oraclePwd}@//localhost:1521/ORCLPDB1 as sysdba\\"" `
  ).toString();
  console.log('settle output: ' + settleOut.trim());
  expect(settleOut).toMatch(/row updated/i);

  await admin.openOrder(page, ADMIN_DIR, ref);
  await page.locator('button.partial-refund-display').click();
  const qtyInput2 = page.locator('input[id^="cancel_product_quantity_"]').first();
  await expect(qtyInput2).toBeVisible();
  await qtyInput2.fill(PARTIAL_QTY);
  await qtyInput2.dispatchEvent('change');
  await expect(page.locator('input[id^="cancel_product_amount_"]').first()).not.toHaveValue('0.00');
  await shot(page, 'phaseB-partial-selected');

  await page.locator('#cancel_product_save').click();
  await page.waitForLoadState('networkidle');
  await shot(page, 'phaseB-after-partial-refund-submit');

  await expect(page.locator('body')).not.toContainText(/error occurred|An error/i);

  // A credit slip must be listed (phase A's orphan slip may also be there —
  // the PS core caveat in the header — so assert presence, not an exact count).
  await page.locator('#orderDocumentsTab').click();
  const docsTab = page.locator('#orderDocumentsTabContent');
  await expect(docsTab).toContainText(/Credit slip/i, { timeout: 30000 });
  expect(await docsTab.locator('a[href*="generateOrderSlipPDF"]').count()).toBeGreaterThan(0);
  await shot(page, 'partial-refund-recorded');

  // --- Read-only DB verification -----------------------------------------

  // The credit slip amount must be strictly less than the order total: this
  // is what makes it a genuine PARTIAL refund rather than a full one.
  const slipRow = dbQuery(
    `SELECT amount, partial FROM ps_order_slip WHERE id_order=${idOrder} ORDER BY id_order_slip DESC LIMIT 1;`
  );
  const [slipAmountStr, slipPartialStr] = slipRow.split('\t');
  const slipAmount = parseFloat(slipAmountStr);
  console.log('credit slip amount =', slipAmount, '| partial flag =', slipPartialStr, '| order total =', totalPaid);

  expect(slipAmount).toBeGreaterThan(0);
  expect(
    slipAmount,
    `Credit slip amount (${slipAmount}) should be strictly less than the order total ` +
    `(${totalPaid}) for a partial refund of qty ${PARTIAL_QTY} of ${maxQty}.`
  ).toBeLessThan(totalPaid);

  // At least one unit is recorded refunded, never the full quantity (phase
  // A's aborted attempt may or may not have advanced this counter depending
  // on where PS's command aborted — the phase-B refund definitely did).
  const qtyRefunded = dbQuery(
    `SELECT product_quantity_refunded FROM ps_order_detail WHERE id_order=${idOrder} LIMIT 1;`
  );
  console.log('product_quantity_refunded =', qtyRefunded);
  expect(parseInt(qtyRefunded, 10)).toBeGreaterThanOrEqual(1);
  expect(parseInt(qtyRefunded, 10)).toBeLessThan(parseInt(maxQty, 10));

  // The phase-B partial went through as an approved gateway CCCREDIT.
  const logLines = dbQuery(
    "SELECT message FROM ps_log WHERE message LIKE '%inovio%' ORDER BY id_log DESC LIMIT 5;"
  );
  console.log('recent ps_log inovio lines:\n' + logLines);
  expect(logLines).toMatch(/refundOrderPartial .*APPROVED/i);
});
