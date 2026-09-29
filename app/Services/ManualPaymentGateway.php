<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\ManualPayment;
use App\Models\Subscription;
use App\Models\User;

class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function record(Subscription $subscription, float $amount, string $status, User $actor, ?string $reference = null, ?string $notes = null): ManualPayment
    {
        return ManualPayment::query()->create([
            'subscription_id' => $subscription->id,
            'recorded_by' => $actor->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'status' => $status,
            'reference' => $reference,
            'notes' => $notes,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
