{**
 * Back-office capture / void controls.
 *
 * PrestaShop exposes no merchant-triggered capture hook, so the module
 * supplies its own controls rather than inferring intent from a status
 * change (which would fire gateway calls on unrelated merchant edits).
 *}
<div class="card mt-2" id="inovio-order-panel">
  <div class="card-header"><h3 class="card-header-title">{l s='Inovio Payment' d='Modules.Inoviopayment.Admin'}</h3></div>
  <div class="card-body">
    <p>
      {l s='Gateway order:' d='Modules.Inoviopayment.Admin'} <code>{$inovioPoId|escape:'html':'UTF-8'}</code><br>
      {l s='Transaction:' d='Modules.Inoviopayment.Admin'} <code>{$inovioTransId|escape:'html':'UTF-8'}</code>
    </p>
    {if $inovioCanCapture}
      <form method="post" class="form-inline">
        <input type="hidden" name="inovio_order_id" value="{$inovioOrderId|intval}">
        <div class="form-group">
          <label class="mr-2">{l s='Amount (blank = full)' d='Modules.Inoviopayment.Admin'}</label>
          <input type="text" name="inovio_capture_amount" class="form-control mr-2" placeholder="">
        </div>
        <button type="submit" name="inovio_capture" class="btn btn-primary mr-2">
          {l s='Capture' d='Modules.Inoviopayment.Admin'}
        </button>
        <button type="submit" name="inovio_void" class="btn btn-outline-danger"
                onclick="return confirm('{l s='Void this authorization?' d='Modules.Inoviopayment.Admin' js=1}');">
          {l s='Void' d='Modules.Inoviopayment.Admin'}
        </button>
      </form>
    {else}
      <p class="text-muted">{l s='No pending authorization on this order.' d='Modules.Inoviopayment.Admin'}</p>
    {/if}
  </div>
</div>
