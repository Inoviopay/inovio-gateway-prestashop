{*
 * Checkout-side saved-card selector fragment.
 *
 * Expected to be included from the module's embedded PaymentOption form
 * (see docs/prestashop-integration-design.md §6 — the form itself is built
 * by the main module class, not by this file).
 *
 * Expects a Smarty var `inovio_stored_cards` — same shape as assigned by
 * InoviopaymentStoredcardsModuleFrontController::initContent():
 *   [{id_inovio_stored_card, card_brand, masked_number, expiry_label}, ...]
 *
 * Kept theme-neutral: Bootstrap 4 classes only, no custom CSS dependency,
 * so it drops into the default PrestaShop 9 checkout theme unmodified.
 *}
<div class="inovio-saved-cards-select">
  {if $inovio_stored_cards|count > 0}
    {foreach from=$inovio_stored_cards item=inovio_card name=inovio_saved_cards}
      <div class="form-check">
        <input
          class="form-check-input inovio-saved-card-radio"
          type="radio"
          name="inovio_payment_method"
          id="inovio_saved_card_{$inovio_card.id_inovio_stored_card|intval}"
          value="{$inovio_card.id_inovio_stored_card|intval}"
          data-inovio-source="saved"
          {if $smarty.foreach.inovio_saved_cards.first}checked{/if}
        >
        <label class="form-check-label" for="inovio_saved_card_{$inovio_card.id_inovio_stored_card|intval}">
          {$inovio_card.card_brand|escape:'html':'UTF-8'}
          {$inovio_card.masked_number|escape:'html':'UTF-8'}
          <span class="text-muted">
            {l s='exp.' d='Modules.Inoviopayment.Shop'} {$inovio_card.expiry_label|escape:'html':'UTF-8'}
          </span>
        </label>
      </div>
    {/foreach}

    <div class="form-check">
      <input
        class="form-check-input inovio-saved-card-radio"
        type="radio"
        name="inovio_payment_method"
        id="inovio_new_card"
        value="new"
        data-inovio-source="new"
        {if $inovio_stored_cards|count == 0}checked{/if}
      >
      <label class="form-check-label" for="inovio_new_card">
        {l s='Use a new card' d='Modules.Inoviopayment.Shop'}
      </label>
    </div>
  {/if}

  {* New-card fields. Shown by default when there are no saved cards; the
     module JS toggles visibility based on the radio selection above. *}
  <div class="inovio-new-card-form" {if $inovio_stored_cards|count > 0}style="display:none;"{/if}>
    {* Card number / expiry / CVV inputs and any "save this card" opt-in
       checkbox are rendered by the main module's embedded payment form —
       this fragment only owns the saved-card list and the toggle target. *}
  </div>
</div>
