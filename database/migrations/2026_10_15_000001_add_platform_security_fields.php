<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('platform_role', 30)->nullable()->after('account_type');
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->unsignedBigInteger('mfa_last_step')->nullable();
        });

        // Existing platform administrators keep their full access as Platform Owners; MFA is enrolled later.
        DB::table('users')->where('account_type', 'super_admin')->whereNull('platform_role')->update(['platform_role' => 'owner']);

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('actor_type', 20)->nullable();
            $table->string('severity', 12)->default('info');
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->index(['event', 'created_at'], 'al_event_idx');
            $table->index(['user_id', 'created_at'], 'al_user_idx');
            $table->index(['severity', 'created_at'], 'al_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('al_event_idx');
            $table->dropIndex('al_user_idx');
            $table->dropIndex('al_sev_idx');
            $table->dropColumn(['actor_type', 'severity', 'reason', 'before', 'after', 'request_id', 'user_agent']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['platform_role', 'mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_step']);
        });
    }
};
