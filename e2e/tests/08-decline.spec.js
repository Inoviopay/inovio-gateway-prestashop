import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * A declined card must NOT create a confirmed order: the shopper should stay
 * on checkout and see an error, and no order row should exist for that cart.
 *
 * How the decline is actually triggered (verified live): merch_acct 1602
 * routes to proc_id 10 ("Test Processor", driver testbank_drv), a built-in
 * simulator. It decides approve/decline purely from the whole transaction
 * AMOUNT, matched exactly against a fixed list of trigger values in
 * repos/payment/SideA/WEB-INF/customtags/testbank.cfc:284 — PAN, expiry and
 * CVV are never consulted. The processor response code is the amount with
 * the decimal point removed (e.g. 6.35 -> "635"), and the module surfaces
 * the processor's advice text as the checkout error.
 *
 * The module sends $cart->getOrderTotal(true, Cart::BOTH) as the amount
 * (InovioGateway.php buildRequest()) — cart total tax-included, products
 * plus shipping. So this spec must make the STOREFRONT CART TOTAL land on
 * exactly one of the trigger values. It does this the same way a merchant
 * would: through the real back-office UI, not the database.
 *
 * Trigger chosen: $6.35 ("Insufficient Funds", processor code 635).
 *
 * How $6.35 is reached, both steps done live through the admin UI:
 *  1. Product #12 ("Mountain fox - Vector graphics") price is temporarily
 *     set to $6.35 tax-excl. via Catalog > Products > 12 > Pricing. The
 *     shopper's default address is in Nevada; the shop's only tax rule
 *     group that touches product #12 is scoped to Florida only, so NV buys
 *     at $6.35 with $0.00 tax — confirmed live (storefront showed
 *     "Total (tax incl.) $6.35", "Taxes: $0.00").
 *  2. "My carrier" (the only carrier this US/NV address is offered — the
 *     store's other carrier, "Click and collect", is zoned to Europe only
 *     and never appears at checkout for this address) is temporarily
 *     switched to free shipping via Shipping > Carriers > My carrier >
 *     Shipping locations and costs > is_free = Yes. Confirmed live:
 *     checkout showed "Shipping: Free".
 * With both in place the payment step showed Subtotal $6.35 / Shipping
 * Free / Total (tax incl.) $6.35 — exactly the trigger amount, reached
 * entirely through real storefront/back-office pages. No database writes,
 * no module PHP calls, no controller POSTs were used to force this; the
 * only DB access in this spec is the read-only verification steps below.
 *
 * PrestaShop's carrier editor never updates a carrier row in place — saving
 * always soft-deletes the old row and inserts a new one (its id changes).
 * Teardown here follows the same real-UI path forward rather than trying to
 * resurrect the original row id, and leaves the merchant-visible
 * configuration (name, zone prices, free-shipping flag) exactly as found.
 */

const TRIGGER_PRODUCT_ID = 12;
const TRIGGER_PRODUCT_URL = '/12-mountain-fox-vector-graphics.html';
const TRIGGER_PRICE = '6.35';
const ORIGINAL_PRICE = '9.00';
const TRIGGER_TOTAL = '$6.35';
const EXPECTED_ADVICE = /insufficient funds/i;

