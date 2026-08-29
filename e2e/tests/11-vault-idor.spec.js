import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';

/**
 * SECURITY: one shopper must never be able to spend another shopper's
 * saved card.
 *
 * The server-side guard is InovioStoredCard::findForCustomer() (see
 * controllers/front/validation.php::collectPaymentData) — it loads the
 * saved-card row scoped to the CURRENT customer, and if the requested id
 * either doesn't exist or belongs to someone else, the whole request is
 * refused ('saved_card_not_found') before any gateway call is made.
 *
 * inovio-checkout.js's own client logic (see inovio-checkout.js around
 * onPaymentFormSubmit): if `[name="inovio_saved_card_id"]:checked` has a
 * truthy value at submit time, it skips tokenization entirely and submits
 * the form as-is. So the realistic attack a malicious shopper can mount is
 * exactly this: inject/alter their OWN checkout form's DOM (their browser,
 * their page — nothing server-side, nothing crafted at the network layer)
 * so that a saved_card_id radio checked with someone else's id exists, then
 * click the real Place Order button. That is what this spec does. This is
 * the one spec in this suite where DOM tampering is legitimate, because the
 * tampering itself is the threat being tested.
 *
 * If B's payment instead succeeds, that is a critical IDOR vulnerability —
 * this spec is written to fail loudly with full evidence if that happens.
 */

const SHOPPER_A = ck.SHOPPER; // shopper@inovio.local

// Deterministic second shopper so re-runs are idempotent (register-or-reuse).
const SHOPPER_B = {
  email: 'shopper-b-idor@inovio.local',
  password: 'ShopperB123!',
  firstname: 'ShopperB',
  lastname: 'IdorTest',
};

