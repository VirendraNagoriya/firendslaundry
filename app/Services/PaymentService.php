<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records money taken against an order.
 *
 * Two rules matter here (spec sections 18 and 21):
 *  - payment status is only ever changed by this service, so the browser
 *    cannot mark an order paid by posting a field;
 *  - every collection is audit logged, because cash and UPI collected in
 *    the field are the most likely thing to be disputed later.
 */
class PaymentService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Record a payment (full or part) against an order and roll the
     * order's payment_status forward to match.
     */
    public function record(
        Order $order,
        float $amount,
        PaymentMethod $method,
        User $collector,
        ?string $referenceNo = null,
        ?string $notes = null,
    ): Payment {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new PaymentException('The amount collected must be greater than zero.');
        }

        if ($order->isTerminal() && $order->order_status === OrderStatus::CANCELLED) {
            throw new PaymentException('Payments cannot be taken against a cancelled order.');
        }

        if ($method === PaymentMethod::UPI && blank($referenceNo)) {
            throw new PaymentException('A UPI reference number is required for UPI payments.');
        }

        $alreadyPaid = (float) $order->amountPaid();
        $outstanding = round((float) $order->total_amount - $alreadyPaid, 2);

        if ($amount - $outstanding > 0.001) {
            throw new PaymentException(
                "That is more than the outstanding balance of Rs. {$outstanding}."
            );
        }

        $payment = DB::transaction(function () use ($order, $amount, $method, $collector, $referenceNo, $notes) {
            $payment = Payment::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => $method,
                // The money is physically in hand, so the collection is
                // settled. PENDING is reserved for a claim on the order
                // that has not been handed over yet.
                'status' => PaymentStatus::PAID,
                'collected_by' => $collector->id,
                'collected_at' => now(),
                'reference_no' => $referenceNo,
                'notes' => $notes,
            ]);

            $this->syncOrderPaymentStatus($order);

            return $payment;
        });

        $this->audit->created($payment, [
            'order_number' => $order->order_number,
            'amount' => $amount,
            'method' => $method->value,
            'reference_no' => $referenceNo,
            'collector' => $collector->name,
        ]);

        return $payment;
    }

    /**
     * Void a collection, e.g. a wrong entry at the counter.
     */
    public function void(Payment $payment, User $actor, ?string $reason = null): Payment
    {
        if ($payment->status === PaymentStatus::REFUNDED) {
            throw new PaymentException('That payment has already been voided.');
        }

        DB::transaction(function () use ($payment, $reason) {
            $payment->forceFill([
                'status' => PaymentStatus::REFUNDED,
                'notes' => trim(($payment->notes ?? '')."\nVoided: ".($reason ?? 'no reason given')),
            ])->save();

            $this->syncOrderPaymentStatus($payment->order()->firstOrFail());
        });

        $this->audit->event('payment.voided', $payment::class, $payment->id, [
            'status' => PaymentStatus::PAID->value,
        ], [
            'status' => PaymentStatus::REFUNDED->value,
            'reason' => $reason,
        ], $payment->business_id);

        return $payment;
    }

    /**
     * Bring the order's payment_status in line with what has been
     * collected. Called only from here, never from a controller.
     */
    private function syncOrderPaymentStatus(Order $order): void
    {
        $collected = round((float) $order->amountPaid(), 2);
        $total = round((float) $order->total_amount, 2);

        $status = match (true) {
            $total <= 0 => PaymentStatus::PENDING,
            $collected >= $total => PaymentStatus::PAID,
            $collected > 0 => PaymentStatus::PARTIAL,
            default => PaymentStatus::PENDING,
        };

        if ($order->payment_status === $status && $order->paid_at !== null) {
            return;
        }

        $order->forceFill([
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::PAID ? ($order->paid_at ?? now()) : null,
        ])->save();
    }
}
