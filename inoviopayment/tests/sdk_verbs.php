<?php
require_once "/var/www/html/config/config.inc.php";
require_once "/var/www/html/modules/inoviopayment/vendor/inovio/autoload.php";
require_once "/var/www/html/modules/inoviopayment/classes/InovioGateway.php";
use Inovio\Gateway\Tokenize;
use Inovio\Gateway\Model\{LineItem, Money, PaymentMethods, Customer as SdkCustomer, Address as SdkAddress};
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Refs\Refs;

function mintToken(): string {
  $siteId=(string)Configuration::get("INOVIOPAYMENT_SITE_ID");
  $siteKey=(string)Configuration::get("INOVIOPAYMENT_SITE_KEY");
  $u=bin2hex(random_bytes(16)); $ts=Tokenize::timestamp();
  $sig=Tokenize::signRequest($siteKey,$ts,$u,$siteId);
  $url=Module::getInstanceByName("inoviopayment")->getTokenEndpoint();
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>30,
    CURLOPT_POSTFIELDS=>http_build_query(["card_pan"=>"4111111111111111","card_cvv"=>"123",
      "request_response_format"=>"json","request_api_version"=>"4.14","site_id"=>$siteId,"unique_id"=>$u]),
    CURLOPT_HTTPHEADER=>["Content-Type: application/x-www-form-urlencoded","X-timestamp: $ts","X-signature: $sig"]]);
  $r=json_decode(curl_exec($ch),true); curl_close($ch);
  if (empty($r["TOKEN_GUID"])) throw new RuntimeException("tokenize failed");
  return $r["TOKEN_GUID"];
}
function req(string $guid, string $amt): TransactionRequest {
  $r = new TransactionRequest(PaymentMethods::token($guid,"122030"),
    [new LineItem((string)Configuration::get("INOVIOPAYMENT_PRODUCT_ID"),1,Money::of($amt,"USD"))]);
  $r->withIdempotency("PS-V-".bin2hex(random_bytes(4)));
  $r->merchAcctId=(string)Configuration::get("INOVIOPAYMENT_MERCH_ACCT_ID");
  $c=new SdkCustomer(); $c->firstName="API"; $c->lastName="Test"; $c->email="apitest@inovio.local"; $c->ip="127.0.0.1";
  $r->customer=$c;
  $a=new SdkAddress(); $a->line1="123 Test St"; $a->city="Las Vegas"; $a->state="NV"; $a->zip="89101"; $a->country="US";
  $r->billingAddress=$a;
  return $r;
}
$c = InovioGateway::client();
function show(string $label,$res){ printf("%-26s %-9s po=%-10s trans=%-11s amt=%-7s %s\n",$label,$res->status,
  $res->orderRef?->poId()??"-", $res->transactionId?->value()??"-", $res->amount?->amount()??"-",
  InovioGateway::advice($res)??""); }

// 1. AUTHORIZE -> CAPTURE (full)
$a1 = $c->authorize(req(mintToken(),"25.00")); show("1a authorize",$a1);
$cap = $c->capture(Refs::order($a1->orderRef->poId())); show("1b capture full",$cap);

// 2. AUTHORIZE -> VOID
$a2 = $c->authorize(req(mintToken(),"30.00")); show("2a authorize",$a2);
$void = $c->reverse(Refs::order($a2->orderRef->poId())); show("2b void",$void);

// 3. SALE -> REFUND. NOTE: refund() on an UNSETTLED order correctly returns
// SERVICE 536 "Order not settled: Please reverse" — this raw-SDK script
// exercises that path deliberately. The module itself uses the
// settlement-aware InovioGateway::refundOrder(), which reverses instead.
$s3 = $c->sale(req(mintToken(),"40.00")); show("3a sale",$s3);
$rp = $c->refund(Refs::order($s3->orderRef->poId()), Money::of("15.00","USD")); show("3b refund partial",$rp);
$rf = $c->refund(Refs::order($s3->orderRef->poId()), Money::of("25.00","USD")); show("3c refund remainder",$rf);

// 4. AUTHORIZE -> PARTIAL CAPTURE
$a4 = $c->authorize(req(mintToken(),"50.00")); show("4a authorize",$a4);
$pc = $c->capture(Refs::order($a4->orderRef->poId()), Money::of("20.00","USD")); show("4b capture partial",$pc);

// 5. STATUS lookup (timeout-recovery path)
$st = $c->status(Refs::order($s3->orderRef->poId()));
printf("%-26s legs=%d\n","5 status lookup",count($st->transactions));

// 6. SAVED CARD reuse (vault)
$s6 = $c->sale(req(mintToken(),"12.00")); show("6a sale (vault source)",$s6);
if ($s6->savedCardRef?->pmtId() && $s6->customerRef?->custId()) {
  $r6 = new TransactionRequest(
    PaymentMethods::savedCard($s6->savedCardRef->pmtId(), null, $s6->customerRef->custId()),
    [new LineItem((string)Configuration::get("INOVIOPAYMENT_PRODUCT_ID"),1,Money::of("7.00","USD"))]);
  $r6->withIdempotency("PS-V-".bin2hex(random_bytes(4)));
  $r6->merchAcctId=(string)Configuration::get("INOVIOPAYMENT_MERCH_ACCT_ID");
  $r6->customer=$s6 ? (function(){$c=new SdkCustomer();$c->firstName="API";$c->lastName="Test";$c->email="apitest@inovio.local";$c->ip="127.0.0.1";return $c;})() : null;
  show("6b saved-card charge",$c->sale($r6));
} else { echo "6b saved-card: NO REFS RETURNED\n"; }

// 7. DECLINE path
try { show("7 decline probe",$c->sale(req(mintToken(),"0.05"))); }
catch (\Throwable $e) { echo "7 decline probe            EXCEPTION: ".substr($e->getMessage(),0,70)."\n"; }
