import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Authorize at checkout, then capture only PART of the amount from the back
 * office — both through the real UI. Mirrors 05-authorize-capture.spec.js's
 * structure (payment action flipped via the module's own admin config page,
 * not the database).
 *
 * Per the design doc (docs/prestashop-integration-design.md §3.1):
 *   "Capture (partial) | Back office | capture(OrderRef, Money); order stays
 *   in the awaiting state until fully captured"
 * So a partial capture is expected to leave the order in "Awaiting capture"
 * (id 20), not move it to "Payment accepted". This spec asserts the actual
 * observed behaviour; if the order instead flips straight to Payment
 * accepted on a partial amount, that's reported as a possible module bug
 * rather than bent into a false pass — see handleAdminOrderActions() in
 * inoviopayment.php, which currently sets $newState =
 * Configuration::get('PS_OS_PAYMENT') unconditionally on any approved
 * capture result, with no check on whether $amount was less than the order
 * total.
 */
async function setPaymentAction(page, value) {
  await page.goto(`/${ADMIN_DIR}/index.php?controller=AdminModules&configure=inoviopayment`);
  await page.locator('select[name="INOVIOPAYMENT_PAYMENT_ACTION"]').selectOption(value);
  await page.locator('button[name="submitinoviopayment"]').click();
  await expect(page.locator('body')).toContainText(/Settings updated|successful/i, { timeout: 30000 });
}

test('Authorize then partially capture from the back office', async ({ page }) => {
  const shot = ck.shotter('13-partial-capture');

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

  // Merchant opens the order: it must be awaiting capture (state 20).
  await admin.loginAdmin(page, ADMIN_DIR);
  await admin.openOrder(page, ADMIN_DIR, ref);
  await shot(page, 'order-awaiting-capture');

  const panel = page.locator('#inovio-order-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText(/Capture/i);

  const stateSelector = '#update_order_status_action_input';
  const stateBefore = await page.locator(stateSelector).inputValue().catch(() => null);
  const panelTextBefore = await panel.innerText();

  // This order's total is a single product at the storefront's default
  // price; $5.00 is deliberately less than that so the amount posted is a
  // genuine partial capture, not an accidental full one.
  const PARTIAL_AMOUNT = '5.00';
  await panel.locator('input[name="inovio_capture_amount"]').fill(PARTIAL_AMOUNT);
  await shot(page, 'partial-amount-entered');

  // Merchant clicks Capture — a real form POST back to the order-view page.
  await panel.locator('button[name="inovio_capture"]').click();
  await page.waitForLoadState('networkidle');

  // Re-open the order so we read the committed state rather than the stale
  // pre-POST DOM.
  await admin.openOrder(page, ADMIN_DIR, ref);
  await page.waitForLoadState('networkidle');
  await shot(page, 'after-partial-capture');

  const panelAfter = page.locator('#inovio-order-panel');
  const panelTextAfter = await panelAfter.innerText().catch(() => '');
  const stateAfter = await page.locator(stateSelector).inputValue().catch(() => null);

  console.log('PARTIAL_CAPTURE_ORDER=' + ref);
  console.log('partial amount posted =', PARTIAL_AMOUNT);
  console.log('state before capture click =', stateBefore, '| after =', stateAfter);
  console.log('panel before:', panelTextBefore.replace(/\s+/g, ' '));
  console.log('panel after :', panelTextAfter.replace(/\s+/g, ' '));

  // Restore Sale so other specs are unaffected, regardless of what we found.
  await setPaymentAction(page, 'sale');

  // AWAITING_CAPTURE = 20 (module state id, from CLAUDE.md / the module's
  // own STATE_AWAITING_CAPTURE constant).
  const AWAITING_CAPTURE = '20';

  if (stateAfter !== AWAITING_CAPTURE) {
    console.error(
      'POSSIBLE MODULE BUG: a $5.00 partial capture on order ' + ref + ' moved the order ' +
      'state from ' + stateBefore + ' to ' + stateAfter + ' instead of leaving it at ' +
      AWAITING_CAPTURE + ' ("Awaiting capture (Inovio)"), contradicting design doc §3.1 ' +
      '("order stays in the awaiting state until fully captured"). ' +
      'handleAdminOrderActions() in inoviopayment.php unconditionally sets ' +
      '$newState = Configuration::get(\'PS_OS_PAYMENT\') on ANY approved capture result ' +
      '(full or partial) — it never checks whether $amount was less than the order total ' +
      'before choosing the target state. See evidence/13-partial-capture/*.png.'
    );
  }

  // Assert the actual observed behaviour per the design doc, rather than
  // bending the assertion to whatever happened.
  expect(
    stateAfter,
    'A partial ($5.00) capture should leave the order in "Awaiting capture" (state 20) ' +
    'per design doc §3.1, but the observed state after the capture was ' + stateAfter +
    ' (started at ' + stateBefore + '). See console output above and ' +
    'evidence/13-partial-capture/*.png — this is a real module behaviour finding, not a ' +
    'test issue.'
  ).toBe(AWAITING_CAPTURE);
});
