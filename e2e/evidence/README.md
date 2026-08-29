# PrestaShop module — e2e evidence

Generated: 2026-08-29 09:05:33

Every screenshot below was produced by Playwright driving the real
storefront and back office as a shopper/merchant would: navigating,
clicking, typing, and submitting. No database writes, no direct calls
into the module, no POSTs to its own controllers.

## 01-sale

### 01-cart

![01-cart](01-sale/01-cart.png)

### 02-payment-step

![02-payment-step](01-sale/02-payment-step.png)

### 03-card-entered

![03-card-entered](01-sale/03-card-entered.png)

### 04-confirmed

![04-confirmed](01-sale/04-confirmed.png)

## 02-vault

### 01-save-card-checked

![01-save-card-checked](02-vault/01-save-card-checked.png)

### 02-first-order-confirmed

![02-first-order-confirmed](02-vault/02-first-order-confirmed.png)

### 03-saved-card-selected

![03-saved-card-selected](02-vault/03-saved-card-selected.png)

### 04-reuse-confirmed

![04-reuse-confirmed](02-vault/04-reuse-confirmed.png)

## 03-vault-account

### 01-saved-cards-page

![01-saved-cards-page](03-vault-account/01-saved-cards-page.png)

## 04-3ds

### 01-challenge-pan-entered

![01-challenge-pan-entered](04-3ds/01-challenge-pan-entered.png)

### 02-after-place-order

![02-after-place-order](04-3ds/02-after-place-order.png)

### 03-acs-challenge-visible

![03-acs-challenge-visible](04-3ds/03-acs-challenge-visible.png)

### 04-otp-entered

![04-otp-entered](04-3ds/04-otp-entered.png)

### 05-confirmed-after-challenge

![05-confirmed-after-challenge](04-3ds/05-confirmed-after-challenge.png)

## 05-backoffice

### 01-order-placed

![01-order-placed](05-backoffice/01-order-placed.png)

### 02-admin-dashboard

![02-admin-dashboard](05-backoffice/02-admin-dashboard.png)

### 03-admin-order-detail

![03-admin-order-detail](05-backoffice/03-admin-order-detail.png)

### 04-inovio-panel

![04-inovio-panel](05-backoffice/04-inovio-panel.png)

### 05-inovio-panel-closeup

![05-inovio-panel-closeup](05-backoffice/05-inovio-panel-closeup.png)

## 06-authorize-capture

### 01-payment-action-authorize

![01-payment-action-authorize](06-authorize-capture/01-payment-action-authorize.png)

### 02-authorized-order-confirmed

![02-authorized-order-confirmed](06-authorize-capture/02-authorized-order-confirmed.png)

### 03-order-awaiting-capture

![03-order-awaiting-capture](06-authorize-capture/03-order-awaiting-capture.png)

### 04-after-capture

![04-after-capture](06-authorize-capture/04-after-capture.png)

## 07-void

### 01-payment-action-authorize

![01-payment-action-authorize](07-void/01-payment-action-authorize.png)

### 02-authorized-order-confirmed

![02-authorized-order-confirmed](07-void/02-authorized-order-confirmed.png)

### 03-order-awaiting-capture

![03-order-awaiting-capture](07-void/03-order-awaiting-capture.png)

### 04-after-void

![04-after-void](07-void/04-after-void.png)

## 08-decline

### 01-cart-at-trigger-total

![01-cart-at-trigger-total](08-decline/01-cart-at-trigger-total.png)

### 02-card-entered

![02-card-entered](08-decline/02-card-entered.png)

### 03-decline-message

![03-decline-message](08-decline/03-decline-message.png)

## 08-refund

### 01-order-placed

![01-order-placed](08-refund/01-order-placed.png)

### 02-admin-order-detail

![02-admin-order-detail](08-refund/02-admin-order-detail.png)

### 03-refund-mode-opened

![03-refund-mode-opened](08-refund/03-refund-mode-opened.png)

### 04-product-selected-for-refund

![04-product-selected-for-refund](08-refund/04-product-selected-for-refund.png)

### 05-after-refund-submit

![05-after-refund-submit](08-refund/05-after-refund-submit.png)

### 06-refund-recorded

![06-refund-recorded](08-refund/06-refund-recorded.png)

## 09-vault-delete

### 01-save-card-checked

![01-save-card-checked](09-vault-delete/01-save-card-checked.png)

### 02-order-placed

![02-order-placed](09-vault-delete/02-order-placed.png)

### 03-saved-cards-listed

![03-saved-cards-listed](09-vault-delete/03-saved-cards-listed.png)

