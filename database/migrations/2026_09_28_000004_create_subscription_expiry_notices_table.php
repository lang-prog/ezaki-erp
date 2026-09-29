<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_expiry_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('notice_date');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['subscription_id', 'user_id', 'notice_date'], 'subscription_expiry_notice_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_expiry_notices');
    }
};
