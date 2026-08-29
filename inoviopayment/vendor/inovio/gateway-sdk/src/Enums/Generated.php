<?php

declare(strict_types=1);

namespace Inovio\Gateway\Enums;

/**
 * GENERATED FILE — DO NOT EDIT.
 *
 * Source: Inovio Gateway Payments Service API v4.14 (spec/spec-enums.json)
 * Regenerate: python3 scripts/generate_enums.py
 *
 * Classifiers (retryable/terminal/stopRecurring, AVS/CVV classification and
 * the API-code -> exception mapping) are DERIVED by the SDK project, not
 * stated in the spec. See spec/README.md.
 */
final class Generated
{
    public const SPEC_API_VERSION = '4.14';

    /** Appendix B — the master transaction lifecycle (5 states). */
    public const TRANSACTION_STATUSES = [
        'APPROVED' => 'Transaction has been approved.',
        'DECLINED' => 'Transaction has been declined.',
        'PENDING' => 'Transaction is in pending status (expected on 3-D Secure, and preauthorization of online check transactions (i.e. Boleto, ACH, Pix etc.)).',
        'RUNNING' => 'Transaction processing is not completed or is waiting completion.',
        'FAILED' => 'Transaction did not finish payment completion (used in European Direct Debit transactions)',
    ];

    /** Appendix A — every REQUEST_ACTION the gateway accepts. */
    public const REQUEST_ACTIONS = [
        'ACHAUTHCAP' => 'record. Request a transaction for Electronic Funds Transfer',
        'ACHAUTHORIZE' => 'Authorize/Validate Check without funds transfer',
        'ACHREVERSE' => 'Used for Authorization Capture Reversal',
        'ACHCREDIT' => 'Used for transaction credit requests.',
        'APPLEPAYCONFIG' => 'Instructs the endpoint to provide Apple Pay Configuration',
        'CCAUTHORIZE' => 'Used for sending transaction authorization-only requests.',
        'CCCAPTURE' => 'Used for sending transaction capture previous authorization',
        'CCAUTHCAP' => 'requests. Used for sending transaction “authorization and capture”',
        'CCREVERSE' => 'requests. Used for sending transaction reversal or void requests. Sending this will reverse the original authorization.',
        'CCREVERSECAP' => 'For reversing CCCAPTURE transactions, merchants should use as the request action.',
        'CCCREDIT' => '“CCCAPTURE” transaction. Used for issuing transaction returns or credits.',
        'CCRDR' => 'Used for RDR Dispute Processing',
        'CCRDRDELETE' => 'Used for RDR Dispute Processing to show a case that has been',
        'CCTC40' => 'removed by the customer/issuer Used for TC40 Alerts Processing',
        'CCSTATUS' => 'Used for checking the status of a previous transaction or order.',
        'CCTRANSUPDATE' => 'Used to add receipts on transaction that was previously run',
        'DBTAUTHORIZE' => '(approved or declined) Used for preparing Mandate without charging',
        'DBTCAPTURE' => 'Used to charge the Mandate for the submitted amount',
        'DBTCREDIT' => 'Used for SEPA Direct Debit Refund/Credit request',
        'DBTDEBIT' => 'Used for SEPA Direct Debit Pay Immediately \'Pay Now\'',
        'DBTREVERSE' => 'Used for canceling existing mandate and end subscription',
        'GOOGLEPAYCONFIG' => 'Instructs the endpoint to provide Google Pay Configuration',
        'TESTGW' => 'Used for testing gateway availability.',
        'TESTAUTH' => 'Used for testing basic authentication.',
        'SUB_CANCEL' => 'Used for requesting cancelation of an active membership record.',
        'SUB_UPDATE' => 'Used for updating the Product ID of an existing membership',
        'BOLETOAUTHCAP' => 'Used for Brazilian Boleto Payment type',
        'PIXSALE' => 'Used for Brazilian Pix Payment type',
        'PAGSALE' => 'Used for Peru’s PagoEfectivo Payment type',
    ];