### 04-after-delete

![04-after-delete](09-vault-delete/04-after-delete.png)

### 05-card-gone-from-list

![05-card-gone-from-list](09-vault-delete/05-card-gone-from-list.png)

## 10-frictionless-3ds

### 01-admin-3ds-config-before

![01-admin-3ds-config-before](10-frictionless-3ds/01-admin-3ds-config-before.png)

### 02-frictionless-pan-entered

![02-frictionless-pan-entered](10-frictionless-3ds/02-frictionless-pan-entered.png)

### 03-confirmed-no-challenge

![03-confirmed-no-challenge](10-frictionless-3ds/03-confirmed-no-challenge.png)

## 11-vault-idor

### 01-shopper-a-order-confirmed

![01-shopper-a-order-confirmed](11-vault-idor/01-shopper-a-order-confirmed.png)

### 02-shopper-b-logged-in

![02-shopper-b-logged-in](11-vault-idor/02-shopper-b-logged-in.png)

### 03-shopper-b-address-ready

![03-shopper-b-address-ready](11-vault-idor/03-shopper-b-address-ready.png)

### 04-shopper-b-at-payment-step

![04-shopper-b-at-payment-step](11-vault-idor/04-shopper-b-at-payment-step.png)

### 05-tampered-saved-card-radio-injected

![05-tampered-saved-card-radio-injected](11-vault-idor/05-tampered-saved-card-radio-injected.png)

### 06-after-place-order-attempt

![06-after-place-order-attempt](11-vault-idor/06-after-place-order-attempt.png)

### 07-shopper-b-refused-with-error

![07-shopper-b-refused-with-error](11-vault-idor/07-shopper-b-refused-with-error.png)

## 12-invalid-card-input-cvv

### 01-short-cvv-submitted

![01-short-cvv-submitted](12-invalid-card-input-cvv/01-short-cvv-submitted.png)

### 02-short-cvv-error-shown

![02-short-cvv-error-shown](12-invalid-card-input-cvv/02-short-cvv-error-shown.png)

## 12-invalid-card-input-expiry

### 01-past-expiry-submitted

![01-past-expiry-submitted](12-invalid-card-input-expiry/01-past-expiry-submitted.png)

### 02-past-expiry-error-shown

![02-past-expiry-error-shown](12-invalid-card-input-expiry/02-past-expiry-error-shown.png)

## 12-invalid-card-input-luhn

### 01-luhn-fail-submitted

![01-luhn-fail-submitted](12-invalid-card-input-luhn/01-luhn-fail-submitted.png)

### 02-luhn-fail-error-shown

![02-luhn-fail-error-shown](12-invalid-card-input-luhn/02-luhn-fail-error-shown.png)

## 12-invalid-card-input-short-pan

### 01-short-pan-submitted

![01-short-pan-submitted](12-invalid-card-input-short-pan/01-short-pan-submitted.png)

### 02-short-pan-error-shown

![02-short-pan-error-shown](12-invalid-card-input-short-pan/02-short-pan-error-shown.png)

## 13-partial-capture

### 01-payment-action-authorize

![01-payment-action-authorize](13-partial-capture/01-payment-action-authorize.png)

### 02-authorized-order-confirmed

![02-authorized-order-confirmed](13-partial-capture/02-authorized-order-confirmed.png)

### 03-order-awaiting-capture

![03-order-awaiting-capture](13-partial-capture/03-order-awaiting-capture.png)

### 04-partial-amount-entered

![04-partial-amount-entered](13-partial-capture/04-partial-amount-entered.png)

### 05-after-partial-capture

![05-after-partial-capture](13-partial-capture/05-after-partial-capture.png)

## 14-partial-refund

### 01-cart-quantity-two

![01-cart-quantity-two](14-partial-refund/01-cart-quantity-two.png)

### 02-cart-detail

![02-cart-detail](14-partial-refund/02-cart-detail.png)

### 03-order-placed

![03-order-placed](14-partial-refund/03-order-placed.png)

### 04-admin-order-detail

![04-admin-order-detail](14-partial-refund/04-admin-order-detail.png)

### 05-refund-mode-opened

![05-refund-mode-opened](14-partial-refund/05-refund-mode-opened.png)

### 06-partial-quantity-selected

![06-partial-quantity-selected](14-partial-refund/06-partial-quantity-selected.png)

### 07-after-partial-refund-submit

![07-after-partial-refund-submit](14-partial-refund/07-after-partial-refund-submit.png)

### 08-partial-refund-recorded

![08-partial-refund-recorded](14-partial-refund/08-partial-refund-recorded.png)

