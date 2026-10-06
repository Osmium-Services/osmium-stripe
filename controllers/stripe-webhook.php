<?php

use Osmium\Services\Stripe\Models\StripeConfig;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;

require_once __DIR__ . '/../../../modules/checkout/services/ShopTotalsService.php';
require_once __DIR__ . '/../../../modules/checkout/services/CheckoutBasketService.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderException.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderService.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderMailer.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * Stripe webhook.
 *
 * The safety net, for the same reason PayPal's webhook is: if a customer's
 * browser dies between confirming a payment and our confirmation call
 * returning, the browser flow records nothing — and that is money taken with
 * no order behind it. This endpoint is how that order still gets marked paid.
 *
 * It is unauthenticated by nature, so the signature check is the only thing
 * standing between Stripe and anybody who can POST JSON at us. An event that
 * fails verification is discarded, never treated as probably genuine.
 *
 * Can be tested without HTTPS: the Stripe CLI forwards real signed events to
 * a local URL —
 *
 *   stripe listen --forward-to yourapp.test:8085/api/checkout-stripe-webhook
 *
 * Query strings are stripped by RedirectHandler, so nothing here may depend on
 * them — everything comes from the POST body.
 */

$rawBody = \file_get_contents('php://input');

$stripe = StripeConfig::client();

if (!$stripe->isEnabled()) {
    \http_response_code(503);
    exit;
}

$headers = \function_exists('getallheaders') ? \getallheaders() : [];
$normalisedHeaders = [];
foreach ($headers as $name => $value) $normalisedHeaders[\strtoupper($name)] = $value;

// Verified against the body exactly as received. Decoding first and re-encoding
// would change the bytes and every signature would fail.
$signatureValid = $stripe->verifyWebhookSignature(headers: $normalisedHeaders, rawBody: (string)$rawBody);

if (!$signatureValid) {
    \error_log('Stripe webhook rejected: signature did not verify');
    \http_response_code(400);
    exit;
}

$event = \json_decode(json: (string)$rawBody, associative: true) ?? [];
$eventType = (string)($event['type'] ?? '');
$object = $event['data']['object'] ?? [];

$sqlDir = 'app/modules/checkout/models/sql/';

$finance = $this->osmium->finance();
$basketService = new CheckoutBasketService(dataSource: $this->osmium->dataSource, sqlDir: $sqlDir);
$orderService = new ShopOrderService(
    dataSource: $this->osmium->dataSource,
    sqlDir: $sqlDir,
    basket: $basketService,
    totals: ShopTotalsService::fromConfig($this->osmium->config->checkout, $finance),
    finance: $finance,
);

/**
 * Find our order from an event object.
 *
 * A PaymentIntent event carries its own id, which is what we stored. A charge
 * event carries the intent id instead, so both shapes lead back to the same row.
 */
$paymentIntentId = (string)($object['id'] ?? '');

$isChargeEvent = \str_starts_with($eventType, 'charge.');
if ($isChargeEvent) $paymentIntentId = (string)($object['payment_intent'] ?? '');

$order = $paymentIntentId === '' ? null : $orderService->findByPaymentReference($paymentIntentId);

if (!$order) {
    \error_log('Stripe webhook ' . $eventType . ' could not be matched to an order');
    \http_response_code(200); // Acknowledged: retrying will not help, and an unacknowledged event is retried for days
    exit;
}

$orderId = (int)$order['id'];

switch ($eventType) {
    case 'payment_intent.succeeded':
        $payment = $stripe->normaliseIntent($object);

        // The same check the browser path makes, for the same reason: a payment
        // that does not match the order we priced is not marked paid, it is
        // left pending for a human.
        $expectedAmount = (float)$order['total_inc_tax'];
        $amountMismatch = \abs($payment['amount'] - $expectedAmount) >= 0.005;
        $currencyMismatch = $payment['currency'] !== $order['currency'];

        if ($amountMismatch || $currencyMismatch) {
            \error_log("Stripe webhook mismatch on order {$order['order_ref']}: {$payment['amount']} {$payment['currency']}, expected {$expectedAmount} {$order['currency']}");
            \http_response_code(200); // Acknowledged — retrying will not fix a mismatch
            exit;
        }

        $invoiceNumber = $orderService->markPaid(
            orderId: $orderId,
            captureRef: $payment['capture_ref'],
            payerEmail: $payment['payer_email'],
        ); // Idempotent: if the browser already confirmed this, it does nothing

        $wasAlreadyPaid = $invoiceNumber === null;
        if ($wasAlreadyPaid) break;

        \error_log("Stripe webhook completed order {$order['order_ref']} that the browser did not confirm"); // Worth knowing about: it means a customer saw an error on a payment that actually succeeded

        $mailer = new ShopOrderMailer($this->osmium->config, $finance);
        $mailer->sendOrderConfirmation($orderService->getOrder($orderId), $invoiceNumber);
        break;

    case 'payment_intent.payment_failed':
        $orderService->markStatus(orderId: $orderId, status: 'failed');
        break;

    case 'charge.refunded':
        $orderService->markStatus(orderId: $orderId, status: 'refunded'); // Recorded, not executed — refunds are issued in Stripe
        break;

    default:
        break; // Acknowledged and ignored
}

\http_response_code(200);
exit;