function queryDb(sql) {
  const out = execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "${sql}"`,
    { encoding: 'utf8' }
  );

  return out.trim();
}

/** Catalog > Products > {id} > Pricing > retail price (tax excl.). */
async function setProductPrice(page, productId, price) {
  await page.goto(`/${ADMIN_DIR}/sell/catalog/products/${productId}`);
  await page.getByRole('link', { name: /I understand the risk/i }).click().catch(() => {});
  await page.waitForLoadState('networkidle');
  // The Symfony debug toolbar overlaps the save button on this dev instance
  // and intercepts the click; it's dev-only chrome, not part of the
  // merchant workflow, so hiding it doesn't affect what gets submitted.
  await page.addStyleTag({ content: '.sf-toolbar { display: none !important; }' });

  await page.getByText('Pricing', { exact: true }).click();
  await page.locator('#product_pricing_retail_price_price_tax_excluded').fill(price);
  await page.locator('#product_pricing_retail_price_price_tax_excluded').press('Tab');
  await page.getByRole('button', { name: 'Save and publish' }).click();
  await page.waitForLoadState('networkidle');
}

/** Shipping > Carriers > My carrier > Shipping locations and costs > is_free. */
async function setCarrierFree(page, free) {
  await page.goto(`/${ADMIN_DIR}/improve/shipping/carriers/`);
  await page.getByRole('link', { name: /I understand the risk/i }).click().catch(() => {});
  await page.waitForLoadState('networkidle');
  await page.locator('tr', { hasText: 'My carrier' }).getByRole('link', { name: 'edit' }).click();
  await page.waitForLoadState('networkidle');

  await page.getByText('Shipping locations and costs', { exact: true }).click();
  // The is_free control is a CSS-styled switch whose native radio input is
  // visually covered by its own decorative label — a real click on the
  // switch toggles this same input, so checking it directly (force, since
  // Playwright sees the input itself as covered) reproduces exactly what a
  // merchant clicking the switch achieves.
  const id = free ? '#carrier_shipping_settings_is_free_1' : '#carrier_shipping_settings_is_free_0';
  await page.locator(id).check({ force: true });
  await page.getByRole('button', { name: 'Save' }).click();
  await page.waitForLoadState('networkidle');
}

test.describe('Decline', () => {
  test.afterEach(async ({ page }) => {
    // Always leave the shop as found, even if an assertion above failed.
    await setProductPrice(page, TRIGGER_PRODUCT_ID, ORIGINAL_PRICE);
    await setCarrierFree(page, false);
  });

  test('Declined card does not create a confirmed order', async ({ page }) => {
    const shot = ck.shotter('08-decline');

    // --- Fixture setup: engineer a $6.35 cart total through the real UI ---
    await admin.loginAdmin(page, ADMIN_DIR);
    await setProductPrice(page, TRIGGER_PRODUCT_ID, TRIGGER_PRICE);
    await setCarrierFree(page, true);

    // --- Shopper checks out with the trigger-priced product ---
    await ck.login(page);
    await ck.emptyCart(page);
    await page.goto(TRIGGER_PRODUCT_URL);
    await page.locator('button[data-button-action="add-to-cart"]').first().click();
    await page.waitForTimeout(2500);
    await page.goto('/cart?action=show');
    await shot(page, 'cart-at-trigger-total');

    await ck.toPaymentStep(page);
    // Confirm the engineered total actually landed on the trigger amount
    // before spending the assertion on the decline itself.
    const paymentStepText = await page.locator('body').innerText();
    expect(paymentStepText).toContain(TRIGGER_TOTAL);

    await ck.selectInovio(page);
    await ck.fillCard(page, ck.CARDS.frictionless);
    await ck.acceptTerms(page);
    await shot(page, 'card-entered');

    await ck.placeOrder(page);
    await page.waitForLoadState('networkidle');
    await shot(page, 'decline-message');

    // Shopper is NOT taken to order-confirmation; still on checkout.
    await expect(page).not.toHaveURL(/order-confirmation/);
    await expect(page).toHaveURL(/\/order(\?|$)/);

    // Decline message is shown, with the processor's actual advice text.
    const notification = page.locator('#notifications .alert-danger');
    await expect(notification).toBeVisible();
    await expect(notification).toContainText(EXPECTED_ADVICE);

    // --- No confirmed/paid order was created for this cart ---
    const cartId = queryDb(
      "SELECT c.id_cart FROM ps_cart c JOIN ps_customer cu ON cu.id_customer = c.id_customer " +
      "WHERE cu.email = 'shopper@inovio.local' ORDER BY c.date_upd DESC LIMIT 1"
    );
    expect(cartId).toMatch(/^\d+$/);

    const orderCount = queryDb(`SELECT COUNT(*) FROM ps_orders WHERE id_cart = ${cartId}`);
    expect(orderCount).toBe('0');

    // --- The gateway really declined: module logged the decline path ---
    const logLines = queryDb(
      "SELECT message FROM ps_log WHERE message LIKE '%inovio%' ORDER BY id_log DESC LIMIT 5"
    );
    console.log('recent ps_log inovio lines:\n' + logLines);
    expect(logLines).toMatch(/declined cart \d+: Insufficient Funds/i);

    console.log('DECLINE_CART_ID=' + cartId);
  });
});
