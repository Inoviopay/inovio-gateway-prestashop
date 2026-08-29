{**
 * 3DS challenge host page.
 *
 * The order is parked in "Awaiting 3DS authentication"; this page runs the
 * visible ACS iframe. inovio-checkout.js exposes window.inovioRunChallenge.
 *}
<div id="inovio-3ds-host">
  <p>{l s='Please complete the verification with your bank…' d='Modules.Inoviopayment.Shop'}</p>
  <form id="inovio-3ds-resume-form" method="get"
        action="{$urls.pages.order_confirmation|escape:'html':'UTF-8'}">
    <input type="hidden" name="id_cart" value="{$cart.id|intval}">
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
  })();
</script>
