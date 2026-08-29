import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

/**
 * Vaulting: save a card at checkout, then pay with it on a second order
 * WITHOUT re-entering the PAN. The second order must never tokenize.
 */
test('Vault: save a card, then reuse it on a later order', async ({ page }) => {
  const shot = ck.shotter('02-vault');

  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless, { save: true });
  await ck.acceptTerms(page);
  await shot(page, 'save-card-checked');

  await ck.placeOrder(page);
  const first = await ck.expectConfirmed(page);
  await shot(page, 'first-order-confirmed');
  expect(first).toBeTruthy();

  // Second order: pay with the stored card.
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.toPaymentStep(page);
  await ck.selectInovio(page);

  const saved = page.locator('#inovio-payment-form [name="inovio_saved_card_id"]').first();
  await expect(saved).toBeVisible();
  await saved.check();
  await shot(page, 'saved-card-selected');

  await ck.acceptTerms(page);
  await ck.placeOrder(page);
  const second = await ck.expectConfirmed(page);
  await shot(page, 'reuse-confirmed');

  expect(second).toBeTruthy();
  expect(second).not.toBe(first);
  console.log('VAULT_ORDERS=' + first + ',' + second);
});

test('Vault: saved card is listed in the customer account', async ({ page }) => {
  const shot = ck.shotter('03-vault-account');

  await ck.login(page);
  await page.goto('/module/inoviopayment/storedcards');
  await expect(page.locator('body')).toContainText(/1111|saved card/i);
  await shot(page, 'saved-cards-page');
});
