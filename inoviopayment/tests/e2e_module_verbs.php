<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Module-level e2e: exercises the module's OWN code paths (InovioGateway,
 * InovioVault, custom order states, validateOrder) against a live gateway —
 * not the raw SDK. Run via a booted FrontKernel; see tests/README.md.
 */
require_once "/var/www/html/modules/inoviopayment/vendor/inovio/autoload.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioGateway.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioStoredCard.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioVault.php";
use Inovio\Gateway\Tokenize;

$ctx=Context::getContext();
$ctx->shop=new Shop(1); $ctx->language=new Language((int)Configuration::get("PS_LANG_DEFAULT"));
$ctx->currency=new Currency((int)Configuration::get("PS_CURRENCY_DEFAULT"));
$ctx->country=new Country((int)Configuration::get("PS_COUNTRY_DEFAULT"));
$m=Module::getInstanceByName("inoviopayment");

function mint(): string {
  $s=(string)Configuration::get("INOVIOPAYMENT_SITE_ID"); $k=(string)Configuration::get("INOVIOPAYMENT_SITE_KEY");
  $u=bin2hex(random_bytes(16)); $t=Tokenize::timestamp(); $g=Tokenize::signRequest($k,$t,$u,$s);
  $ch=curl_init(Module::getInstanceByName("inoviopayment")->getTokenEndpoint());
  curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>30,
   CURLOPT_POSTFIELDS=>http_build_query(["card_pan"=>"4111111111111111","card_cvv"=>"123","request_response_format"=>"json","request_api_version"=>"4.14","site_id"=>$s,"unique_id"=>$u]),
   CURLOPT_HTTPHEADER=>["Content-Type: application/x-www-form-urlencoded","X-timestamp: $t","X-signature: $g"]]);
  $r=json_decode(curl_exec($ch),true); curl_close($ch); return $r["TOKEN_GUID"] ?? "";
}
function newCart(&$ctx, int $reuse=0): array {
  if ($reuse) { $cu=new Customer($reuse); }
  else { $cu=new Customer(); $cu->firstname="Verb"; $cu->lastname="Tester";
    $cu->email="verb".bin2hex(random_bytes(4))."@inovio.local"; $cu->passwd=Tools::hash("Test123!"); $cu->add(); }
  $ia=(int)Db::getInstance()->getValue("SELECT id_address FROM "._DB_PREFIX_."address WHERE id_customer=".(int)$cu->id." AND deleted=0");
  if (!$ia) { $a=new Address(); $a->id_customer=$cu->id; $a->id_country=(int)Country::getByIso("US");
    $a->alias="Home"; $a->firstname="Verb"; $a->lastname="Tester"; $a->address1="123 Test St";
    $a->city="Las Vegas"; $a->postcode="89101"; $a->id_state=(int)State::getIdByIso("NV",(int)Country::getByIso("US")); $a->add(); $ia=(int)$a->id; }
  $c=new Cart(); $c->id_customer=$cu->id; $c->id_address_delivery=$ia; $c->id_address_invoice=$ia;
  $c->id_lang=(int)$ctx->language->id; $c->id_currency=(int)$ctx->currency->id; $c->id_shop=1; $c->id_carrier=0; $c->add();
  $ctx->cart=$c; $ctx->customer=$cu;
  $c->updateQty(1,(int)Db::getInstance()->getValue("SELECT id_product FROM "._DB_PREFIX_."product WHERE active=1"));
  $c=new Cart($c->id); $ctx->cart=$c; return [$c,$cu];
}
function place(Cart $cart, Customer $cu, array $p, string $action, Module $m): array {
  Configuration::updateValue("INOVIOPAYMENT_PAYMENT_ACTION",$action);
  $req=InovioGateway::buildRequest($cart,$p);
  $res=$action==="authorize"?InovioGateway::client()->authorize($req):InovioGateway::client()->sale($req);
  if ($res->status!=="APPROVED") return [null,$res];
  $state=$action==="authorize"?(int)Configuration::getGlobalValue(Inoviopayment::STATE_AWAITING_CAPTURE):(int)Configuration::get("PS_OS_PAYMENT");
  $m->validateOrder((int)$cart->id,$state,(float)$res->amount->amount(),$m->displayName,null,
    ["transaction_id"=>$res->transactionId?->value()??""],(int)$cart->id_currency,false,$cu->secure_key);
  $o=new Order((int)$m->currentOrder); InovioGateway::recordReferences($o,$res); return [$o,$res];
}
$tok=fn()=>["token_guid"=>mint(),"pmt_expiry"=>"122030","cc_brand"=>"VI","cc_last4"=>"1111",
  "ddc_reference_id"=>"","browser"=>"","save_card"=>false,"saved_card_id"=>0,"saved_card"=>null];

[$c1,$u1]=newCart($ctx); [$o1,]=place($c1,$u1,$tok(),"authorize",$m);
printf("A1 authorize      order=%d state=%d (awaiting-capture)\n",$o1->id,(int)$o1->getCurrentState());
printf("A2 capture full   %s\n",InovioGateway::captureOrder($o1)->status);
[$c2,$u2]=newCart($ctx); [$o2,]=place($c2,$u2,$tok(),"authorize",$m);
printf("B  void           %s\n",InovioGateway::voidOrder($o2)->status);
[$c3,$u3]=newCart($ctx); $p3=$tok(); $p3["save_card"]=true; [$o3,$r3]=place($c3,$u3,$p3,"sale",$m);
$card=InovioVault::saveFromResult((int)$u3->id,1,$r3,"122030","VI","1111");
printf("C1 sale+vault     order=%d card=%s\n",$o3->id,$card?"id=".$card->id:"NOT SAVED");
[$c4,]=newCart($ctx,(int)$u3->id);
[$o4,$r4]=place($c4,$u3,["token_guid"=>"","pmt_expiry"=>"","cc_brand"=>"","cc_last4"=>"","ddc_reference_id"=>"",
  "browser"=>"","save_card"=>false,"saved_card_id"=>(int)$card->id,"saved_card"=>$card],"sale",$m);
printf("C2 saved-card     %s order=%s\n",$r4->status,$o4?$o4->id:"-");
[$c5,$u5]=newCart($ctx); [$o5,]=place($c5,$u5,$tok(),"authorize",$m);
printf("D  partial cap    %s amt=%s\n",($x=InovioGateway::captureOrder($o5,"5.00"))->status,$x->amount?->amount()??"-");
// E1: partial refund on o3 (sale — captured but, in sandbox, typically not yet
// settled). InovioGateway::refundOrderPartial() throws when the gateway
// answers SERVICE_NOT_SETTLED (536); that throw IS the expected diagnostic
// signal here, so it is reported rather than retried as a full refund.
try {
  $y=InovioGateway::refundOrderPartial($o3,"5.00");
  printf("E1 partial refund %s amt=%s\n",$y->status,$y->amount?->amount()??"-");
} catch (\Throwable $e) {
  printf("E1 partial refund EXCEPTION: %s\n",$e->getMessage());
}
// E2: full refund on o3 — reverseCapture(creditOnFail: true) handles both the
// unsettled (reverse) and settled (auto-credit) cases gateway-side.
printf("E2 full refund    %s amt=%s\n",($z=InovioGateway::refundOrderFull($o3))->status,$z->amount?->amount()??"-");
Configuration::updateValue("INOVIOPAYMENT_PAYMENT_ACTION","sale");
