{**
 * 3DS challenge host page.
 *
 * The order is parked in "Awaiting 3DS authentication"; this page runs the
 * visible ACS iframe. inovio-checkout.js exposes window.inovioRunChallenge,
 * which posts the JWT to the ACS and waits for our return controller to
 * postMessage the outcome back.
 *
 * MUST extend the theme layout: a bare template renders with no <head> and
 * therefore no theme JS, which left window.inovioRunChallenge undefined and
 * the ACS iframe never opened.
 *}
{extends file=$layout}

{block name='content'}
  <div id="inovio-3ds-host" class="container">
    <p>{l s='Please complete the verification with your bank…' d='Modules.Inoviopayment.Shop'}</p>
    <div id="inovio-errors" class="alert alert-danger" style="display:none" role="alert"></div>

    <form id="inovio-3ds-resume-form" method="get" action="{$inovioConfirmUrl|escape:'html':'UTF-8'}">
      <input type="hidden" name="id_cart" value="{$inovioCartId|intval}">
      <input type="hidden" name="id_module" value="{$inovioModuleId|intval}">
      <input type="hidden" name="key" value="{$inovioSecureKey|escape:'html':'UTF-8'}">
    </form>
  </div>

  <script>
    (function () {
      var challenge = {$inovioChallenge nofilter};

      function go() {
        if (window.inovioRunChallenge && challenge && challenge.redirectUrl) {
          window.inovioRunChallenge(challenge);
        } else {
          setTimeout(go, 50);
        }
      }

      go();
    }());
  </script>
{/block}
