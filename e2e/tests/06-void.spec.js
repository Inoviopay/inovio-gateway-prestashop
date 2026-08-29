import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * Authorize at checkout, then VOID from the back office — both through the
 * real UI. Mirrors 05-authorize-capture.spec.js's structure; see that spec
 * for why the payment action is flipped through the module's own admin
 * config page rather than the database.
 */
async function setPaymentAction(page, value) {
  await page.goto(`/${ADMIN_DIR}/index.php?controller=AdminModules&configure=inoviopayment`);
  await page.locator('select[name="INOVIOPAYMENT_PAYMENT_ACTION"]').selectOption(value);
  await page.locator('button[name="submitinoviopayment"]').click();
  await expect(page.locator('body')).toContainText(/Settings updated|successful/i, { timeout: 30000 });
}

test('Authorize then void from the back office', async ({ page }) => {
  const shot = ck.shotter('07-void');

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

  // Merchant opens the order: it must be awaiting capture (state 20) before
  // it can be voided.
  await admin.loginAdmin(page, ADMIN_DIR);
  await admin.openOrder(page, ADMIN_DIR, ref);
  await shot(page, 'order-awaiting-capture');

  const panel = page.locator('#inovio-order-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText(/Void/i);

  const stateSelector = '#update_order_status_action_input';
  const stateBefore = await page.locator(stateSelector).inputValue().catch(() => null);
  const panelTextBefore = await panel.innerText();

  // Merchant clicks Void. The template wires a confirm() dialog to the
  // button (onclick="return confirm(...)"); accept it like a real merchant
  // would.
  page.once('dialog', (dialog) => dialog.accept());
  await panel.locator('button[name="inovio_void"]').click();
  await page.waitForLoadState('networkidle');

  // The state select is re-rendered by the order page; re-open the order so we
  // read the committed state rather than the stale pre-POST DOM.
  await admin.openOrder(page, ADMIN_DIR, ref);
  await page.waitForLoadState('networkidle');
  await shot(page, 'after-void');

  const panelAfter = page.locator('#inovio-order-panel');
  const panelTextAfter = await panelAfter.innerText().catch(() => '');
  const stateAfter = await page.locator(stateSelector).inputValue().catch(() => null);

  console.log('VOID_ORDER=' + ref);
  console.log('state before void click =', stateBefore, '| after =', stateAfter);
  console.log('panel before:', panelTextBefore.replace(/\s+/g, ' '));
  console.log('panel after :', panelTextAfter.replace(/\s+/g, ' '));

  if (stateAfter === stateBefore) {
    console.error(
      'MODULE BUG: clicking Void (button[name="inovio_void"]) posted back to the ' +
      'order-view page (HTTP 200) but did not change the order state — it is still ' +
      String(stateAfter) +
      ' (expected 18, "Authorization voided"). Same root cause as 05-authorize-capture: ' +
      'inoviopayment.php has no handler for Tools::isSubmit(\'inovio_capture\') / ' +
      '\'inovio_void\' anywhere in the module — InovioGateway::voidOrder() exists and is ' +
      'covered by a standalone PHP test script (tests/e2e_module_verbs.php) but is never ' +
      'invoked from the admin order panel.'
    );
  }

  // Restore Sale so other specs are unaffected, regardless of the assertion
  // outcome below.
  await setPaymentAction(page, 'sale');

  expect(
    stateAfter,
    'Clicking Void in #inovio-order-panel did not change the order state — ' +
    'see console output above and evidence/07-void/*.png. This is a real module bug ' +
    '(missing admin POST handler for inovio_void), not a test issue.'
  ).not.toBe(stateBefore);
});
