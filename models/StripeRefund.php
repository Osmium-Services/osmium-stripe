<?php

declare(strict_types=1);

namespace Osmium\Services\Stripe\Models;

/**
 * Whether a charge.refunded event means the whole payment went back. Pure.
 *
 * Stripe sends charge.refunded for a partial refund too, with the running total in amount_refunded.
 */
class StripeRefund
{
    /** True only when the charge is refunded in full; a missing or zero figure is never a full refund. */
    public static function isFull(array $charge): bool
    {
        $flaggedRefunded = ($charge['refunded'] ?? false) === true;
        if ($flaggedRefunded) return true;

        $amount = (int)($charge['amount'] ?? 0);
        $amountRefunded = (int)($charge['amount_refunded'] ?? 0);

        return $amount > 0 && $amountRefunded >= $amount;
    }
}
