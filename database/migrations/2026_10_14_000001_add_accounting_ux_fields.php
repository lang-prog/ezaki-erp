<?php

declare(strict_types=1);

use App\Support\SystemAccountCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable()->after('name');
            $table->string('name_en', 255)->nullable()->after('name_ar');
        });

        // sales_bills.total_packages already exists (default 0) but was never maintained.

        // Only fill blanks so a company's own display names are never overwritten.
        foreach (DB::table('accounts')->where('is_system', true)->get(['id', 'code', 'name', 'name_ar', 'name_en']) as $account) {
            $labels = SystemAccountCatalog::forCode((string) $account->code);
            $update = [];
            if ($account->name_en === null) {
                $update['name_en'] = $account->name;
            }
            if ($account->name_ar === null && $labels) {
                $update['name_ar'] = $labels['ar'];
            }
            if ($update) {
                DB::table('accounts')->where('id', $account->id)->update($update);
            }
        }

        // Custom accounts keep their single historical name in the English slot.
        DB::table('accounts')->where('is_system', false)->whereNull('name_en')->update(['name_en' => DB::raw('name')]);

        // Header packages were never maintained for sales; derive them from the lines.
        $sales = DB::table('sales_bills')->where('total_packages', 0)->pluck('id');
        foreach ($sales as $id) {
            $sum = DB::table('sales_bill_lines')->where('sales_bill_id', $id)->sum('packages');
            if ((float) $sum > 0) {
                DB::table('sales_bills')->where('id', $id)->update(['total_packages' => $sum]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en']);
        });
    }
};
