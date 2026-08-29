# Inovio Payment Gateway — PrestaShop 9

Tokenized direct-post card checkout for PrestaShop 9, on the Inovio gateway.
**The card number never reaches the shop server.**

Design: [`docs/prestashop-integration-design.md`](../../../docs/prestashop-integration-design.md)

## Requirements

- PrestaShop **9.0+** (9.x only — see D5; PrestaShop 8.x caps at PHP 8.1 and 1.7 is EOL)
- PHP **8.1+** with `bcmath`, `curl`, `json`

`bcmath` is required, not optional: the SDK's `Money` does decimal arithmetic
through it so amounts never touch a binary float. Install fails without it.

## What it does

| | |
|---|---|
| Sale / Authorize | configurable payment action |
| Capture | full and partial, from the order page |
| Void | pre-settlement reversal |
| Refund | full and partial, settlement-aware (see below) |
| Saved cards | own table + customer-account UI (PrestaShop has no vault API) |
| 3D Secure | DDC + challenge, with a module-created pending order state |
| Timeout recovery | reconciles via `status()` before failing an order |

### PCI posture

Card fields live in the checkout DOM; the shipped JS exchanges the PAN for a
single-use token **directly against Inovio** and only the token reaches this
server. Posture ≈ **SAQ A-EP**, with no Inovio-side infrastructure.

Verified on a live stack: a full `mysqldump` of the shop database contains
**zero** occurrences of a submitted PAN. `ps_order_payment.card_number` holds
last-4 only — never a full PAN.

### Refunds are settlement-aware

The gateway rejects a refund on an unsettled order with
`SERVICE 536 "Order not settled: Please reverse"`. Before settlement the correct
undo is a reversal, not a credit. `InovioGateway::refundOrder()` checks
settlement and picks the right verb, falling back to a reversal if a refund
still returns 536.

## Install

1. Copy `inoviopayment/` into `modules/`, or upload the ZIP.
2. Install: `php bin/console prestashop:module install inoviopayment`
3. Configure under **Payment → Inovio Payment Gateway**.

### Configuration

Required: API username, API password, Site ID, **Site Key**, Gateway Product ID.

The **Site Key** is a per-site HMAC secret issued by Inovio support — it is not
the API password, and browser tokenization fails with error 121 without it.
The **Gateway Product ID** (`LI_PROD_ID`) is a gateway-registered product, not a
PrestaShop SKU; the whole order bills as one line item under it.

## Layout

```
inoviopayment.php              main class (PaymentModule)
classes/
  InovioGateway.php            SDK requests + transaction verbs
  InovioStoredCard.php         vault ObjectModel
  InovioVault.php              vault write path
controllers/front/
  signature.php                HMAC for browser tokenization (CSRF + cart-bound + rate-limited)
  validation.php               order placement
  threeds.php                  3DS prepare (AJAX)
  threedsreturn.php            ACS return (CSRF-exempt, TransactionId-authenticated)
  storedcards.php              saved-cards management
views/js/inovio-checkout.js    direct-post + 3DS checkout JS
vendor/inovio/gateway-sdk/     vendored SDK (Addons requires a self-contained ZIP)
tests/                         e2e scripts (see below)
```

## Tests

The e2e scripts need a booted Symfony kernel, so they run through a small
runner rather than bare CLI:

```php
// /tmp/runner.php
require_once "/var/www/html/vendor/autoload.php";
require_once "/var/www/html/config/config.inc.php";
$kernel = new FrontKernel("dev", true);
$kernel->boot();
global $kernel;
require "/var/www/html/modules/inoviopayment/tests/e2e_module.php";
```

- `tests/e2e_module.php` — sale → order → vault → refund through module code
- `tests/e2e_module_verbs.php` — authorize/capture/void/partial-capture/saved-card
- `tests/sdk_verbs.php` — raw SDK verbs; deliberately exercises the 536 path

A local stack lives at `stack/prestashop/docker-compose.yml`.

## Known gaps

- The checkout JS has not been exercised in a real browser yet (PrestaShop has
  no order-submission event, so it intercepts the form's own submit — that path
  needs a browser pass, plus a theme-compatibility matrix).
- 3DS challenge completion needs a browser and an HTTPS-reachable return URL.
- Whether deleting a saved card should also revoke the gateway-side payment
  method is open (design doc §11).
