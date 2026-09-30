<?php

declare(strict_types=1);

namespace Osmium\Services\Stripe\Models;

/**
 * Adapts Stripe to core's payment.* hooks (see ServiceHooks / ShopPaymentProviders).
 *
 * Every handler answers only for provider id 'stripe' and returns null for
 * anything else, so another payment service can coexist.
 */
class StripePaymentProvider
{
    public const ID = 'stripe';

    /**
     * payment.providers - advertise Stripe and whether it can take money now.
     */
    public static function provider(array $payload): array
    {
        $client = StripeConfig::client();

        return [
            'id' => self::ID,
            'label' => 'Stripe',
            'ready' => $client->isEnabled(),
            'frontend' => ['publishableKey' => $client->publishableKey()], // Public by design
            'settingsRoute' => 'settings/stripe/',
        ];
    }

    /**
     * payment.create - start a PaymentIntent for an order already written.
     *
     * @return array{ref: string, client_secret: string, test_mode: bool}|null
     * @throws \Osmium\Modules\Checkout\Services\ShopPaymentException
     */
    public static function create(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        $client = StripeConfig::client();
        $intent = $client->createPaymentIntent(order: $payload['order'], totals: $payload['totals']);

        return [
            'ref' => $intent['id'],
            'client_secret' => $intent['client_secret'],
            'test_mode' => $client->isTestMode(),
        ];
    }

    /**
     * payment.confirm - ask Stripe what actually happened to the PaymentIntent.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}|null
     * @throws \Osmium\Modules\Checkout\Services\ShopPaymentException
     */
    public static function confirm(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        return StripeConfig::client()->confirmPayment((string) $payload['ref']);
    }
}
