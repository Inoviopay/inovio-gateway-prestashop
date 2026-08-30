<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once "/var/www/html/config/config.inc.php";
require_once "/var/www/html/modules/inoviopayment/vendor/inovio/autoload.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioGateway.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioStoredCard.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioVault.php";
use Inovio\Gateway\Tokenize;

$ctx = Context::getContext();
$ctx->shop = new Shop(1); $ctx->language = new Language((int)Configuration::get("PS_LANG_DEFAULT"));
$ctx->currency = new Currency((int)Configuration::get("PS_CURRENCY_DEFAULT"));
$ctx->country = new Country((int)Configuration::get("PS_COUNTRY_DEFAULT"));

// A real customer + cart, as checkout would produce.
$customer = new Customer();
$customer->firstname="Eetwo"; $customer->lastname="Tester";
$customer->email="e2e".time()."@inovio.local"; $customer->passwd=Tools::hash("Test123!");
$customer->add();
$addr = new Address();
$addr->id_customer=$customer->id; $addr->id_country=(int)Country::getByIso("US");
$addr->alias="Home"; $addr->firstname="Eetwo"; $addr->lastname="Tester";
$addr->address1="123 Test St"; $addr->city="Las Vegas"; $addr->postcode="89101";
$addr->id_state=(int)State::getIdByIso("NV",(int)Country::getByIso("US")); $addr->add();

$cart = new Cart();
$cart->id_customer=$customer->id; $cart->id_address_delivery=$addr->id; $cart->id_address_invoice=$addr->id;
$cart->id_lang=(int)$ctx->language->id; $cart->id_currency=(int)$ctx->currency->id;
$cart->id_shop=1; $cart->id_carrier=0; $cart->add();
$idProduct=(int)Db::getInstance()->getValue("SELECT id_product FROM "._DB_PREFIX_."product WHERE active=1");
$cart->updateQty(1,$idProduct);
$cart=new Cart($cart->id); $ctx->cart=$cart; $ctx->customer=$customer;
printf("cart %d total=%.2f\n",$cart->id,$cart->getOrderTotal(true,Cart::BOTH));

// Mint a token the way the browser does.
$siteId=(string)Configuration::get("INOVIOPAYMENT_SITE_ID"); $siteKey=(string)Configuration::get("INOVIOPAYMENT_SITE_KEY");
$u=bin2hex(random_bytes(16)); $ts=Tokenize::timestamp(); $sig=Tokenize::signRequest($siteKey,$ts,$u,$siteId);
$ch=curl_init(Module::getInstanceByName("inoviopayment")->getTokenEndpoint());
curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>30,
 CURLOPT_POSTFIELDS=>http_build_query(["card_pan"=>"4111111111111111","card_cvv"=>"123","request_response_format"=>"json","request_api_version"=>"4.14","site_id"=>$siteId,"unique_id"=>$u]),
 CURLOPT_HTTPHEADER=>["Content-Type: application/x-www-form-urlencoded","X-timestamp: $ts","X-signature: $sig"]]);
$tok=json_decode(curl_exec($ch),true); curl_close($ch);

// THE MODULE BUILDS THE REQUEST — this is what we are testing.
$payment=["token_guid"=>$tok["TOKEN_GUID"],"pmt_expiry"=>"122030","cc_brand"=>"VI","cc_last4"=>"1111",
  "ddc_reference_id"=>"","browser"=>"","save_card"=>true,"saved_card_id"=>0,"saved_card"=>null];
$req = InovioGateway::buildRequest($cart,$payment);
$res = InovioGateway::client()->sale($req);
printf("module sale: %s po=%s amt=%s\n",$res->status,$res->orderRef?->poId()??"-",$res->amount?->amount()??"-");
if ($res->status!=="APPROVED") { echo "advice: ".(InovioGateway::advice($res)??"-")."\n"; exit(1); }

// Create the order exactly as validation.php does.
$m = Module::getInstanceByName("inoviopayment");
$m->validateOrder((int)$cart->id,(int)Configuration::get("PS_OS_PAYMENT"),
  (float)$res->amount->amount(),$m->displayName,null,
  ["transaction_id"=>$res->transactionId?->value()??""],(int)$cart->id_currency,false,$customer->secure_key);
$order=new Order((int)$m->currentOrder);
InovioGateway::recordReferences($order,$res);
printf("order %d state=%d total_paid=%.2f\n",$order->id,(int)$order->getCurrentState(),(float)$order->total_paid);
printf("stored po_id=%s trans_id=%s\n",InovioGateway::getOrderRefValue($order,"po_id"),InovioGateway::getOrderRefValue($order,"trans_id"));

// Vault
$card = InovioVault::saveFromResult((int)$customer->id,1,$res,"122030","VI","1111");
printf("vault: %s\n", $card ? "saved id={$card->id} ".$card->getMaskedNumber()." ".$card->getExpiryLabel() : "NOT SAVED");
printf("vault list count=%d\n", count(InovioStoredCard::getByCustomer((int)$customer->id,1)));

// Refund through the module (CREDIT_ON_FAIL contract). A partial refund
// throws when the gateway reports SERVICE_NOT_SETTLED (536); that throw is
// the expected diagnostic signal in sandbox, where the sale has typically
// not settled yet, so it is reported rather than retried as a full refund.
try {
    $rf = InovioGateway::refundOrderPartial($order, "5.00");
    printf("module partial refund: %s amt=%s\n", $rf->status, $rf->amount?->amount() ?? "-");
} catch (\Throwable $e) {
    printf("module partial refund EXCEPTION: %s\n", $e->getMessage());
}
echo "ORDER_ID=".$order->id." CUSTOMER_ID=".$customer->id."\n";
