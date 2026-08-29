/**
 * Back-office driver. Same rule as the storefront: real clicks only.
 */
import { expect } from '@playwright/test';

export const ADMIN = {
  email: 'admin@inovio.local',
  password: 'InovioTest123!',
};

/** The admin folder is randomised at install; discover it once. */
export async function adminUrl(page, adminDir) {
  return `/${adminDir}`;
}

export async function loginAdmin(page, adminDir) {
  await page.goto(`/${adminDir}`);

  // Already signed in? PS9 drops us straight into the dashboard, and the
  // login form is absent — calling this twice in one spec must be harmless.
  if (!(await page.locator('input[name="email"]').count())) {
    return;
  }

  await page.goto(`/${adminDir}`);
  await page.fill('input[name="email"]', ADMIN.email);
  await page.fill('input[name="passwd"]', ADMIN.password);
  // PS9's Symfony-based login page has no button[name="submitLogin"] (that's
  // legacy PS1.6). The real submit button, verified live, is
  // button[name="submit_login"] (text "Log in").
  await page.click('button[name="submit_login"]');
  await expect(page.locator('body')).toContainText(/Dashboard|Orders/i, { timeout: 30000 });
}

/** Open an order's detail page by its reference. */
export async function openOrder(page, adminDir, reference) {
  // PS9's AdminOrders is a Symfony grid controller; index.php?controller=AdminOrders
  // has no security token and bounces to an "invalid token" page. Navigate
  // via the real back-office left nav instead.
  await page.getByRole('link', { name: 'Orders', exact: true }).first().click();
  await page.waitForLoadState('networkidle');

  // The grid's reference filter is order[reference]; submission requires the
  // grid's real search button (button[name="order[actions][search]"]) --
  // pressing Enter alone does not reliably trigger the server-side filter.
  await page.fill('input[name="order[reference]"]', reference);
  await page.click('button[name="order[actions][search]"]');
  await page.waitForLoadState('networkidle');

  // Once filtered down to the single matching row, the reference cell
  // (td.column-reference, class "clickable") is JS-wired to navigate to the
  // order's detail page (/sell/orders/{id}/view) - there is no <a> wrapping
  // the reference text itself.
  await page.locator('td.column-reference', { hasText: reference }).first().click();
  await expect(page.locator('body')).toContainText(reference);
}
