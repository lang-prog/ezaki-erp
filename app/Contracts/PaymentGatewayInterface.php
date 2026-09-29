<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ManualPayment;
use App\Models\Subscription;
use App\Models\User;

interface PaymentGatewayInterface
{
    public function record(Subscription $subscription, float $amount, string $status, User $actor, ?string $reference = null, ?string $notes = null): ManualPayment;
}