    /** Appendix D — service response codes + decline taxonomy. */
    public const SERVICE_RESPONSE_CODES = [
        100 => ['code' => 100, 'description' => 'User Authorized', 'retryable' => false, 'stopRecurring' => false, 'approval' => true, 'terminal' => false],
        101 => ['code' => 101, 'description' => 'Service Available', 'retryable' => false, 'stopRecurring' => false, 'approval' => true, 'terminal' => false],
        102 => ['code' => 102, 'description' => 'Membership Updated', 'retryable' => false, 'stopRecurring' => false, 'approval' => true, 'terminal' => false],
        150 => ['code' => 150, 'description' => 'Product Not Found', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        152 => ['code' => 152, 'description' => 'Product Type Not Found', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        155 => ['code' => 155, 'description' => 'Selected currency not configured', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        157 => ['code' => 157, 'description' => 'MID has RDR Status OFF', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        190 => ['code' => 190, 'description' => 'Invalid Product Configuration', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        192 => ['code' => 192, 'description' => 'Product Not Active', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        200 => ['code' => 200, 'description' => 'CVV required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        201 => ['code' => 201, 'description' => 'Country required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        202 => ['code' => 202, 'description' => 'DOB required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        203 => ['code' => 203, 'description' => 'SSN required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        204 => ['code' => 204, 'description' => 'Address required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        205 => ['code' => 205, 'description' => 'City required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        206 => ['code' => 206, 'description' => 'State required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        207 => ['code' => 207, 'description' => 'Postal Code required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        208 => ['code' => 208, 'description' => 'Phone required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        209 => ['code' => 209, 'description' => 'IP required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        210 => ['code' => 210, 'description' => 'CPF required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        211 => ['code' => 211, 'description' => 'Email required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        212 => ['code' => 212, 'description' => 'FName required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        213 => ['code' => 213, 'description' => 'LName required by processor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        215 => ['code' => 215, 'description' => 'Activity limit exceeded', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        216 => ['code' => 216, 'description' => 'Invalid amount', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        217 => ['code' => 217, 'description' => 'No such issuer', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        218 => ['code' => 218, 'description' => 'Wrong PIN entered', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        219 => ['code' => 219, 'description' => 'R0: Stop recurring payments', 'retryable' => false, 'stopRecurring' => true, 'approval' => false, 'terminal' => true],
        220 => ['code' => 220, 'description' => 'R1: Stop recurring payments', 'retryable' => false, 'stopRecurring' => true, 'approval' => false, 'terminal' => true],
        221 => ['code' => 221, 'description' => 'System malfunction', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        500 => ['code' => 500, 'description' => 'No merchant account configured', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        501 => ['code' => 501, 'description' => 'Customer not found', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        502 => ['code' => 502, 'description' => 'Transaction error', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        503 => ['code' => 503, 'description' => 'Service Unavailable', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        505 => ['code' => 505, 'description' => 'Order adjusted to zero', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        506 => ['code' => 506, 'description' => 'Capture amount exceeds order value', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        507 => ['code' => 507, 'description' => 'Order fully captured', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        510 => ['code' => 510, 'description' => 'Order already reversed', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        511 => ['code' => 511, 'description' => 'Order already charged back', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        512 => ['code' => 512, 'description' => 'Order not found', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        515 => ['code' => 515, 'description' => 'Order fully credited', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        516 => ['code' => 516, 'description' => 'Credit amount exceeds order value', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        518 => ['code' => 518, 'description' => 'Missing required field', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        520 => ['code' => 520, 'description' => 'Unsupported Currency', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        522 => ['code' => 522, 'description' => 'Unsupported card brand', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        525 => ['code' => 525, 'description' => 'Batch Closed: Please credit', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        526 => ['code' => 526, 'description' => 'ApplePay is not supported on this merch_acct_id', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        527 => ['code' => 527, 'description' => 'No ApplePay merch_acct_id configured', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        528 => ['code' => 528, 'description' => 'ApplePay MCC Restricted', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        530 => ['code' => 530, 'description' => 'Downstream Processor Unavailable', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        536 => ['code' => 536, 'description' => 'Order not settled: Please reverse', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        540 => ['code' => 540, 'description' => 'Maximum Auth Limit Exceeded', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        546 => ['code' => 546, 'description' => 'GooglePay MCC Restricted', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        547 => ['code' => 547, 'description' => 'No GooglePay merch_acct_id configured', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        548 => ['code' => 548, 'description' => 'GooglePay is not supported on this merch_acct_id', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        555 => ['code' => 555, 'description' => 'Call Center', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        560 => ['code' => 560, 'description' => 'Invalid Service Action', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        564 => ['code' => 564, 'description' => 'Invalid Terminal', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        565 => ['code' => 565, 'description' => 'Invalid Amount', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        570 => ['code' => 570, 'description' => 'Invalid Card Type', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        580 => ['code' => 580, 'description' => 'Unsupported Request', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        600 => ['code' => 600, 'description' => 'Declined', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        601 => ['code' => 601, 'description' => 'Scrub Decline', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        603 => ['code' => 603, 'description' => 'Fraud', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        605 => ['code' => 605, 'description' => 'Stolen Card', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        610 => ['code' => 610, 'description' => 'Pickup Card', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        615 => ['code' => 615, 'description' => 'Lost Card', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        620 => ['code' => 620, 'description' => 'Invalid CVV', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        621 => ['code' => 621, 'description' => 'Failed CVV', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        622 => ['code' => 622, 'description' => 'Invalid AVS', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        623 => ['code' => 623, 'description' => 'Failed AVS', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        624 => ['code' => 624, 'description' => 'Expired Card', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        625 => ['code' => 625, 'description' => 'Excessive Use', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        630 => ['code' => 630, 'description' => 'Invalid Card Number', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        635 => ['code' => 635, 'description' => 'Insufficient Funds', 'retryable' => true, 'stopRecurring' => false, 'approval' => false, 'terminal' => false],
        640 => ['code' => 640, 'description' => 'Retry', 'retryable' => true, 'stopRecurring' => false, 'approval' => false, 'terminal' => false],
        650 => ['code' => 650, 'description' => 'Do Not Honor', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        660 => ['code' => 660, 'description' => 'Partial Approval', 'retryable' => true, 'stopRecurring' => false, 'approval' => false, 'terminal' => false],
        670 => ['code' => 670, 'description' => 'Additional Authentication Required', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        675 => ['code' => 675, 'description' => 'Invalid Card Number, failed Mod 10 validation', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        680 => ['code' => 680, 'description' => 'Duplicate Transaction Detected', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        685 => ['code' => 685, 'description' => 'Duplicate Order Detected', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        690 => ['code' => 690, 'description' => 'Active Membership Exists', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        692 => ['code' => 692, 'description' => 'Invalid Rebill Product', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        695 => ['code' => 695, 'description' => 'Site Username Unavailable', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        697 => ['code' => 697, 'description' => 'Membership Not Active', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        698 => ['code' => 698, 'description' => 'Membership Not Found', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        699 => ['code' => 699, 'description' => 'Membership Not Set for Rebill', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        700 => ['code' => 700, 'description' => 'Scrub Decline', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        706 => ['code' => 706, 'description' => 'Failed Age Validation', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
        707 => ['code' => 707, 'description' => 'Invalid CPF', 'retryable' => false, 'stopRecurring' => false, 'approval' => false, 'terminal' => true],
    ];

    /** Appendix C — gateway request-validation codes. */
    public const API_RESPONSE_CODES = [
        100 => ['code' => 100, 'description' => 'Invalid login information (throttle)', 'recommendation' => 'Check your login credentials and try again. If you continue to receive this response, contact Client Support', 'mapsToException' => 'RateLimitException', 'carriesRefField' => false],
        101 => ['code' => 101, 'description' => 'Invalid login information', 'recommendation' => 'Check your login credentials and try again. If you continue to receive this response, contact Client Support', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        102 => ['code' => 102, 'description' => 'User not active', 'recommendation' => 'These credentials have been disabled. If you think this is an error, contact Client Support', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        103 => ['code' => 103, 'description' => 'Invalid site', 'recommendation' => 'The value of SITE_ID does not exist, or it does not match the authentication credentials provided.', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        104 => ['code' => 104, 'description' => 'Invalid service', 'recommendation' => 'Check the value of request_action to confirm it is correct.', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        105 => ['code' => 105, 'description' => 'Invalid service action', 'recommendation' => 'Check the value of request_action to confirm it is correct.', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        106 => ['code' => 106, 'description' => 'Invalid service object', 'recommendation' => 'Check the value of request_object to confirm it is correct.', 'mapsToException' => 'AuthenticationException', 'carriesRefField' => false],
        110 => ['code' => 110, 'description' => 'Required field', 'recommendation' => 'A required key/value pair has not been included in the request. In the response, check the value of REF_FIELD to see what is missing', 'mapsToException' => 'ValidationException', 'carriesRefField' => true],
        111 => ['code' => 111, 'description' => 'Invalid length', 'recommendation' => 'The length of a value is too short or long. Check the returned value of REF_FIELD to see which field may need editing', 'mapsToException' => 'ValidationException', 'carriesRefField' => true],
        112 => ['code' => 112, 'description' => 'Not numeric', 'recommendation' => 'Numeric data is expected. Confirm the amount sent for LI_VALUE_x, which should only contain numerals and one decimal Something in the request was not', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        113 => ['code' => 113, 'description' => 'Invalid Data', 'recommendation' => 'expected. Check the values that were submitted for unusual characters, spaces, or null values where there perhaps should not be', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        115 => ['code' => 115, 'description' => 'Customer not found', 'recommendation' => 'If CUST_ID or CUST_ID_XTL was submitted, check these values and try again. If this response has come from a request without these parameters, contact Client Support', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        116 => ['code' => 116, 'description' => 'User MUST change password', 'recommendation' => 'User passwords expire every 90 days. This does not apply to API credentials.', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        118 => ['code' => 118, 'description' => 'New password must not match the previous 5 passwords', 'recommendation' => 'Try a different password.', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        119 => ['code' => 119, 'description' => 'request_ref_po_id and request_po_li_id mismatch', 'recommendation' => 'The order ID and the line item ID do not relate to one another. Check the order information.', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        120 => ['code' => 120, 'description' => 'System Error', 'recommendation' => 'Contact Client Support', 'mapsToException' => 'ValidationException', 'carriesRefField' => false],
        125 => ['code' => 125, 'description' => 'Duplicate Login', 'recommendation' => 'This email address, a unique identifier, already exists.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        130 => ['code' => 130, 'description' => 'Same Product ID found on different line items.', 'recommendation' => 'Check the values of LI_PROD_ID_x. Each one should have a unique ID. If the intent is to submit a purchase for multiples of the same product use LI_COUNT_x to indicate the quantity.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        135 => ['code' => 135, 'description' => 'Duplicate Company Name', 'recommendation' => 'This company name is already in the system. If you are certain it doesn\'t already exist in the system, it could be a company with the same name, but doing business in a different region. Contact Client Support for assistance.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        136 => ['code' => 136, 'description' => 'Duplicate Site Name', 'recommendation' => 'This site name already exists in our system.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        150 => ['code' => 150, 'description' => 'Product Not Found', 'recommendation' => 'The product ID is not valid. It may not exist, or it might be associated with another site. Check', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        152 => ['code' => 152, 'description' => 'Product Type Not Found', 'recommendation' => 'The value for PROD_TYPE is not valid.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        153 => ['code' => 153, 'description' => 'Duplicate XTL product id', 'recommendation' => 'This value is already in the system. To confirm and review, the ID can be searched for in our', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        155 => ['code' => 155, 'description' => 'Selected currency not configured', 'recommendation' => 'Check the merchant account configuration in the portal.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        160 => ['code' => 160, 'description' => 'Invalid product amount', 'recommendation' => 'Check the value of LI_VALUE_x to confirm it is the intended amount.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        165 => ['code' => 165, 'description' => 'Currency not supported', 'recommendation' => 'Check the merchant account configuration in the portal. The MID\'s allowed currencies can be configured there. Additionally, check the value of PROCESSOR_RESPONSE in the', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        170 => ['code' => 170, 'description' => 'Duplicate product amount and currency', 'recommendation' => 'A product with matching properties already exists within the site.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        176 => ['code' => 176, 'description' => 'Duplicate product description and language', 'recommendation' => 'A product with matching properties already exists within this Site', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        180 => ['code' => 180, 'description' => 'Invalid transaction limit type', 'recommendation' => 'The limit type was not recognized. Try using the portal to adjust velocity settings.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        181 => ['code' => 181, 'description' => 'Invalid limit type', 'recommendation' => 'The limit type was not recognized. Try using the portal to adjust velocity settings.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        183 => ['code' => 183, 'description' => 'Payment Type is required', 'recommendation' => 'Confirm that PMT_TYPE has been submitted, and has not been included multiple times.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        205 => ['code' => 205, 'description' => 'No Permissions on requested object', 'recommendation' => 'You may not be able to check and confirm your own user permissions, so it may be necessary for an administrator to check them for you. If', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        210 => ['code' => 210, 'description' => 'Merchant Account not found', 'recommendation' => 'you feel this is an error, contact your administrator or Client Support. Verify the value of MERCH_ACCT_ID', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        211 => ['code' => 211, 'description' => 'Currency not found', 'recommendation' => 'The expected format is three-character currency code.', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        215 => ['code' => 215, 'description' => 'Invalid Card Brand', 'recommendation' => 'Check the card brand submitted. If you are certain it’s correct, contact Client Support', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        410 => ['code' => 410, 'description' => 'Field not supported with wallet payment', 'recommendation' => 'Check the value of REF_FIELD in the response to see what incompatible element was', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => true],
        411 => ['code' => 411, 'description' => 'REQUEST_CURRENCY mismatch with Cryptogram', 'recommendation' => 'The currency in the gateway request needs to match the currency that was packed into the ApplePay cryptogram', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
        414 => ['code' => 414, 'description' => 'GooglePay token has expired', 'recommendation' => '', 'mapsToException' => 'ConfigurationException', 'carriesRefField' => false],
    ];

    /**
     * Appendix E — AVS codes. 'classification' is DERIVED, not from the spec:
     * positive | partial | negative | neutral. 'partial' means some elements
     * matched and some did not — whether that is acceptable is a merchant
     * risk-policy decision, not a spec fact.
     */
    public const AVS_CODES = [
        'A' => ['code' => 'A', 'description' => 'Street address matches, but 5-digit and 9-digit postal code do not match.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'partial'],
        'B' => ['code' => 'B', 'description' => 'Street address matches, but postal code not verified.', 'cardNetwork' => 'Standard international', 'classification' => 'neutral'],
        'C' => ['code' => 'C', 'description' => 'Street address and postal code do not match.', 'cardNetwork' => 'Standard international', 'classification' => 'negative'],
        'D' => ['code' => 'D', 'description' => 'Street address and postal code match. Code "M" is equivalent.', 'cardNetwork' => 'Standard international', 'classification' => 'positive'],
        'E' => ['code' => 'E', 'description' => 'AVS data is invalid or AVS is not allowed for this card type.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'neutral'],
        'F' => ['code' => 'F', 'description' => 'Card member\'s name does not match, but billing postal code matches.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'G' => ['code' => 'G', 'description' => 'Non-U.S. issuing bank does not support AVS.', 'cardNetwork' => 'Standard international', 'classification' => 'neutral'],
        'H' => ['code' => 'H', 'description' => 'Card member\'s name does not match. Street address and postal code match.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'I' => ['code' => 'I', 'description' => 'Address not verified.', 'cardNetwork' => 'Standard international', 'classification' => 'neutral'],
        'J' => ['code' => 'J', 'description' => 'Card member\'s name, billing address, and postal code match.', 'cardNetwork' => 'American Express only', 'classification' => 'positive'],
        'K' => ['code' => 'K', 'description' => 'Card member\'s name matches but billing address and billing postal code do not match.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'L' => ['code' => 'L', 'description' => 'Card member\'s name and billing postal code match, but billing address does not match.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'M' => ['code' => 'M', 'description' => 'Street address and postal code match. Code "D" is equivalent.', 'cardNetwork' => 'Standard international', 'classification' => 'positive'],
        'N' => ['code' => 'N', 'description' => 'Street address and postal code do not match.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'negative'],
        'O' => ['code' => 'O', 'description' => 'Card member\'s name and billing address match, but billing postal code does not match.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'P' => ['code' => 'P', 'description' => 'Postal code matches, but street address not verified.', 'cardNetwork' => 'Standard international', 'classification' => 'neutral'],
        'Q' => ['code' => 'Q', 'description' => 'Card member\'s name, billing address, and postal code match.', 'cardNetwork' => 'American Express only', 'classification' => 'positive'],
        'R' => ['code' => 'R', 'description' => 'System unavailable.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'neutral'],
        'S' => ['code' => 'S', 'description' => 'Bank does not support AVS.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'neutral'],
        'T' => ['code' => 'T', 'description' => 'Card member\'s name does not match, but street address matches.', 'cardNetwork' => 'American Express only', 'classification' => 'partial'],
        'U' => ['code' => 'U', 'description' => 'Address information unavailable. Returned if the U.S. bank does not support non-U.S. AVS or if the AVS in a U.S. bank is not functioning properly.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'neutral'],
        'V' => ['code' => 'V', 'description' => 'Card member\'s name, billing address, and billing postal code match.', 'cardNetwork' => 'American Express only', 'classification' => 'positive'],
        'W' => ['code' => 'W', 'description' => 'Street address does not match, but 9-digit postal code matches.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'partial'],
        'X' => ['code' => 'X', 'description' => 'Street address and 9-digit postal code match.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'positive'],
        'Y' => ['code' => 'Y', 'description' => 'Street address and 5-digit postal code match.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'positive'],
        'Z' => ['code' => 'Z', 'description' => 'Street address does not match, but 5-digit postal code matches.', 'cardNetwork' => 'Standard domestic (US)', 'classification' => 'partial'],
    ];

    /** Appendix F — CVV codes. 'classification' is DERIVED. */
    public const CVV_CODES = [
        'M' => ['code' => 'M', 'description' => 'Match', 'classification' => 'match'],
        'N' => ['code' => 'N', 'description' => 'No Match', 'classification' => 'no_match'],
        'P' => ['code' => 'P', 'description' => 'Not Processed', 'classification' => 'neutral'],
        'S' => ['code' => 'S', 'description' => 'Not Supported', 'classification' => 'neutral'],
        'U' => ['code' => 'U', 'description' => 'Service Not Available', 'classification' => 'neutral'],
        'X' => ['code' => 'X', 'description' => 'No CVC/CVV/CVV2/CID Response Data Available', 'classification' => 'neutral'],
        '' => ['code' => '', 'description' => 'No CVC/CVV/CVV2/CID Response Data Available', 'classification' => 'neutral'],
    ];
}
