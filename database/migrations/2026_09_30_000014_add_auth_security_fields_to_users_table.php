<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('login_locked_until')->nullable()->after('failed_login_attempts');
            $table->unsignedBigInteger('session_version')->default(0)->after('login_locked_until');
            $table->index('login_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['login_locked_until']);
            $table->dropColumn(['login_locked_until', 'session_version']);
        });
    }
};
