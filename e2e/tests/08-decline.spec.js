import { test } from '@playwright/test';

/**
 * A declined card must NOT create a confirmed order: the shopper should
 * stay on checkout and see an error. We looked for a reliable way to make
 * the sandbox actually decline a transaction and could not find one — so
 * this spec is an honest, documented skip rather than a test dressed up to
 * pass against a fake decline.
 *
 * What was tried, all against the real gateway used by this stack
 * (INOVIOPAYMENT_ENDPOINT = http://192.168.86.188:8080/payment/pmt_service.cfm,
 * merch acct 1602 — a local CFML stub, not a live processor):
 *
 *  - inoviopayment/tests/sdk_verbs.php:78 comments "// 7. DECLINE path"
 *    using amount "0.05". Run live via the raw SDK
 *    (`docker --context tensor exec prestashop php
 *      /var/www/html/modules/inoviopayment/tests/sdk_verbs.php`):
 *    returned APPROVED, not DECLINED. That convention does not hold here.
 *  - Common industry "magic decline" test PANs (4000000000000002,
 *    4000000000009995, 4000000000000101, 4000300011112220) — all APPROVED.
 *  - An extreme amount ($9,999.00) — APPROVED.
 *  - A syntactically valid but already-expired card expiry (01/2020) —
 *    still tokenized and still APPROVED at sale().
 *  - Searched the wider inoviov2 workspace (docs, PROGRESS.md, the SDK's
 *    conformance fixtures, the CFML payment gateway repo) for any
 *    documented Inovio-specific decline-simulation convention: none found.
 *    The SDK's decline/* conformance fixtures are mocked HTTP responses for
 *    unit-testing the SDK's own parsing logic, not inputs this live sandbox
 *    responds to.
 *
 * Conclusion: INOVIOPAYMENT_ENDPOINT in this environment is a permissive
 * stub that approves every transaction regardless of PAN, amount, or
 * expiry — there is no known input that reliably produces a DECLINED
 * response here. Rather than fabricate a decline (e.g. by forcing an error
 * through a malformed field, which would test form validation, not a
 * payment decline), this spec is left as a documented skip. Wiring up a
 * true decline test requires either: (a) a sandbox/mock processor that
 * actually implements decline simulation codes, or (b) confirmation from
 * Inovio of a magic PAN/amount for this merchant account.
 */
test.skip(
  'Declined card does not create a confirmed order',
  () => {}
);
