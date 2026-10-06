<?php

declare(strict_types=1);

namespace Osmium\Services\Stripe\Models;

use Osmium\Core\Library\StoreFinance;

use Osmium\Modules\Checkout\Services\ShopPaymentException;

require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * StripeClient
 *
 * A thin client for the Stripe PaymentIntents API. Deliberately thin, for the
 * same reason OsmiumPayPal is: it knows how to talk to Stripe and nothing about
 * our basket, our totals or our order lifecycle.
 *
 * Everything monetary passed in here has already been computed server-side from
 * the database. Nothing in this class decides what anything costs.
 *
 * It is NOT an implementation of a shared payment interface. Stripe's
 * lifecycle genuinely differs from PayPal's — the browser confirms a
 * PaymentIntent rather than us capturing an approved order — so the verbs here
 * are Stripe's own. StripePaymentProvider adapts them to core's payment.* hooks.
 *
 * Two wire-level differences from PayPal are easy to get wrong:
 *
 *   - Stripe takes form-encoded bodies, not JSON.
 *   - Stripe takes integer minor units (pence), not decimal strings. That
 *     conversion happens at this boundary and nowhere else, because a
 *     factor-of-100 error still looks like a plausible number.
 */
class StripeClient
{
    private const API_BASE = 'https://api.stripe.com';
    private const TIMEOUT_SECONDS = 20;

    /**
     * How far out of step with our clock a webhook may be and still be trusted.
     * Stripe's own libraries default to five minutes; the tolerance is what
     * stops a captured payload being replayed at us indefinitely.
     */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __construct(private object $config) {}

