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
 * navigation), and (c) no new order row appears in the DB (read-only
 * verification only — nothing here writes to the database or calls the
 * module directly).
 */

/** Read-only: highest order id currently in the DB, to prove none was added. */
function maxOrderId() {
  const out = execSync(
    'docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop ' +
    '-N -e "SELECT COALESCE(MAX(id_order), 0) FROM ps_orders;"'
  ).toString().trim();

  return parseInt(out, 10);
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
    const before = maxOrderId();

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);

    // Same length/prefix as the approving PAN but with the last digit bumped
    // by one, which breaks the Luhn checksum without breaking the format.
    await submitCard(page, { pan: '4111111111111112', month: '12', year: '2030', cvv: '123' });
    await shot(page, 'luhn-fail-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'luhn-fail-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    expect(maxOrderId()).toBe(before);
  });

  test('PAN that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-short-pan');
    const before = maxOrderId();

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);

    // luhnValid() in inovio-checkout.js requires pan.length >= 12 before it
    // even runs the checksum, so a 6-digit PAN fails on length alone.
    await submitCard(page, { pan: '411111', month: '12', year: '2030', cvv: '123' });
    await shot(page, 'short-pan-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'short-pan-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    expect(maxOrderId()).toBe(before);
  });

  test('Expiry in the past (current year, past month) is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-expiry');
    const before = maxOrderId();

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);

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

    await submitCard(page, { pan: ck.CARDS.frictionless, month: pastMonth, year: currentYear, cvv: '123' });
    await shot(page, 'past-expiry-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid expiration date/i);
    await shot(page, 'past-expiry-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    expect(maxOrderId()).toBe(before);
  });

  test('CVV that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('12-invalid-card-input-cvv');
    const before = maxOrderId();

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);

    await submitCard(page, { pan: ck.CARDS.frictionless, month: '12', year: '2030', cvv: '12' });
    await shot(page, 'short-cvv-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid security code/i);
    await shot(page, 'short-cvv-error-shown');

    await expect(page).not.toHaveURL(/order-confirmation/);
    expect(maxOrderId()).toBe(before);
  });
});
