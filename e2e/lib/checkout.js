/**
 * Shared storefront driver.
 *
 * Everything here goes through the real UI — click the product, click through
 * the checkout steps, type into the card fields, click Place Order. No
 * database writes, no direct calls into the module, no POSTing to our own
 * controllers. If a step can't be done as a shopper, the test fails.
 */
import { expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export const SHOPPER = {
  email: 'shopper@inovio.local',
  password: 'ShopTest123!',
};

export const CARDS = {
  // Approves without a challenge.
  frictionless: '4111111111111111',
  // Cardinal's documented challenge PAN — triggers the ACS step-up.
  challenge: '4000000000002503',
};

const EVIDENCE = path.resolve('evidence');

/** Numbered, per-case screenshots so the run reads as a story. */
export function shotter(caseName) {
  const dir = path.join(EVIDENCE, caseName);
  fs.mkdirSync(dir, { recursive: true });
  let n = 0;

  return async (page, label) => {
    n += 1;
    const file = path.join(dir, `${String(n).padStart(2, '0')}-${label}.png`);
    /*
     * Full-page, but capped.
     *
     * PrestaShop's back office in dev mode renders pages up to ~147,000px
     * tall (the debug bar dumps every query), which trips Pillow's
     * decompression-bomb guard and, more importantly, makes the screenshot
     * useless as evidence — the actual UI is a sliver at the top. Clip to a
     * generous viewport-multiple instead so the shot stays readable.
     */
    const MAX_SHOT_HEIGHT = 4000;
    const height = await page.evaluate(
      () => document.documentElement.scrollHeight
    ).catch(() => MAX_SHOT_HEIGHT);

    if (height > MAX_SHOT_HEIGHT) {
      const width = page.viewportSize()?.width ?? 1280;
      await page.screenshot({
        path: file,
        clip: {x: 0, y: 0, width, height: MAX_SHOT_HEIGHT},
      });
    } else {
      await page.screenshot({ path: file, fullPage: true });
    }

    return file;
  };
}

export async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="email"]', SHOPPER.email);
  await page.fill('input[name="password"]', SHOPPER.password);
  await page.click('#submit-login');
  // Successful login redirects to "/". The header's account menu button
  // (id="userMenuButton") is the unique post-login indicator — the theme
  // also renders 2-3 separate "my-account" links (desktop dropdown, mobile
  // icon, nofollow variant) which trip Playwright's strict mode if matched
  // together.
  await expect(page.locator('#userMenuButton')).toBeVisible();
}

/** Empty the cart so each case starts from a known state. */
export async function emptyCart(page) {
  await page.goto('/cart?action=show');
  const removes = page.locator('a.remove-from-cart');
  for (let i = await removes.count(); i > 0; i--) {
    await removes.first().click();
    await page.waitForTimeout(1200);
  }
}

export async function addProduct(page) {
  await page.goto('/2-home');
  await page.locator('button[data-button-action="add-to-cart"]').first().click();
  // The theme opens a modal; wait for the cart to actually reflect the add.
  await page.waitForTimeout(2500);
  await page.goto('/cart?action=show');
  await expect(page.locator('.cart-item, .cart-overview')).toBeVisible();
}

/**
 * Walk checkout to the payment step. Each step is a real click, and we wait
 * for the next step to become current rather than sleeping.
 */
export async function toPaymentStep(page) {
  await page.goto('/order');

  const steps = ['checkout-personal-information-step',
                 'checkout-addresses-step',
                 'checkout-delivery-step'];

  for (const step of steps) {
    const section = page.locator(`#${step}`);
    if (await isCurrent(page, step)) {
      // Each step's real "Continue" control is a type="submit" button; the
      // theme also leaves a hidden legacy `.ps-hidden-by-js` submit in the
      // DOM (a no-JS fallback) and a type="button" "Back" control — both
      // would match a bare `button[type="submit"]` selector or an unfiltered
      // `button`, so we require visibility explicitly.
      const submit = section.locator('button[type="submit"]:visible, input[type="submit"]:visible').first();
      await expect(submit).toBeVisible();
      await submit.click();
      // Wait for this step to hand off "current" to the next one rather
      // than sleeping a fixed amount.
      await expect(section).not.toHaveClass(/step--current/, { timeout: 30000 });
    }
  }

  await expect(page.locator('#checkout-payment-step')).toHaveClass(/step--current/, { timeout: 30000 });
}

async function isCurrent(page, id) {
  return page.locator(`#${id}.step--current`).count().then((c) => c > 0);
}

/** Select the Inovio payment option (index-based wrapper id, per PS9). */
export async function selectInovio(page) {
  const radios = page.locator('input[type=radio][name="payment-option"]');
  const count = await radios.count();

  for (let i = 0; i < count; i++) {
    const id = await radios.nth(i).getAttribute('id');
    if (await page.locator(`#pay-with-${id}-form #inovio-payment-form`).count()) {
      await radios.nth(i).check();
      await expect(page.locator('#inovio-payment-form')).toBeVisible();

      return id;
    }
  }
  throw new Error('Inovio payment option not offered at checkout');
}

/** Type the card exactly as a shopper does. */
export async function fillCard(page, pan, { save = false, month = '12', year = '2030', cvv = '123' } = {}) {
  const form = page.locator('#inovio-payment-form');
  await form.locator('#inovio_card_number').fill(pan);
  await form.locator('[name="inovio_exp_month"]').selectOption(month);
  await form.locator('[name="inovio_exp_year"]').selectOption(year);
  await form.locator('#inovio_cvv').fill(cvv);

  if (save) {
    await form.locator('[name="inovio_save_card_input"]').check();
  }
}

export async function acceptTerms(page) {
  const terms = page.locator('#conditions-to-approve input[type="checkbox"]').first();
  if (await terms.count() && !(await terms.isChecked())) {
    await terms.check();
  }
}

export async function placeOrder(page) {
  await page.locator('#payment-confirmation button[type="submit"]').first().click();
}

/** Assert we landed on a confirmed order, and return its reference. */
export async function expectConfirmed(page) {
  await expect(page).toHaveURL(/order-confirmation/, { timeout: 60000 });
  // The confirmation banner is `.alert-success[role="alert"]` with an
  // `h1.page-title-section` reading "Your order is confirmed" — the
  // `#content-hook_order_confirmation`/`.card-block` selectors this used to
  // use don't exist on this theme's confirmation page.
  await expect(page.locator('.alert-success[role="alert"]')).toContainText(
    /confirmed|Your order/i
  );
  const body = await page.locator('body').innerText();
  const ref = body.match(/Order reference:\s*([A-Z0-9]{6,})/);

  return ref ? ref[1] : null;
}
