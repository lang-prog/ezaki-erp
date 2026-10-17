<?php

use App\Support\SubscriptionDurationBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Additive only: the legacy plans.duration / duration_days columns and every subscription row stay untouched.
    public function up(): void
    {
        Schema::create('subscription_durations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique('sd_key_unique');
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->string('description_ar', 500)->nullable();
            $table->string('description_en', 500)->nullable();
            $table->string('category', 12);
            $table->string('unit', 12);
            $table->unsignedInteger('value')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'sd_active_sort_idx');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('subscription_duration_id')->nullable()->after('duration_days');
            $table->foreign('subscription_duration_id', 'plans_sd_fk')->references('id')->on('subscription_durations')->restrictOnDelete();
        });

        SubscriptionDurationBackfill::run();
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropForeign('plans_sd_fk');
            $table->dropColumn('subscription_duration_id');
        });
        Schema::dropIfExists('subscription_durations');
    }
};
