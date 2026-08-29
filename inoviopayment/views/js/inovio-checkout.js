/**
 * Inovio direct-post checkout (PrestaShop 9).
 *
 * The card number lives only in this page and in the browser-direct POST to
 * Inovio's token service — it is never sent to the PrestaShop server. The
 * order is placed carrying the single-use TOKEN_GUID(s) instead.
 *
 * This is a port of the proven Magento 2 module
 * (view/frontend/web/js/view/payment/method-renderer/inovio-method.js).
 * The payment/tokenization/3DS logic is kept identical; only the framework
 * wrapper changes, because PrestaShop has no Knockout/RequireJS component
 * model and — critically — no JS event that fires on order submission.
 * Unlike Magento's placeOrder() override, here we intercept the payment
 * form's native `submit` event, run the async tokenize/3DS chain, write the
 * results into hidden inputs, and then resubmit the same form so PrestaShop's
 * own controller flow proceeds untouched.
 */
(function () {
    'use strict';

    /** @type {boolean} Guards against double-binding if this script is somehow included twice. */
    var INOVIO_INITIALIZED = false;

    /**
     * Read the PHP-injected config defensively — themes/overrides may load
     * this script before window.inovioConfig exists, or omit fields.
     * @returns {Object}
     */
    function cfg() {
        return window.inovioConfig || {};
    }

    /**
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    function translate(key, fallback) {
        var t = cfg().translations || {};

        return t[key] || fallback;
    }

    // ------------------------------------------------------------------
    // Card helpers (ported verbatim from inovio-method.js)
    // ------------------------------------------------------------------

    /**
     * Strip whitespace/dashes from a raw card number input.
     * @param {string} raw
     * @returns {string}
     */
    function normalizePan(raw) {
        return String(raw || '').replace(/[\s-]/g, '');
    }

    /**
     * Detect the card brand from the PAN prefix.
     * @param {string} pan
     * @returns {string} One of VI/MC/AE/DI/JCB/DN, or '' if unrecognized.
     */
    function cardBrand(pan) {
        if (/^4/.test(pan)) { return 'VI'; }
        if (/^(5[1-5]|2[2-7])/.test(pan)) { return 'MC'; }
        if (/^3[47]/.test(pan)) { return 'AE'; }
        if (/^(6011|64[4-9]|65)/.test(pan)) { return 'DI'; }
        if (/^35/.test(pan)) { return 'JCB'; }
        if (/^3(0[0-5]|[68])/.test(pan)) { return 'DN'; }

        return '';
    }

    /**
     * Standard Luhn checksum.
     * @param {string} pan
     * @returns {boolean}
     */
    function luhnValid(pan) {
        var sum = 0, dbl = false, i, d;

        for (i = pan.length - 1; i >= 0; i--) {
            d = parseInt(pan.charAt(i), 10);

            if (dbl) {
                d *= 2;

                if (d > 9) { d -= 9; }
            }
            sum += d;
            dbl = !dbl;
        }

        return pan.length >= 12 && sum % 10 === 0;
    }

    /**
     * Validate PAN, expiry, and CVV before touching the network.
     * @param {{pan: string, month: string, year: string, cvv: string}} fields
     * @returns {string|null} An error message, or null if valid.
     */
    function validate(fields) {
        var pan = fields.pan,
            month = fields.month,
            year = fields.year,
            now = new Date();

        if (!luhnValid(pan)) {
            return translate('invalid_card_number', 'Please enter a valid card number.');
        }

        if (!month || !year ||
            parseInt(year, 10) < now.getFullYear() ||
            parseInt(year, 10) === now.getFullYear() && parseInt(month, 10) < now.getMonth() + 1
        ) {
            return translate('invalid_expiry', 'Please enter a valid expiration date.');
        }

        if (!/^[0-9]{3,4}$/.test(String(fields.cvv))) {
            return translate('invalid_cvv', 'Please enter a valid security code.');
        }

        return null;
    }

    /**
     * Cryptographically random lowercase hex string.
     * @param {number} length Number of hex characters (even).
     * @returns {string}
     */
    function randomHex(length) {
        var bytes = new Uint8Array(length / 2);

        window.crypto.getRandomValues(bytes);

        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * Snapshot the browser fields the 3DS DDC/challenge legs require.
     * @returns {Object}
     */
    function collectBrowserData() {
        return {
            language: navigator.language || 'en-US',
            userAgent: navigator.userAgent,
            header: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            javaEnabled: typeof navigator.javaEnabled === 'function' ? navigator.javaEnabled() : false,
            colorDepth: window.screen.colorDepth,
            screenHeight: window.screen.height,
            screenWidth: window.screen.width,
            timeZoneOffset: Math.abs(new Date().getTimezoneOffset())
        };
    }

    // ------------------------------------------------------------------
    // Error UI
    // ------------------------------------------------------------------

    /**
     * Find (or lazily create) the #inovio-errors div and show a message in
     * it. Never pass raw gateway response bodies here — only translated /
     * hand-written user-facing strings.
     * @param {string} message
     * @param {HTMLElement} formEl Form to insert the error box above, when created.
     */
    function showError(message, formEl) {
        var box = document.getElementById('inovio-errors');

        if (!box) {
            box = document.createElement('div');
            box.id = 'inovio-errors';
            box.setAttribute('style', 'color:#d9534f;margin:10px 0;');
            if (formEl && formEl.parentNode) {
                formEl.parentNode.insertBefore(box, formEl);
            } else {
                document.body.appendChild(box);
            }
        }
        box.textContent = message;
        box.style.display = 'block';
    }

    function clearError() {
        var box = document.getElementById('inovio-errors');

        if (box) {
            box.style.display = 'none';
            box.textContent = '';
        }
    }

    // ------------------------------------------------------------------
    // Tokenization / 3DS (ported verbatim in behavior from inovio-method.js)
    // ------------------------------------------------------------------

    /**
     * Ask our own signature endpoint for a timestamp/signature/site_id, then
     * POST the PAN straight to the Inovio token service (browser-direct —
     * the PAN never touches the PrestaShop server). Resolves with a
     * single-use TOKEN_GUID.
     * @param {string} pan
     * @param {string} cvv
     * @returns {Promise<string>}
     */
    function mintToken(pan, cvv) {
        var uid = randomHex(32);

        return fetch(cfg().signatureUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'uniqueId=' + encodeURIComponent(uid)
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('signature');
            }

            return resp.json();
        }).catch(function () {
            throw translate('signing_failed', 'Payment signing failed. Please refresh and try again.');
        }).then(function (sig) {
            var body = new URLSearchParams();

            body.append('card_pan', pan);
            body.append('card_cvv', String(cvv));
            body.append('request_response_format', 'json');
            body.append('request_api_version', '4.14');
            body.append('site_id', sig.siteId);
            body.append('unique_id', uid);

            return fetch(sig.tokenUrl || cfg().tokenUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-timestamp': sig.timestamp,
                    'X-signature': sig.signature
                },
                body: body.toString()
            }).then(function (resp) {
                return resp.json();
            }).then(function (token) {
                if (!token.TOKEN_GUID) {
                    throw token.ERROR_MESSAGE ||
                        translate('card_failed', 'Card could not be processed. Please try again.');
                }

                return token.TOKEN_GUID;
            }).catch(function (err) {
                // Re-throw string messages (from the TOKEN_GUID check above)
                // as-is; wrap network/parse failures in a generic message so
                // we never surface a raw gateway/network error to the user.
                if (typeof err === 'string') {
                    throw err;
                }
                throw translate('token_service_unreachable', 'Could not reach the payment service. Please try again.');
            });
        });
    }

    /**
     * Full tokenize + (optional) 3DS-prepare chain.
     *
     * Gateway tokens are single-use: the 3DS enrollment leg consumes the
     * token that triggers the challenge, so a second token is minted for the
     * completion leg. Both resolve to the same PAN, keeping the 3DS
     * authentication valid. (Verified against the gateway's "API 401
     * Invalid TOKEN_GUID" rejection on token reuse — do not collapse this
     * back into a single mintToken() call.)
     *
     * @param {{pan: string, cvv: string}} card
     * @returns {Promise<Object>} Resolves with {tokenGuid, tokenGuidCompletion, ddcReferenceId, browserData}.
     */
    function tokenizeFlow(card) {
        var pan = card.pan,
            cvv = card.cvv,
            browserData = collectBrowserData(),
            needsSecond = !!cfg().threeDsActive,
            result = {
                tokenGuid: null,
                tokenGuidCompletion: null,
                ddcReferenceId: null,
                browserData: browserData
            };

        return mintToken(pan, cvv).then(function (guid) {
            result.tokenGuid = guid;

            if (!needsSecond) {
                return result;
            }

            return mintToken(pan, cvv).then(function (guid2) {
                result.tokenGuidCompletion = guid2;

                return prepareThreeDs(pan.slice(0, 6)).then(function (ddcReferenceId) {
                    result.ddcReferenceId = ddcReferenceId;

                    return result;
                });
            });
        });
    }

    /**
     * Start a 3DS session for this BIN and run the hidden device-data-
     * collection iframe. Never rejects — a failure here should not block
     * checkout, it just means no DDC reference id gets attached.
     * @param {string} bin First 6 digits of the PAN.
     * @returns {Promise<string|null>} ddcReferenceId, or null.
     */
    function prepareThreeDs(bin) {
        return fetch(cfg().prepareUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'bin=' + encodeURIComponent(bin)
        }).then(function (resp) {
            return resp.json();
        }).then(function (ddc) {
            if (!ddc || !ddc.jwt || !ddc.ddcUrl) {
                return null;
            }

            return runHiddenDdc(ddc).then(function () {
                return ddc.ddcReferenceId || null;
            });
        }).catch(function () {
            return null;
        });
    }

    /**
     * POST the JWT to the DDC url in a hidden iframe and wait for the
     * device-fingerprinting page to postMessage completion, OR an 8-second
     * timeout — whichever comes first. Checkout must never hang waiting on
     * a DDC iframe that never responds.
     * @param {{jwt: string, ddcUrl: string}} ddc
     * @returns {Promise<void>}
     */
    function runHiddenDdc(ddc) {
        return new Promise(function (resolve) {
            var iframe = document.createElement('iframe'),
                form = document.createElement('form'),
                input = document.createElement('input'),
                finished = false,
                finish = function () {
                    if (!finished) {
                        finished = true;
                        window.removeEventListener('message', onMessage);
                        iframe.remove();
                        form.remove();
                        resolve();
                    }
                },
                onMessage = function (e) {
                    var data;

                    try {
                        data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                    } catch (err) {
                        return;
                    }

                    if (data && data.MessageType === 'profile.completed') {
                        finish();
                    }
                };

            iframe.name = 'inovio-ddc';
            iframe.style.display = 'none';
            form.method = 'POST';
            form.action = ddc.ddcUrl;
            form.target = 'inovio-ddc';
            input.type = 'hidden';
            input.name = 'JWT';
            input.value = ddc.jwt;
            form.appendChild(input);
            document.body.appendChild(iframe);
            document.body.appendChild(form);
            window.addEventListener('message', onMessage);
            form.submit();
            setTimeout(finish, 8000);
        });
    }

    /**
     * Visible ACS challenge iframe. POSTs the challenge JWT to redirectUrl
     * in an overlay iframe; the server-rendered return page postMessages the
     * outcome back to this window as {inovio3ds: 'complete', success, message}.
     *
     * Exported as window.inovioRunChallenge because PrestaShop has no
     * afterPlaceOrder-style hook: the server-side controller renders the
     * challenge page directly when 3DS is required, and that page calls
     * this function itself rather than us polling for it after submission.
     *
     * @param {{redirectUrl: string, jwt: string}} challenge
     */
    function runChallenge(challenge) {
        var overlay = document.createElement('div'),
            iframe = document.createElement('iframe'),
            form = document.createElement('form'),
            input = document.createElement('input'),
            onMessage = function (e) {
                // Only trust postMessages from our own origin — the return
                // page is same-origin with us even though the ACS challenge
                // itself is cross-origin inside the iframe.
                if (e.origin !== window.location.origin || !e.data || e.data.inovio3ds !== 'complete') {
                    return;
                }
                window.removeEventListener('message', onMessage);
                overlay.remove();
                form.remove();

                if (e.data.success) {
                    var form2 = document.getElementById('inovio-3ds-resume-form');

                    if (form2) {
                        form2.submit();
                    }
                } else {
                    showError(e.data.message || translate('auth_failed', 'Payment authentication failed.'));
                }
            };

        overlay.setAttribute('style',
            'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;' +
            'display:flex;align-items:center;justify-content:center;');
        iframe.name = 'inovio-3ds-challenge';
        iframe.setAttribute('style', 'width:420px;height:480px;border:0;background:#fff;');
        overlay.appendChild(iframe);
        form.method = 'POST';
        form.action = challenge.redirectUrl;
        form.target = 'inovio-3ds-challenge';
        input.type = 'hidden';
        input.name = 'JWT';
        input.value = challenge.jwt;
        form.appendChild(input);
        document.body.appendChild(overlay);
        document.body.appendChild(form);
        window.addEventListener('message', onMessage);
        form.submit();
    }

    // Exported for the server-rendered challenge page to invoke directly.
    window.inovioRunChallenge = runChallenge;

    // ------------------------------------------------------------------
    // Form wiring
    // ------------------------------------------------------------------

    /**
     * Locate our payment form.
     *
     * PrestaShop wraps each payment option's embedded form in a container
     * Locate our payment form. See the note inside about PrestaShop 9's
     * actual wrapper id, which is index-based rather than module-named.
     * @returns {HTMLFormElement|null}
     */
    function findPaymentForm() {
        // Our own form id is the reliable anchor.
        //
        // NOTE: PrestaShop 9 wraps each payment option in
        // `pay-with-payment-option-<N>-form` — an INDEX, not the module name
        // (verified in a browser on the Hummingbird theme). So do not look for
        // `pay-with-inoviopayment-form`; it does not exist.
        var form = document.getElementById('inovio-payment-form');

        if (form) {
            return form;
        }

        var panField = document.querySelector('[name="inovio_card_number"], #inovio_card_number');

        if (panField) {
            return panField.closest('form');
        }

        return null;
    }

    /**
     * @param {HTMLFormElement} form
     * @param {string} name
     * @returns {HTMLInputElement|null}
     */
    function field(form, name) {
        return form.querySelector('[name="' + name + '"]');
    }

    /**
     * Write a value into a hidden input, creating it if the template didn't
     * already declare it.
     * @param {HTMLFormElement} form
     * @param {string} name
     * @param {string} value
     */
    function setHidden(form, name, value) {
        var input = field(form, name);

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = value === null || value === undefined ? '' : value;
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {HTMLButtonElement|HTMLInputElement|null}
     */
    function findSubmitButton(form) {
        return form.querySelector('button[type="submit"], input[type="submit"]') ||
            document.querySelector('#payment-confirmation button, #payment-confirmation [type="submit"]');
    }

    /**
     * @param {HTMLElement|null} button
     * @param {boolean} busy
     * @param {string} busyText
     */
    function setBusy(button, busy, busyText) {
        if (!button) {
            return;
        }

        if (busy) {
            button.dataset.inovioOriginalText = button.dataset.inovioOriginalText || button.innerHTML;
            button.disabled = true;
            button.innerHTML = busyText;
        } else {
            button.disabled = false;
            if (button.dataset.inovioOriginalText) {
                button.innerHTML = button.dataset.inovioOriginalText;
            }
        }
    }

    /**
     * Bind the submit-intercept on our payment form. First submit: run the
     * tokenize/3DS chain and populate hidden inputs, then programmatically
     * resubmit; second (guarded) submit goes through to PrestaShop untouched.
     * @param {HTMLFormElement} form
     */
    /**
     * The submit interception itself. Shared by the document-level capture
     * listener and by bindForm(), so the behaviour is identical however the
     * submit reaches us.
     *
     * The "already tokenized" guard lives on the form ELEMENT rather than in a
     * closure: PrestaShop can re-render the payment step, and a closure flag
     * would be lost (or worse, stale) across renders.
     *
     * @param {HTMLFormElement} form
     * @param {Event} e
     */
    function handleSubmit(form, e) {
        var submitBtn = findSubmitButton(form);

        if (form.dataset.inovioSubmitted === 'true') {
            // Second pass — let PrestaShop's own controller handle it.
            return;
        }

        (function (e) {
            e.preventDefault();
            e.stopPropagation();
            clearError();

            var panField = field(form, 'inovio_card_number'),
                monthField = field(form, 'inovio_exp_month'),
                yearField = field(form, 'inovio_exp_year'),
                cvvField = field(form, 'inovio_cvv'),
                saveCardField = form.querySelector('[name="inovio_save_card_input"]'),
                pan = normalizePan(panField ? panField.value : ''),
                month = monthField ? monthField.value : '',
                year = yearField ? yearField.value : '',
                cvv = cvvField ? cvvField.value : '',
                validationError = validate({pan: pan, month: month, year: year, cvv: cvv});

            if (validationError) {
                showError(validationError, form);

                return;
            }

            setBusy(submitBtn, true, translate('processing', 'Processing payment…'));

            tokenizeFlow({pan: pan, cvv: cvv}).then(function (result) {
                setHidden(form, 'inovio_token_guid', result.tokenGuid);
                setHidden(form, 'inovio_token_guid_completion', result.tokenGuidCompletion);
                setHidden(form, 'inovio_pmt_expiry', month + year);
                setHidden(form, 'inovio_cc_brand', cardBrand(pan));
                setHidden(form, 'inovio_cc_last4', pan.slice(-4));
                setHidden(form, 'inovio_ddc_reference_id', result.ddcReferenceId);
                setHidden(form, 'inovio_browser', result.browserData ? JSON.stringify(result.browserData) : '');
                setHidden(form, 'inovio_save_card',
                    saveCardField && saveCardField.checked ? 'true' : 'false');

                form.dataset.inovioSubmitted = 'true';
                setBusy(submitBtn, false);

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitBtn || undefined);
                } else {
                    form.submit();
                }
            }).catch(function (message) {
                setBusy(submitBtn, false);
                showError(String(message), form);
            });
        }(e));
    }

    /**
     * Per-form setup. The submit interception is handled at the document
     * level (see the bottom of this file), so this only marks the form as
     * seen; it is kept as a named step for clarity and future per-form work.
     * @param {HTMLFormElement} form
     */
    function bindForm(form) {
        form.dataset.inovioBound = 'true';
    }

    /**
     * Entry point: locate the form and wire it up. Safe to call more than
     * once — subsequent calls are no-ops once bound.
     */
    function init() {
        if (INOVIO_INITIALIZED) {
            return;
        }

        var form = findPaymentForm();

        if (!form) {
            // Payment method not selected / not rendered on this page load —
            // nothing to bind yet. PrestaShop may swap payment options in
            // dynamically; re-running init() on later interaction is safe.
            return;
        }

        bindForm(form);
        INOVIO_INITIALIZED = true;
    }

    /*
     * Binding strategy.
     *
     * VERIFIED IN A BROWSER (PrestaShop 9, Hummingbird theme, 2026-08-28):
     * the payment form does not exist at DOMContentLoaded OR at window.load.
     * PrestaShop renders the checkout on step 1 and only injects the payment
     * options when the shopper reaches step 4, so an init() bound to either
     * event finds no form and never wires anything up — the order then posts
     * with no token and the server correctly refuses it.
     *
     * PrestaShop also exposes no order-submission event to hook (the whole
     * documented bus was checked: updateCart/updatedCart/changedCheckoutStep/
     * updatedDeliveryForm/termsUpdated/orderConfirmationErrors/… — none fire
     * on placement).
     *
     * So we bind ONCE at the document level, in the capture phase. The
     * listener survives any re-render of the payment step because it is not
     * attached to the form at all; it simply checks whether the submitted
     * form is ours. A MutationObserver additionally runs init() when the form
     * finally appears, so per-form setup still happens exactly once.
     */
    function documentSubmitHandler(e) {
        var form = e.target;

        if (!form || form.id !== 'inovio-payment-form') {
            return;
        }

        // Delegate to the same interception used when binding directly.
        handleSubmit(form, e);
    }

    document.addEventListener('submit', documentSubmitHandler, true);

    /*
     * PrestaShop submits the payment form with jQuery, not natively.
     *
     * VERIFIED by reading the Hummingbird theme's own checkout bundle
     * (2026-08-28). Its confirm() handler ends with:
     *
     *     $(`#pay-with-${option}-form form`).submit()
     *
     * jQuery's .submit() triggers jQuery-bound handlers ONLY — it does not
     * dispatch an event that native addEventListener('submit') handlers can
     * see. That is why a native listener (any phase, document or form) never
     * fires on a real "Place Order" click, and why the order posted with no
     * token until this handler was added.
     *
     * So when jQuery is present (the theme always loads it) we bind through
     * jQuery as well, and that is the path that actually runs in production.
     */
    if (typeof window.jQuery === 'function') {
        window.jQuery(document).on('submit', '#inovio-payment-form', function (e) {
            handleSubmit(this, e);
        });
    }

    /*
     * ⚠️ KNOWN GAP — browser checkout is NOT yet working end to end.
     *
     * Status as of 2026-08-28, established by testing in a real browser
     * against PrestaShop 9 + Hummingbird:
     *
     *   - Everything on the server side is proven: the signature endpoint
     *     returns a valid HMAC to the browser (200), and a browser-direct POST
     *     of the PAN to token_service.cfm returns a real TOKEN_GUID. Both were
     *     executed from this page.
     *   - The theme submits with jQuery: `$('#pay-with-<opt>-form form').submit()`.
     *     A native addEventListener('submit') never sees that, and the Place
     *     Order button is not associated with any form (its .form is null), so
     *     a button-click interceptor cancels PrestaShop's own option handling
     *     and collapses the form.
     *   - With the jQuery binding above the form DOES submit and reaches our
     *     validation controller, but it posts BEFORE the async tokenize chain
     *     resolves, so `inovio_token_guid` is empty and the controller
     *     correctly refuses with "Payment token is missing".
     *
     * The remaining work is to make the submit wait for tokenization: cancel
     * the first jQuery submit, run the chain, then re-trigger the theme's own
     * submit path once the hidden inputs are populated. jQuery's
     * event.preventDefault() inside a delegated 'submit' handler does stop
     * .submit(), so this is a solvable sequencing problem, not a dead end.
     *
     * The server side refusing an untokenized order is correct behaviour and
     * should not be relaxed to make checkout "work".
     */

    /*
     * The "Place Order" button is NOT part of our form.
     *
     * VERIFIED IN A BROWSER (PrestaShop 9, Hummingbird theme, 2026-08-28):
     * the button is `type="submit"` but its `.form` is null and it lives
     * OUTSIDE the payment form — PrestaShop's own checkout JS submits the
     * selected option's form programmatically. A native `submit` event
     * therefore never fires on our form from a real click, so a submit
     * listener alone (any phase, any element) can never intercept checkout.
     *
     * We therefore also intercept the BUTTON CLICK in the capture phase, run
     * the tokenize/3DS chain, and only then re-issue the click — at which
     * point PrestaShop submits the form with our hidden inputs populated.
     */
    function isPlaceOrderButton(el) {
        if (!el || !el.closest) {
            return null;
        }

        var btn = el.closest('button, input[type="submit"]'),
            form = document.getElementById('inovio-payment-form');

        if (!btn || !form || form.offsetParent === null) {
            return null;
        }

        // Don't hijack clicks inside our own form.
        if (form.contains(btn)) {
            return null;
        }

        return (btn.closest('#checkout-payment-step') || btn.closest('#payment-confirmation'))
            ? btn
            : null;
    }

    /**
     * Run the tokenize/3DS chain, populate the hidden inputs, then re-issue
     * the click so PrestaShop's own checkout code submits the form.
     * @param {HTMLFormElement} form
     * @param {HTMLElement} btn
     */
    function runTokenizeAndContinue(form, btn) {
        var panField = field(form, 'inovio_card_number'),
            monthField = field(form, 'inovio_exp_month'),
            yearField = field(form, 'inovio_exp_year'),
            cvvField = field(form, 'inovio_cvv'),
            saveCardField = form.querySelector('[name="inovio_save_card_input"]'),
            savedCardRadio = form.querySelector('[name="inovio_saved_card_id"]:checked'),
            pan = normalizePan(panField ? panField.value : ''),
            month = monthField ? monthField.value : '',
            year = yearField ? yearField.value : '',
            cvv = cvvField ? cvvField.value : '',
            validationError;

        clearError();

        // Paying with a stored card needs no tokenization at all.
        if (savedCardRadio && savedCardRadio.value) {
            form.dataset.inovioSubmitted = 'true';
            btn.click();

            return;
        }

        validationError = validate({pan: pan, month: month, year: year, cvv: cvv});

        if (validationError) {
            showError(validationError, form);

            return;
        }

        setBusy(btn, true, translate('processing', 'Processing payment…'));

        tokenizeFlow({pan: pan, cvv: cvv}).then(function (result) {
            setHidden(form, 'inovio_token_guid', result.tokenGuid);
            setHidden(form, 'inovio_token_guid_completion', result.tokenGuidCompletion);
            setHidden(form, 'inovio_pmt_expiry', month + year);
            setHidden(form, 'inovio_cc_brand', cardBrand(pan));
            setHidden(form, 'inovio_cc_last4', pan.slice(-4));
            setHidden(form, 'inovio_ddc_reference_id', result.ddcReferenceId);
            setHidden(form, 'inovio_browser', result.browserData ? JSON.stringify(result.browserData) : '');
            setHidden(form, 'inovio_save_card',
                saveCardField && saveCardField.checked ? 'true' : 'false');

            form.dataset.inovioSubmitted = 'true';
            setBusy(btn, false);
            btn.click();
        }).catch(function (message) {
            setBusy(btn, false);
            showError(String(message), form);
        });
    }

    /*
     * NOTE (2026-08-28): a capture-phase click interceptor on the Place Order
     * button was tried here and REMOVED. Cancelling that click also cancels
     * PrestaShop's own payment-option handling, which collapses the selected
     * option and hides the form. The jQuery submit binding above is the
     * correct hook; keep interception there, not on the button.
     *
     * `isPlaceOrderButton` / `runTokenizeAndContinue` are retained because the
     * jQuery path reuses the same tokenize-then-continue logic.
     */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('load', function () {
        if (!INOVIO_INITIALIZED) {
            init();
        }
    });

    /*
     * The payment step is injected late, so watch for it — but do so cheaply:
     * PrestaShop's checkout mutates the DOM constantly, and running a query on
     * every mutation record is enough to make the page unresponsive. The
     * callback is therefore throttled to one check per animation frame and the
     * observer disconnects itself the moment the form is found.
     */
    if (typeof MutationObserver === 'function' && !INOVIO_INITIALIZED) {
        (function () {
            var scheduled = false,
                observer = new MutationObserver(function () {
                    if (scheduled || INOVIO_INITIALIZED) {
                        return;
                    }
                    scheduled = true;
                    window.requestAnimationFrame(function () {
                        scheduled = false;
                        init();
                        if (INOVIO_INITIALIZED) {
                            observer.disconnect();
                        }
                    });
                });

            observer.observe(document.body || document.documentElement,
                {childList: true, subtree: true});
        }());
    }
}());
