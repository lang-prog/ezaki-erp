<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('previous_subscription_id')->nullable();
            $table->index(['company_id', 'superseded_at', 'starts_at'], 'subs_cur_idx');
        });

        Schema::table('manual_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique('mp_idem_unique');
            $table->text('reason')->nullable();
            $table->timestamp('previous_ends_at')->nullable();
            $table->timestamp('new_ends_at')->nullable();
            $table->decimal('amount_due', 12, 2)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
        });

        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->unsignedBigInteger('from_plan_id')->nullable();
            $table->unsignedBigInteger('to_plan_id')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->timestamp('from_starts_at')->nullable();
            $table->timestamp('from_ends_at')->nullable();
            $table->timestamp('to_starts_at')->nullable();
            $table->timestamp('to_ends_at')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'se_company_idx');
        });

        Schema::create('subscription_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);
            $table->text('message')->nullable();
            $table->string('status', 12)->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'type', 'status'], 'sr_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
        Schema::dropIfExists('subscription_events');

        Schema::table('manual_payments', function (Blueprint $table): void {
            $table->dropForeign(['resolved_by']);
            $table->dropUnique('mp_idem_unique');
            $table->dropColumn(['idempotency_key', 'reason', 'previous_ends_at', 'new_ends_at', 'amount_due', 'resolved_by', 'resolved_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subs_cur_idx');
            $table->dropColumn(['superseded_at', 'previous_subscription_id']);
        });
    }
};