    /**
     * Is Stripe switched on and actually usable?
     *
     * Enabled with empty keys is not usable, and treating it as such would put
     * a dead payment form in front of a customer.
     */
    public function isEnabled(): bool
    {
        $switchedOn = !empty($this->config->enabled);
        if (!$switchedOn) return false;

        return $this->publishableKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * Publishable key for Stripe.js. Public by design — the secret key never
     * leaves this class.
     */
    public function publishableKey(): string
    {
        $key = $this->isTestMode() ? 'testPublishableKey' : 'livePublishableKey';

        return (string)($this->config->$key ?? '');
    }

    public function isTestMode(): bool
    {
        return ($this->config->mode ?? 'test') !== 'live';
    }

    /**
     * Create a PaymentIntent for an order that has already been written.
     *
     * Stripe has no equivalent of PayPal's capture step: the browser confirms
     * this intent with the returned client secret, and the money moves at that
     * point. Our confirmation is therefore a *retrieval* — see confirmPayment().
     *
     * @param array $order Stored order row (order_ref, addresses, name, totals)
     * @param array $totals Server-computed totals
     * @param StoreFinance $finance The store's country: every order ships there (UK-only for now)
     * @return array{id: string, client_secret: string}
     * @throws ShopPaymentException
     */
    public function createPaymentIntent(array $order, array $totals, StoreFinance $finance): array
    {
        $currency = (string)$order['currency'];
        $amount = (float)$order['total_inc_tax'];

        $body = [
            'amount' => self::toMinorUnits($amount),
            'currency' => \strtolower($currency), // Stripe wants a lowercase ISO code
            'description' => $this->truncate('Order ' . $order['order_ref'], 350),
            // Cards only, for now. Redirect-based methods (iDEAL, Klarna,
            // Bancontact) send the customer away and bring them back with the
            // intent reference in a query string. That is recoverable —
            // RedirectHandler stashes the query string in the session and
            // getStashedParam() reads it back — but the return leg is not
            // built: nothing resumes a checkout the customer has left and come
            // back to. Adding a method here without building that leg would
            // strand people mid-payment.
            'payment_method_types' => ['card'],

            // Our own reference travels with the payment so a webhook, or a
            // human in the Stripe dashboard, can always get back to the order.
            'metadata' => [
                'order_ref' => (string)$order['order_ref'],
                'order_id' => (string)$order['id'],
            ],

            // The address we priced and validated. Stripe will not substitute
            // its own, but recording ours makes the dashboard match the invoice.
            'shipping' => [
                'name' => $this->truncate((string)$order['delivery_name'], 350),
                'address' => \array_filter([
                    'line1' => $this->truncate((string)$order['delivery_address1'], 350),
                    'line2' => $this->truncate((string)($order['delivery_address2'] ?? ''), 350) ?: null,
                    'city' => $this->truncate((string)$order['delivery_city'], 350),
                    'state' => $this->truncate((string)($order['delivery_county'] ?? ''), 350) ?: null,
                    'postal_code' => (string)$order['delivery_postcode'],
                    'country' => $finance->country(),
                ], fn($v) => $v !== null),
            ],
        ];

        $email = \trim((string)($order['customer_email'] ?? ''));
        $hasEmail = $email !== '';
        if ($hasEmail) $body['receipt_email'] = $email;

        $descriptor = $this->statementDescriptor();
        $hasDescriptor = $descriptor !== '';
        if ($hasDescriptor) $body['statement_descriptor_suffix'] = $descriptor;

        // Keyed on our order, so a retried create for the same order returns the
        // same intent rather than a second one the customer could also pay.
        $headers = ['Idempotency-Key: order-' . $order['order_ref']];

        $response = $this->request(method: 'POST', path: '/v1/payment_intents', body: $body, headers: $headers);

        $intentId = (string)($response['id'] ?? '');
        $clientSecret = (string)($response['client_secret'] ?? '');

        $unusable = $intentId === '' || $clientSecret === '';
        if ($unusable) throw new ShopPaymentException('Stripe did not return a usable PaymentIntent');

        return ['id' => $intentId, 'client_secret' => $clientSecret];
    }

    /**
     * Retrieve a PaymentIntent, normalised into what the order lifecycle needs.
     *
     * This is Stripe's answer to "did the money actually move?", and it is the
     * only thing that should be trusted for that — never the browser's word.
     *
     * The amount comes back in major units so callers compare it against the
     * stored order in the same units the order is stored in. The conversion
     * lives here and nowhere else.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}
     * @throws ShopPaymentException
     */
    public function confirmPayment(string $paymentIntentId): array
    {
        $intent = $this->request(method: 'GET', path: '/v1/payment_intents/' . \rawurlencode($paymentIntentId));

        return $this->normaliseIntent($intent);
    }

    /**
     * Normalise a PaymentIntent — from a retrieval or from a webhook payload —
     * into the same shape, so both paths agree about what happened.
     *
     * @param array $intent Raw PaymentIntent
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}
     */
    public function normaliseIntent(array $intent): array
    {
        $stripeStatus = (string)($intent['status'] ?? '');

        // Stripe has more statuses than our order lifecycle does. Anything that
        // is not plainly succeeded or plainly dead is 'pending', because the
        // customer may still be completing an authentication step — telling
        // them it failed would invite a second payment for one about to succeed.
        $status = match ($stripeStatus) {
            'succeeded' => 'completed',
            'canceled' => 'failed',
            default => 'pending',
        };

        $charge = $this->latestCharge($intent);

        return [
            'status' => $status,
            'amount' => self::fromMinorUnits((int)($intent['amount_received'] ?? $intent['amount'] ?? 0)),
            'currency' => \strtoupper((string)($intent['currency'] ?? '')),
            'capture_ref' => (string)($charge['id'] ?? ''),
            'payer_email' => $this->payerEmail($intent, $charge),
        ];
    }

    /**
     * Verify a webhook signature.
     *
     * Stripe signs the raw body with the endpoint's signing secret. An event
     * that does not verify is not "probably genuine" — it is discarded.
     *
     * @param array $headers Request headers, keys upper-cased
     * @param string $rawBody The body exactly as received, unparsed
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $secret = $this->webhookSigningSecret();

        $noSecret = $secret === '';
        if ($noSecret) {
            \error_log('Stripe webhook rejected: no signing secret is configured');
            return false;
        }

        $header = (string)($headers['STRIPE-SIGNATURE'] ?? $headers['HTTP_STRIPE_SIGNATURE'] ?? '');

        $noSignature = $header === '';
        if ($noSignature) return false;

        $parts = $this->parseSignatureHeader($header);

        $incomplete = $parts['timestamp'] === 0 || $parts['signatures'] === [];
        if ($incomplete) return false;

        // A signature stays valid forever unless the timestamp is checked, which
        // would let a captured payload be replayed at us indefinitely.
        $age = \abs(\time() - $parts['timestamp']);
        $tooOld = $age > self::WEBHOOK_TOLERANCE_SECONDS;
        if ($tooOld) {
            \error_log("Stripe webhook rejected: timestamp is {$age}s out of tolerance");
            return false;
        }

        $expected = \hash_hmac(algo: 'sha256', data: $parts['timestamp'] . '.' . $rawBody, key: $secret);

        foreach ($parts['signatures'] as $candidate) {
            $matches = \hash_equals(known_string: $expected, user_string: $candidate);
            if ($matches) return true;
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Money
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Major units to Stripe's integer minor units.
     *
     * Rounded, not truncated: (int)(1.15 * 100) is 114 on a binary float, which
     * would undercharge by a penny and silently fail the amount check later.
     */
    public static function toMinorUnits(float $amount): int
    {
        return (int)\round(num: $amount * 100);
    }

    /**
     * Stripe's integer minor units back to major units.
     */
    public static function fromMinorUnits(int $minorUnits): float
    {
        return \round(num: $minorUnits / 100, precision: 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The charge behind an intent, whichever shape the API version supplies it in.
     *
     * @return array The charge, or an empty array when there is not one yet
     */
    private function latestCharge(array $intent): array
    {
        $expanded = $intent['latest_charge'] ?? null;

        $isExpandedObject = \is_array($expanded);
        if ($isExpandedObject) return $expanded;

        $isId = \is_string($expanded) && $expanded !== '';
        if ($isId) return ['id' => $expanded];

        return $intent['charges']['data'][0] ?? []; // Older API versions
    }

    private function payerEmail(array $intent, array $charge): ?string
    {
        $candidates = [
            $charge['billing_details']['email'] ?? null,
            $intent['receipt_email'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $usable = \is_string($candidate) && $candidate !== '';
            if ($usable) return $candidate;
        }

        return null;
    }

    /**
     * Split a Stripe-Signature header into its timestamp and v1 signatures.
     *
     * The header looks like: t=1614556800,v1=abc...,v1=def...
     * More than one v1 appears while a signing secret is being rotated, and any
     * of them matching is a genuine event.
     *
     * @return array{timestamp: int, signatures: array<int, string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = 0;
        $signatures = [];

        foreach (\explode(separator: ',', string: $header) as $pair) {
            $bits = \explode(separator: '=', string: \trim($pair), limit: 2);

            $malformed = \count($bits) !== 2;
            if ($malformed) continue;

            [$key, $value] = $bits;

            if ($key === 't') $timestamp = (int)$value;
            if ($key === 'v1') $signatures[] = $value;
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    private function statementDescriptor(): string
    {
        return $this->truncate((string)($this->config->statementDescriptor ?? ''), 22); // Stripe's limit for the suffix
    }

    private function truncate(string $value, int $length): string
    {
        return \mb_substr(string: \trim($value), start: 0, length: $length);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transport
    // ─────────────────────────────────────────────────────────────────────────

    private function secretKey(): string
    {
        $key = $this->isTestMode() ? 'testSecretKey' : 'liveSecretKey';

        return (string)($this->config->$key ?? '');
    }

    private function webhookSigningSecret(): string
    {
        return (string)($this->config->webhookSigningSecret ?? '');
    }

    /**
     * Make an authenticated API call.
     *
     * Stripe authenticates with the secret key as a bearer token — there is no
     * OAuth exchange to cache, which is why this class has no token handling.
     *
     * @param string $method HTTP method
     * @param string $path Path below the API base
     * @param array|null $body Body to form-encode, or null for none
     * @param array $headers Extra headers
     * @return array Decoded response
     * @throws ShopPaymentException
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $keyMissing = $this->secretKey() === '';
        if ($keyMissing) throw new ShopPaymentException('Stripe credentials are not configured');

        $curl = \curl_init(self::API_BASE . $path);

        $requestHeaders = \array_merge([
            'Authorization: Bearer ' . $this->secretKey(),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $headers);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ];

        $hasBody = $body !== null;
        if ($hasBody) $options[CURLOPT_POSTFIELDS] = \http_build_query($body); // Nested arrays become shipping[address][line1], which is Stripe's own format

        \curl_setopt_array($curl, $options);

        $raw = \curl_exec($curl);
        $status = (int)\curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($curl);

        $callFailed = $raw === false;
        if ($callFailed) throw new ShopPaymentException('Could not reach Stripe: ' . $curlError);

        $response = \json_decode(json: (string)$raw, associative: true) ?? [];

        $callRejected = $status < 200 || $status >= 300;
        if ($callRejected) {
            \error_log("Stripe {$method} {$path} failed with status {$status}: " . (string)$raw);

            // Carried on the exception as well as logged, for the same reason as
            // PayPal's: on an environment with no log access it is the only way
            // to find out what Stripe objected to. Diagnostic only — callers
            // must never show it to a customer.
            $detail = \mb_substr(string: (string)$raw, start: 0, length: 500);
            throw new ShopPaymentException("Stripe rejected the request ({$status}): {$detail}");
        }

        return $response;
    }
}
