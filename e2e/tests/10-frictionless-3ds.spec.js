import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

const ADMIN_DIR = process.env.PS_ADMIN_DIR || 'admin6559ig9pytkodet9x6f';

/**
 * 3DS is active but the shopper's card is the ordinary approving PAN
 * (4111111111111111), not Cardinal's documented challenge PAN. This should
 * be a frictionless flow: the gateway resolves 3DS without a step-up, and
 * checkout sails straight through to confirmation — no ACS iframe ever
 * appears.
 *
 * 03-3ds.spec.js already covers the challenge path with the challenge PAN;
 * this is its frictionless counterpart, and confirms the two PANs actually
 * produce different behaviour under the same module setting.
 */

function queryDb(sql) {
  const out = execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "${sql}"`,
    { encoding: 'utf8' }
  );

  return out.trim();
}

// PrestaShop's HelperForm switch widget (helpers/form/form.tpl) ids the
// radio pair as "{input.name}_on" / "{input.name}_off" — the field's own
// name, not a custom widget id. The field name here is
// INOVIOPAYMENT_THREEDS_ACTIVE.
const THREEDS_FIELD = 'INOVIOPAYMENT_THREEDS_ACTIVE';

async function get3dsSwitch(page) {
  await page.goto(`/${ADMIN_DIR}/index.php?controller=AdminModules&configure=inoviopayment`);
  const on = await page.locator(`#${THREEDS_FIELD}_on`).isChecked();

  return on;
}

async function set3ds(page, enabled) {
  await page.goto(`/${ADMIN_DIR}/index.php?controller=AdminModules&configure=inoviopayment`);
  await page.locator(`#${THREEDS_FIELD}_${enabled ? 'on' : 'off'}`).check();
  await page.locator('button[name="submitinoviopayment"]').click();
  await expect(page.locator('body')).toContainText(/Settings updated|successful/i, { timeout: 30000 });
}

test('3DS active, frictionless PAN: no challenge, order confirms', async ({ page }) => {
  const shot = ck.shotter('10-frictionless-3ds');

  await admin.loginAdmin(page, ADMIN_DIR);
  const wasEnabled = await get3dsSwitch(page);
  console.log('THREEDS_ACTIVE_BEFORE=' + wasEnabled);
  await shot(page, 'admin-3ds-config-before');

  if (!wasEnabled) {
    await set3ds(page, true);
    await shot(page, 'admin-3ds-enabled');
  }

  try {
    // Watch for the ACS iframe ever becoming visible, across the whole
    // checkout — not just a point-in-time check after placing the order.
    let acsFrameSeen = false;
    page.on('frameattached', (frame) => {
      const url = frame.url();
      if (/creq|cardinal|centinelapi/i.test(url)) {
        acsFrameSeen = true;
      }
    });

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.toPaymentStep(page);
    await ck.selectInovio(page);
    await ck.fillCard(page, ck.CARDS.frictionless);
    await ck.acceptTerms(page);
    await shot(page, 'frictionless-pan-entered');

    await ck.placeOrder(page);

    const ref = await ck.expectConfirmed(page);
    await shot(page, 'confirmed-no-challenge');
    expect(ref).toBeTruthy();
    console.log('FRICTIONLESS_3DS_ORDER=' + ref);

    // The named challenge iframe must never have become visible.
    const challengeIframe = page.locator('iframe[name="inovio-3ds-challenge"]');
    const challengeCount = await challengeIframe.count();
    if (challengeCount > 0) {
      await expect(challengeIframe).not.toBeVisible();
    }
    expect(acsFrameSeen, 'no Cardinal ACS frame should ever have attached').toBe(false);

    // Report — do not assert a specific value — whether this order carries
    // an eci ref. Observed prior orders in this environment do carry
    // ref_key='eci' (e.g. value '05'), but that was under the CHALLENGE
    // path; report what the frictionless path actually produces.
    const idOrderRow = queryDb(`SELECT id_order FROM ps_orders WHERE reference='${ref}'`);
    const idOrder = Number(idOrderRow);
    console.log('FRICTIONLESS_3DS_ID_ORDER=' + idOrder);

    if (Number.isFinite(idOrder) && idOrder > 0) {
      const eciRows = queryDb(
        `SELECT ref_value FROM ps_inovio_order_ref WHERE id_order=${idOrder} AND ref_key='eci'`
      );
      const allRefs = queryDb(
        `SELECT ref_key, ref_value FROM ps_inovio_order_ref WHERE id_order=${idOrder} ORDER BY ref_key`
      );
      console.log('FRICTIONLESS_3DS_ECI_OBSERVED=' + JSON.stringify(eciRows));
      console.log('FRICTIONLESS_3DS_ALL_REFS=' + JSON.stringify(allRefs));
    }
  } finally {
    // Restore the original config value regardless of outcome.
    await admin.loginAdmin(page, ADMIN_DIR);
    const nowEnabled = await get3dsSwitch(page);
    if (nowEnabled !== wasEnabled) {
      await set3ds(page, wasEnabled);
      await shot(page, 'admin-3ds-restored');
    }
    const finalState = await get3dsSwitch(page);
    console.log('THREEDS_ACTIVE_RESTORED_TO=' + finalState);
  }
});
