import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';

/**
 * Vault: the shopper deletes a saved card from their account.
 *
 * The delete control on /module/inoviopayment/storedcards is CSRF-token
 * protected (see controllers/front/storedcards.php::postProcess) and fires
 * a native confirm() dialog before submitting, so this must be driven by a
 * real click on the real form — never a crafted POST.
 */

function queryDb(sql) {
  const out = execSync(
    `docker --context tensor exec ps-mysql mysql -uprestashop -pprestashop prestashop -N -e "${sql}"`,
    { encoding: 'utf8' }
  );

  return out.trim();
}

test('Vault: shopper deletes a saved card', async ({ page }) => {
  const shot = ck.shotter('09-vault-delete');

  // Save a card at checkout, as in 02-vault.
  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless, { save: true });
  await ck.acceptTerms(page);
  await shot(page, 'save-card-checked');

  await ck.placeOrder(page);
  const ref = await ck.expectConfirmed(page);
  expect(ref).toBeTruthy();
  await shot(page, 'order-placed');
  console.log('VAULT_DELETE_ORDER=' + ref);

  // Go to the saved-cards page and confirm the card is listed.
  await page.goto('/module/inoviopayment/storedcards');
  const row = page.locator('table.inovio-stored-cards-table tbody tr').first();
  await expect(row).toBeVisible();
  await expect(row).toContainText('1111');
  await shot(page, 'saved-cards-listed');

  // Read the row's stored card id from the delete form's hidden input, so we
  // can verify the DB row is really gone afterward — read-only, for
  // verification only.
  const idCardStr = await row.locator('input[name="id_inovio_stored_card"]').getAttribute('value');
  const idCard = Number(idCardStr);
  expect(idCard).toBeGreaterThan(0);

  const beforeCount = queryDb(
    `SELECT COUNT(*) FROM ps_inovio_stored_card WHERE id_inovio_stored_card=${idCard}`
  );
  expect(beforeCount).toBe('1');

  // Click the real delete control. It fires a native confirm() dialog
  // (onclick="return confirm(...)") — accept it, exactly as a shopper would.
  page.once('dialog', (dialog) => dialog.accept());
  await row.locator('button.inovio-delete-card').click();

  // The controller redirects back to the same page with ?inovio_deleted=1.
  await expect(page).toHaveURL(/inovio_deleted=1/);
  await expect(page.locator('body')).toContainText(/removed/i);
  await shot(page, 'after-delete');

  // The card must no longer appear in the list.
  const rowsAfter = page.locator('table.inovio-stored-cards-table tbody tr');
  const remaining = await rowsAfter.count();
  for (let i = 0; i < remaining; i++) {
    await expect(rowsAfter.nth(i)).not.toContainText('1111');
  }
  await shot(page, 'card-gone-from-list');

  // Read-only DB verification that the row is really gone.
  const afterCount = queryDb(
    `SELECT COUNT(*) FROM ps_inovio_stored_card WHERE id_inovio_stored_card=${idCard}`
  );
  expect(afterCount).toBe('0');

  console.log('VAULT_DELETE_CARD_ID=' + idCard);
});
