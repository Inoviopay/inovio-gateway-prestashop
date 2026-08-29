<div class="inovio-payment-return">
  <p>
    {l s='Your payment has been processed.' d='Modules.Inoviopayment.Shop'}
  </p>
  <ul>
    <li>{l s='Order reference:' d='Modules.Inoviopayment.Shop'} <strong>{$inovioReference|escape:'html':'UTF-8'}</strong></li>
    <li>{l s='Amount:' d='Modules.Inoviopayment.Shop'} <strong>{$inovioTotal|escape:'html':'UTF-8'}</strong></li>
    <li>{l s='Status:' d='Modules.Inoviopayment.Shop'} <strong>{$inovioStatus|escape:'html':'UTF-8'}</strong></li>
  </ul>
</div>
