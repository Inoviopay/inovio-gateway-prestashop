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

        // The signature endpoint is CSRF-guarded and cart-bound, so send the
        // PrestaShop token from the form and include session cookies.
        var tokenField = document.querySelector('#inovio-payment-form [name="inovio_token"]'),
            payload = 'uniqueId=' + encodeURIComponent(uid) +
                '&inovio_token=' + encodeURIComponent(tokenField ? tokenField.value : '');

        return fetch(cfg().signatureUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'same-origin',
            body: payload
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
     * Per-form setup. The submit interception is handled at the document
     * level (see the bottom of this file), so this only marks the form as
     * seen; it is kept as a named step for clarity and future per-form work.
     * @param {HTMLFormElement} form
     */
    function bindForm(form) {
        form.dataset.inovioBound = 'true';
    }

    /*
     * How the submit is intercepted.
     *
     * MEASURED IN A BROWSER (PrestaShop 9 + jQuery 3, 2026-08-28) — each fact
     * below was tested on a live page, not assumed:
     *
     *  1. The theme confirms the order with, literally:
     *         $(`#pay-with-${option}-form form`).submit()
     *     (read from Hummingbird's own checkout bundle).
     *  2. jQuery's .submit() runs jQuery-bound handlers ONLY. A native
     *     addEventListener('submit', ...) never fires for it — any phase, form
     *     or document. A native listener therefore cannot intercept checkout.
     *  3. Inside a jQuery-delegated 'submit' handler, e.preventDefault() DOES
     *     stop the submission (isDefaultPrevented === true).
     *  4. HTMLFormElement.prototype.submit.call(form) submits WITHOUT running
     *     jQuery handlers — the escape hatch for continuing once we're done.
     *
     * Hence: bind through jQuery, preventDefault, run the async tokenize
     * chain, then submit natively so we don't re-enter this handler.
     *
     * The Place Order button is deliberately NOT touched: it belongs to no
     * form (its .form is null) and cancelling its click also cancels
     * PrestaShop's own payment-option handling, collapsing the form.
     */
    function onPaymentFormSubmit(form, e) {
        if (form.dataset.inovioSubmitted === 'true') {
            return;
        }

        e.preventDefault();
        clearError();

        var panField = field(form, 'inovio_card_number'),
            monthField = field(form, 'inovio_exp_month'),
            yearField = field(form, 'inovio_exp_year'),
            cvvField = field(form, 'inovio_cvv'),
            saveCardField = form.querySelector('[name="inovio_save_card_input"]'),
            savedCardRadio = form.querySelector('[name="inovio_saved_card_id"]:checked'),
            submitBtn = findSubmitButton(form),
            pan = normalizePan(panField ? panField.value : ''),
            month = monthField ? monthField.value : '',
            year = yearField ? yearField.value : '',
            cvv = cvvField ? cvvField.value : '',
            validationError;

        // A stored card needs no tokenization — send it straight through.
        if (savedCardRadio && savedCardRadio.value) {
            submitForReal(form);

            return;
        }

        validationError = validate({pan: pan, month: month, year: year, cvv: cvv});

        if (validationError) {
            showError(validationError, form);
            setBusy(submitBtn, false);

            return;
        }

        setBusy(submitBtn, true, translate('processing', 'Processing payment\u2026'));

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

            // The PAN and CVV must never reach our server: clear them from the
            // DOM before the form is posted.
            if (panField) { panField.value = ''; }
            if (cvvField) { cvvField.value = ''; }

            setBusy(submitBtn, false);
            submitForReal(form);
        }).catch(function (message) {
            setBusy(submitBtn, false);
            showError(String(message), form);
        });
    }

    /**
     * Submit bypassing every handler (jQuery's included), so the tokenized
     * form posts exactly once.
     * @param {HTMLFormElement} form
     */
    function submitForReal(form) {
        form.dataset.inovioSubmitted = 'true';
        HTMLFormElement.prototype.submit.call(form);
    }

    if (typeof window.jQuery === 'function') {
        window.jQuery(document).on('submit', '#inovio-payment-form', function (e) {
            onPaymentFormSubmit(this, e);
        });
    } else {
        // No jQuery (non-standard theme): fall back to the native event.
        document.addEventListener('submit', function (e) {
            if (e.target && e.target.id === 'inovio-payment-form') {
                onPaymentFormSubmit(e.target, e);
            }
        }, true);
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
