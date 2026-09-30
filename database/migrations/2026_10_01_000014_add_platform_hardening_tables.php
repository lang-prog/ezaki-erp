<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registration_requests')->nullOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('base_amount', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('payable_amount', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['coupon_id', 'company_id', 'subscription_id'], 'coupon_redemptions_once_per_subscription');
            $table->index(['coupon_id', 'created_at']);
        });

        Schema::table('local_licenses', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('installation_id')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('enabled')->after('customer_binding');
            $table->timestamp('enabled_at')->nullable()->after('activated_at');
            $table->timestamp('disabled_at')->nullable()->after('enabled_at');
            $table->timestamp('revoked_at')->nullable()->after('disabled_at');
            $table->timestamp('transferred_at')->nullable()->after('revoked_at');
            $table->string('transfer_reference')->nullable()->after('transferred_at');
            $table->timestamp('last_verified_at')->nullable()->after('transfer_reference');
            $table->index(['company_id', 'status']);
        });

        Schema::create('local_license_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('local_license_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_installation_id')->nullable();
            $table->string('to_installation_id')->nullable();
            $table->string('from_customer_binding')->nullable();
            $table->string('to_customer_binding')->nullable();
            $table->foreignId('from_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('to_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['local_license_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_license_audits');
        Schema::table('local_licenses', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn(['company_id', 'status', 'enabled_at', 'disabled_at', 'revoked_at', 'transferred_at', 'transfer_reference', 'last_verified_at']);
        });
        Schema::dropIfExists('coupon_redemptions');
    }
};
