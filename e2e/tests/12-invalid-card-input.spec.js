import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';

/**
 * Client-side validation at checkout, before the card is ever tokenized.
 *
 * inovio-checkout.js's validate() (views/js/inovio-checkout.js) runs
 * synchronously on submit, ahead of any network call:
 *   - Luhn check on the PAN
 *   - expiry must not be in the past
 *   - CVV must match ^[0-9]{3,4}$
 *
 * A failure here must never reach the gateway or create an order — it's a
 * pure client-side reject. Each case below fills the real form, clicks the
 * real submit control, and asserts (a) the shopper sees the right message
 * in #inovio-errors, (b) the page stays on checkout (no order-confirmation
 * navigation), and (c) no new order row appears in the DB for the shopper
 * account used here (read-only verification only — nothing here writes to
 * the database or calls the module directly).
 *
 * This stack is shared with another agent adding OTHER specs concurrently
 * against the same shop and the same shopper login, so a bare
 * "MAX(id_order) is unchanged" check is racy: an unrelated, legitimate
 * order from that concurrent run can land between our "before" and "after"
 * reads and produce a false failure. To keep the DB check meaningful
 * without being flaky, checkNoNewOrder() takes the "before" id right before
 * the submit click (not at the top of the test) and, if a new order row
 * does appear, inspects it before failing: a new row for a DIFFERENT total
 * than this test's own cart is someone else's order, not evidence of a
 * validation bypass here, and is logged rather than failed on.
 */

/** Read-only: highest order id currently in the DB. */
function maxOrderId() {
  const out = execSync(
    'docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop ' +
    '-N -e "SELECT COALESCE(MAX(id_order), 0) FROM ps_orders;"'
  ).toString().trim();

  return parseInt(out, 10);
}

/** Read-only: id_order/reference/total for every order strictly after `afterId`. */
function ordersAfter(afterId) {
  const out = execSync(
    'docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop ' +
    `-N -e "SELECT id_order, reference, total_paid_tax_incl FROM ps_orders WHERE id_order > ${afterId};"`
  ).toString().trim();

  if (!out) {
    return [];
  }

  return out.split('\n').map((line) => {
    const [id, reference, total] = line.split('\t');

    return { id: parseInt(id, 10), reference, total: parseFloat(total) };
  });
}

/**
 * Assert no order belonging to THIS test's cart was created, tolerating a
 * concurrently-running, unrelated order landing in the same window (see the
 * file-level comment above).
 * @param {number} before max id_order captured right before the submit click.
 * @param {number} ourTotal the cart total this test's own order would carry.
 */
function assertNoOrderCreated(before, ourTotal) {
  const newOrders = ordersAfter(before);
  const ours = newOrders.filter((o) => Math.abs(o.total - ourTotal) < 0.01);

  if (newOrders.length && !ours.length) {
    console.log(
      'Note: ' + newOrders.length + ' new order row(s) appeared after this test\'s "before" ' +
      'snapshot, but none match this cart\'s total ($' + ourTotal.toFixed(2) + ') — ' +
      JSON.stringify(newOrders) + '. Treating as unrelated concurrent activity on the ' +
      'shared stack, not evidence this test\'s invalid card was accepted.'
    );
  }

  expect(
    ours,
    'A new order matching this test\'s cart total ($' + ourTotal.toFixed(2) + ') was created ' +
    'despite invalid client-side input: ' + JSON.stringify(ours)
  ).toEqual([]);
}

/** Read-only: this order's cart total (tax incl.), from the payment step's own summary. */
async function cartTotal(page) {
  const text = await page.locator('body').innerText();
  const m = text.match(/Total \(tax incl\.\)\s*\$?([\d,.]+)/i);

  if (!m) {
    throw new Error('Could not read cart total from the payment step summary.');
  }

  return parseFloat(m[1].replace(/,/g, ''));
}

/** Fill the card form directly (bypassing ck.fillCard's fixed-valid values) and submit. */
async function submitCard(page, { pan, month, year, cvv }) {
  const form = page.locator('#inovio-payment-form');
  await form.locator('[name="inovio_card_number"]').fill(pan);
  if (month) {
    await form.locator('[name="inovio_exp_month"]').selectOption(month);
  }
  if (year) {
    await form.locator('[name="inovio_exp_year"]').selectOption(year);
  }
  await form.locator('[name="inovio_cvv"]').fill(cvv);
  await ck.acceptTerms(page);
  await ck.placeOrder(page);
}

test.describe('Invalid card input: rejected client-side, no order created', () => {
  test('PAN failing the Luhn check is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-luhn');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    // Same length/prefix as the approving PAN but with the last digit bumped
    // by one, which breaks the Luhn checksum without breaking the format.
    const before = maxOrderId();
    await submitCard(page, { pan: '4111111111111112', month: '12', year: '2030', cvv: '123' });
    await shot(page, 'luhn-fail-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'luhn-fail-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('PAN that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-short-pan');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    // luhnValid() in inovio-checkout.js requires pan.length >= 12 before it
    // even runs the checksum, so a 6-digit PAN fails on length alone.
    const before = maxOrderId();
    await submitCard(page, { pan: '411111', month: '12', year: '2030', cvv: '123' });
    await shot(page, 'short-pan-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'short-pan-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('Expiry in the past (current year, past month) is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-expiry');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    /*
     * The year <select> is server-rendered from
     * range(date('Y'), date('Y') + 11) (inoviopayment.php) — it never offers
     * a past year, so a genuinely past YEAR cannot be selected through the
     * UI at all. That means the "past year" half of this case is not
     * reachable via the real form; it is not being skipped for convenience,
     * it structurally cannot be driven as a shopper action.
     *
     * validate()'s expiry check is independently sensitive to a past MONTH
     * within the CURRENT year:
     *   parseInt(year) === now.getFullYear() && parseInt(month) < now.getMonth()+1
     * So this case picks the current year and January (unless the test is
     * literally run in January, handled by falling back to the current
     * month minus one when possible, or by skipping with a clear reason if
     * no past month exists in the current year at all).
     */
    const now = new Date();
    const currentMonth = now.getMonth() + 1; // 1-12

    if (currentMonth === 1) {
      test.skip(true, 'No past month exists within the current year in January; ' +
        'a genuinely past year is not selectable in the UI (year <select> only ' +
        'offers current..current+11) so this case has no reachable expiry to pick.');
    }

    const pastMonth = String(currentMonth - 1).padStart(2, '0');
    const currentYear = String(now.getFullYear());

    const before = maxOrderId();
    await submitCard(page, { pan: ck.CARDS.frictionless, month: pastMonth, year: currentYear, cvv: '123' });
    await shot(page, 'past-expiry-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid expiration date/i);
    await shot(page, 'past-expiry-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('CVV that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-cvv');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    const before = maxOrderId();
    await submitCard(page, { pan: ck.CARDS.frictionless, month: '12', year: '2030', cvv: '12' });
    await shot(page, 'short-cvv-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid security code/i);
    await shot(page, 'short-cvv-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    assertNoOrderCreated(before, ourTotal);
  });
});
