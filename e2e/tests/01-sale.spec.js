import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

test('Sale: card checkout approves and confirms', async ({ page }) => {
  const shot = ck.shotter('01-sale');

  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await shot(page, 'cart');

  await ck.toPaymentStep(page);
  await shot(page, 'payment-step');

  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless);
  await ck.acceptTerms(page);
  await shot(page, 'card-entered');

  await ck.placeOrder(page);
  const ref = await ck.expectConfirmed(page);
  await shot(page, 'confirmed');

  expect(ref).toBeTruthy();
  console.log('ORDER_REFERENCE=' + ref);
});
