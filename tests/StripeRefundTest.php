<?php

declare(strict_types=1);

namespace Tests\Services\Stripe;

use Osmium\Services\Stripe\Models\StripeRefund;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StripeRefund.php';

/**
 * A partial refund must not mark the whole order refunded.
 */
final class StripeRefundTest extends TestCase
{
    public function test_a_charge_flagged_refunded_is_a_full_refund(): void
    {
        $this->assertTrue(StripeRefund::isFull(['refunded' => true, 'amount' => 5000, 'amount_refunded' => 5000]));
    }

    public function test_a_part_refund_is_not_a_full_refund(): void
    {
        $this->assertFalse(StripeRefund::isFull(['refunded' => false, 'amount' => 5000, 'amount_refunded' => 1500]));
    }

    public function test_the_amounts_decide_when_the_flag_is_missing(): void
    {
        $this->assertTrue(StripeRefund::isFull(['amount' => 5000, 'amount_refunded' => 5000]));
        $this->assertFalse(StripeRefund::isFull(['amount' => 5000, 'amount_refunded' => 4999]));
    }

    public function test_missing_or_zero_figures_are_never_a_full_refund(): void
    {
        $this->assertFalse(StripeRefund::isFull([]));
        $this->assertFalse(StripeRefund::isFull(['amount' => 0, 'amount_refunded' => 0]));
    }
}
