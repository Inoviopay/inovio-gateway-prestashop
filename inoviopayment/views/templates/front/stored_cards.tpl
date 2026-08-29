{*
 * "My saved cards" customer-account page.
 * Assigned by InoviopaymentStoredcardsModuleFrontController::initContent().
 *}
<div class="inovio-stored-cards">
  <h1 class="page-heading">{l s='My saved cards' d='Modules.Inoviopayment.Shop'}</h1>

  {if $inovio_deleted}
    <div class="alert alert-success" role="alert">
      {l s='The card was removed.' d='Modules.Inoviopayment.Shop'}
    </div>
  {/if}

  {if $inovio_error == 'csrf'}
    <div class="alert alert-danger" role="alert">
      {l s='Your session has expired. Please try again.' d='Modules.Inoviopayment.Shop'}
    </div>
  {elseif $inovio_error == 'notfound'}
    <div class="alert alert-danger" role="alert">
      {l s='That card could not be found.' d='Modules.Inoviopayment.Shop'}
    </div>
  {/if}

  {if $inovio_stored_cards|count > 0}
    <table class="table inovio-stored-cards-table">
      <thead>
        <tr>
          <th>{l s='Brand' d='Modules.Inoviopayment.Shop'}</th>
          <th>{l s='Card number' d='Modules.Inoviopayment.Shop'}</th>
          <th>{l s='Expiry' d='Modules.Inoviopayment.Shop'}</th>
          <th>{l s='Added' d='Modules.Inoviopayment.Shop'}</th>
          <th>{l s='Actions' d='Modules.Inoviopayment.Shop'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$inovio_stored_cards item=inovio_card}
          <tr>
            <td>{$inovio_card.card_brand|escape:'html':'UTF-8'}</td>
            <td>{$inovio_card.masked_number|escape:'html':'UTF-8'}</td>
            <td>{$inovio_card.expiry_label|escape:'html':'UTF-8'}</td>
            <td>{$inovio_card.date_add|escape:'html':'UTF-8'}</td>
            <td>
              <form action="{$link->getModuleLink('inoviopayment', 'storedcards')|escape:'html':'UTF-8'}" method="post">
                <input type="hidden" name="id_inovio_stored_card" value="{$inovio_card.id_inovio_stored_card|intval}">
                <input type="hidden" name="token" value="{$inovio_delete_token|escape:'html':'UTF-8'}">
                <button type="submit" name="deleteInovioStoredCard" value="1" class="btn btn-default inovio-delete-card"
                  onclick="return confirm('{l s='Remove this saved card?' d='Modules.Inoviopayment.Shop' js=1}');">
                  {l s='Remove' d='Modules.Inoviopayment.Shop'}
                </button>
              </form>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p class="alert alert-info">
      {l s='You have no saved cards yet.' d='Modules.Inoviopayment.Shop'}
    </p>
  {/if}
</div>
