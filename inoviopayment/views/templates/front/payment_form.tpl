{**
 * Embedded payment form.
 *
 * PrestaShop generates the submit button itself, so this template MUST NOT
 * contain one (a hard Addons validation rule).
 *
 * The card fields below are deliberately NOT named as POST fields the server
 * reads: inovio-checkout.js exchanges them for a token in the browser and
 * writes only the token into hidden inputs. The PAN never reaches the server.
 *}
<form id="inovio-payment-form" action="{$inovioAction|escape:'html':'UTF-8'}" method="post">
  <input type="hidden" name="inovio_token" value="{$inovioToken|escape:'html':'UTF-8'}">

  <div id="inovio-errors" class="alert alert-danger" style="display:none" role="alert"></div>

  {if $inovioVaultActive && $inovioSavedCards|@count > 0}
    <div class="inovio-saved-cards form-group">
      {foreach from=$inovioSavedCards item=card}
        <div class="form-check">
          <label class="form-check-label">
            <input class="form-check-input inovio-saved-card-radio" type="radio"
                   name="inovio_saved_card_id" value="{$card.id_inovio_stored_card|intval}">
            {$card.card_brand|escape:'html':'UTF-8'} &bull;&bull;&bull;&bull; {$card.card_last4|escape:'html':'UTF-8'}
            <span class="text-muted">
              ({$card.card_exp_month|escape:'html':'UTF-8'}/{$card.card_exp_year|escape:'html':'UTF-8'})
            </span>
          </label>
        </div>
      {/foreach}
      <div class="form-check">
        <label class="form-check-label">
          <input class="form-check-input inovio-saved-card-radio" type="radio"
                 name="inovio_saved_card_id" value="" checked>
          {l s='Use a new card' d='Modules.Inoviopayment.Shop'}
        </label>
      </div>
    </div>
  {/if}

  <div class="inovio-new-card">
    <div class="form-group row">
      <label class="col-md-4 form-control-label" for="inovio_card_number">
        {l s='Card number' d='Modules.Inoviopayment.Shop'}
      </label>
      <div class="col-md-8">
        <input type="text" id="inovio_card_number" class="form-control"
               inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="•••• •••• •••• ••••">
      </div>
    </div>

    <div class="form-group row">
      <label class="col-md-4 form-control-label" for="inovio_exp_month">
        {l s='Expiration date' d='Modules.Inoviopayment.Shop'}
      </label>
      <div class="col-md-4">
        <select id="inovio_exp_month" name="inovio_exp_month" class="form-control" autocomplete="cc-exp-month">
          {foreach from=$inovioMonths item=m}
            <option value="{$m|string_format:'%02d'}">{$m|string_format:'%02d'}</option>
          {/foreach}
        </select>
      </div>
      <div class="col-md-4">
        <select id="inovio_exp_year" name="inovio_exp_year" class="form-control" autocomplete="cc-exp-year">
          {foreach from=$inovioYears item=y}
            <option value="{$y|intval}">{$y|intval}</option>
          {/foreach}
        </select>
      </div>
    </div>

    <div class="form-group row">
      <label class="col-md-4 form-control-label" for="inovio_cvv">
        {l s='Security code' d='Modules.Inoviopayment.Shop'}
      </label>
      <div class="col-md-8">
        <input type="text" id="inovio_cvv" class="form-control"
               inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="•••">
      </div>
    </div>

    {if $inovioVaultActive}
      <div class="form-group row">
        <div class="col-md-8 offset-md-4">
          <label class="form-check-label">
            <input type="checkbox" name="inovio_save_card_input" value="1"> 
            {l s='Save this card for future purchases' d='Modules.Inoviopayment.Shop'}
          </label>
        </div>
      </div>
    {/if}
  </div>
</form>