function queryDb(sql) {
  const out = execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "${sql}"`,
    { encoding: 'utf8' }
  );

  return out.trim();
}

async function registerOrLoginShopperB(page) {
  await page.goto('/login');
  await page.fill('input[name="email"]', SHOPPER_B.email);
  await page.fill('input[name="password"]', SHOPPER_B.password);
  await page.click('#submit-login');

  if (await page.locator('#userMenuButton').isVisible({ timeout: 5000 }).catch(() => false)) {
    return; // account already existed from a previous run
  }

  // Not an existing account (or bad state) — register through the real
  // storefront registration form.
  await page.goto('/registration');
  await page.fill('#field-firstname', SHOPPER_B.firstname);
  await page.fill('#field-lastname', SHOPPER_B.lastname);
  await page.fill('#field-email', SHOPPER_B.email);
  await page.fill('#field-password', SHOPPER_B.password);

  const psgdpr = page.locator('#field-psgdpr');
  if (await psgdpr.count()) {
    await psgdpr.check();
  }
  const privacy = page.locator('#field-customer_privacy');
  if (await privacy.count()) {
    await privacy.check();
  }

  await page.locator('button[name="submitCreate"], #customer-form button[type="submit"]').first().click();
  await expect(page.locator('#userMenuButton')).toBeVisible({ timeout: 30000 });
}

/**
 * A brand-new shopper has no delivery address on file, so
 * ck.toPaymentStep() (which assumes one already exists, as every other
 * spec's shopper account does) stalls at the addresses step. Fill the real
 * address form once, through the UI, exactly as a first-time shopper would.
 * Idempotent: if shopper B already has an address from a previous run, the
 * addresses step is already satisfied and this is a no-op.
 */
async function ensureAddress(page) {
  await page.goto('/order');

  const addressForm = page.locator('#field-address1');
  if (!(await addressForm.count()) || !(await addressForm.isVisible().catch(() => false))) {
    return; // already has an address on file; nothing to fill
  }

  await addressForm.fill('456 Idor Test Ave');
  await page.locator('#field-city').fill('Las Vegas');
  await page.locator('#field-id_state').selectOption('31'); // Nevada
  await page.locator('#field-postcode').fill('89101');
  // #field-id_country already defaults to United States (21) for this shop.

  await page.locator('button[name="confirm-addresses"]').click();
  await expect(page.locator('#checkout-addresses-step')).not.toHaveClass(/step--current/, { timeout: 30000 });
}

test('Vault IDOR: shopper B cannot pay with shopper A\'s saved card', async ({ page, browser }) => {
  const shot = ck.shotter('11-vault-idor');

  // --- Shopper A saves a card. ------------------------------------------
  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless, { save: true });
  await ck.acceptTerms(page);
  await ck.placeOrder(page);
  const aRef = await ck.expectConfirmed(page);
  expect(aRef).toBeTruthy();
  await shot(page, 'shopper-a-order-confirmed');
  console.log('IDOR_SHOPPER_A_ORDER=' + aRef);

  const idCustomerA = queryDb(`SELECT id_customer FROM ps_customer WHERE email='${SHOPPER_A.email}'`);
  const cardRow = queryDb(
    `SELECT id_inovio_stored_card FROM ps_inovio_stored_card WHERE id_customer=${idCustomerA} ORDER BY id_inovio_stored_card DESC LIMIT 1`
  );
  const idCardA = Number(cardRow);
  expect(idCardA).toBeGreaterThan(0);
  console.log('IDOR_SHOPPER_A_CARD_ID=' + idCardA);

  // Baseline: count orders/transactions before the attack attempt, so we
  // can prove nothing new got charged against A's card.

  // --- Shopper B: separate browser context (own cookies/session). -------
  const contextB = await browser.newContext();
  const pageB = await contextB.newPage();
  const shotB = shot; // same evidence dir, shared numbering via closure below

  try {
    await registerOrLoginShopperB(pageB);
    await shotB(pageB, 'shopper-b-logged-in');

    const idCustomerB = queryDb(`SELECT id_customer FROM ps_customer WHERE email='${SHOPPER_B.email}'`);
    expect(Number(idCustomerB)).toBeGreaterThan(0);
    expect(Number(idCustomerB)).not.toBe(Number(idCustomerA));

    // B's cart — the one the attack is attempted against. Scoping the final
    // assertion to this cart keeps it meaningful on a shop shared with the
    // rest of the suite, where other specs create orders concurrently.
    const idCartB = queryDb(
      `SELECT IFNULL(MAX(id_cart),0) FROM ps_cart WHERE id_customer=${Number(idCustomerB)}`
    );

    await ck.emptyCart(pageB);
    await ck.addProduct(pageB);

    // A brand-new shopper has no delivery address on file yet; fill it once
    // through the real UI (idempotent on re-runs, see ensureAddress above).
    await ensureAddress(pageB);
    await shotB(pageB, 'shopper-b-address-ready');

    await ck.toPaymentStep(pageB);
    await ck.selectInovio(pageB);
    await shotB(pageB, 'shopper-b-at-payment-step');

    // Shopper B has no saved cards of their own, so the saved-card radio
    // block does not render at all (see payment_form.tpl:
    // {if $inovioVaultActive && $inovioSavedCards|@count > 0}). The
    // tampering: inject that exact radio into B's OWN form/DOM, checked,
    // with shopper A's stored card id as its value — simulating a
    // malicious shopper editing their own page before submitting.
    const injectedId = await pageB.evaluate((cardId) => {
      const form = document.getElementById('inovio-payment-form');
      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'inovio_saved_card_id';
      radio.value = String(cardId);
      radio.checked = true;
      radio.id = 'inovio-idor-injected-radio';
      form.appendChild(radio);

      return radio.value;
    }, idCardA);
    expect(injectedId).toBe(String(idCardA));
    await shotB(pageB, 'tampered-saved-card-radio-injected');

    await ck.acceptTerms(pageB);
    await ck.placeOrder(pageB);

    // Give the server round-trip time to complete (redirect back to
    // checkout with an error, NOT to order-confirmation).
    await pageB.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await shotB(pageB, 'after-place-order-attempt');

    const onConfirmation = /order-confirmation/.test(pageB.url());

    if (onConfirmation) {
      // This must never happen. Capture everything and fail loudly.
      await shotB(pageB, 'CRITICAL-idor-order-confirmed');
      const body = await pageB.locator('body').innerText();
      console.log('CRITICAL SECURITY BUG: shopper B was able to pay using shopper A\'s ' +
        'saved card (id=' + idCardA + '). Confirmation page body follows:\n' + body);
    }

    expect(onConfirmation, 'Shopper B must NOT reach order-confirmation using shopper A\'s saved card').toBe(false);

    // The refusal must surface as a visible error to the shopper, back on
    // the checkout page.
    await expect(pageB.locator('body')).toContainText(/could not be processed|error|try again/i);
    await shotB(pageB, 'shopper-b-refused-with-error');

    // No order must exist for the cart B attacked with.
    //
    // NOTE: do NOT assert on a global COUNT(*) of ps_orders here. This shop is
    // shared with the rest of the suite, so other specs legitimately create
    // orders while this one runs — a global count is flaky by construction and
    // says nothing about whether the attack succeeded. Scope it to B instead.
    const ordersForAttackedCart = queryDb(
      `SELECT COUNT(*) FROM ps_orders WHERE id_cart=${Number(idCartB)}`
    );
    expect(ordersForAttackedCart, 'no order may exist for the attacked cart').toBe('0');

    // And specifically: no order in the system should have been placed by
    // shopper B (defence in depth beyond the cart-scoped check above).
    const idCustomerBNum = Number(idCustomerB);
    const bOrders = queryDb(`SELECT COUNT(*) FROM ps_orders WHERE id_customer=${idCustomerBNum}`);
    expect(bOrders).toBe('0');

    console.log('IDOR_RESULT=refused-as-expected');
  } finally {
    await contextB.close();
  }
});
