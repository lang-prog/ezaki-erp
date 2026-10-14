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
        Schema::table('bill_revisions', function (Blueprint $table): void {
            $table->string('revision_chain_id', 100)->nullable()->after('document_id');
            $table->foreignId('original_journal_entry_id')->nullable()->after('revision_chain_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('original_journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('replacement_journal_entry_id')->nullable()->after('reversal_journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->decimal('original_amount', 18, 2)->nullable()->after('replacement_journal_entry_id');
            $table->decimal('revised_amount', 18, 2)->nullable()->after('original_amount');
            $table->decimal('delta_amount', 18, 2)->nullable()->after('revised_amount');
            $table->index(['company_id', 'revision_chain_id'], 'bill_revisions_chain_idx');
        });

        Schema::table('sales_bills', function (Blueprint $table): void {
            $table->string('revision_chain_id', 100)->nullable()->after('internal_number');
            $table->boolean('is_revised')->default(false)->after('status');
            $table->unsignedInteger('revision_count')->default(0)->after('is_revised');
            $table->index(['company_id', 'revision_chain_id'], 'sales_bills_chain_idx');
        });

        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->string('revision_chain_id', 100)->nullable()->after('internal_number');
            $table->boolean('is_revised')->default(false)->after('status');
            $table->unsignedInteger('revision_count')->default(0)->after('is_revised');
            $table->index(['company_id', 'revision_chain_id'], 'purchase_bills_chain_idx');
        });

        // Backfill existing revision data for historical records
        foreach (DB::table('bill_revisions')->orderBy('id')->get() as $rev) {
            $before = json_decode((string) $rev->before_data, true) ?: [];
            $after = json_decode((string) $rev->after_data, true) ?: [];
            $chainId = $rev->document_type.':'.$rev->document_id;
            $origJournalId = $before['journal_entry_id'] ?? null;
            $replacementJournalId = $after['journal_entry_id'] ?? null;
            $reversalJournalId = null;
            if ($origJournalId) {
                $reversalJournalId = DB::table('journal_entries')
                    ->where('company_id', $rev->company_id)
                    ->where('source_type', 'App\\Models\\JournalEntry')
                    ->where('source_id', $origJournalId)
                    ->value('id');
            }
            $origAmount = isset($before['total']) ? (float) $before['total'] : null;
            $revisedAmount = isset($after['total']) ? (float) $after['total'] : null;
            $delta = ($origAmount !== null && $revisedAmount !== null) ? round($revisedAmount - $origAmount, 2) : null;

            DB::table('bill_revisions')->where('id', $rev->id)->update([
                'revision_chain_id' => $chainId,
                'original_journal_entry_id' => $origJournalId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'replacement_journal_entry_id' => $replacementJournalId,
                'original_amount' => $origAmount,
                'revised_amount' => $revisedAmount,
                'delta_amount' => $delta,
            ]);

            $table = $rev->document_type === 'purchase' ? 'purchase_bills' : 'sales_bills';
            DB::table($table)->where('id', $rev->document_id)->update([
                'revision_chain_id' => $chainId,
                'is_revised' => true,
            ]);
        }

        foreach (['sales_bills', 'purchase_bills'] as $billTable) {
            $docType = $billTable === 'purchase_bills' ? 'purchase' : 'sales';
            $counts = DB::table('bill_revisions')
                ->where('document_type', $docType)
                ->groupBy('document_id')
                ->select('document_id', DB::raw('count(*) as total'))
                ->pluck('total', 'document_id');
            foreach ($counts as $docId => $count) {
                DB::table($billTable)->where('id', $docId)->update(['revision_count' => $count]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->dropIndex('purchase_bills_chain_idx');
            $table->dropColumn(['revision_chain_id', 'is_revised', 'revision_count']);
        });

        Schema::table('sales_bills', function (Blueprint $table): void {
            $table->dropIndex('sales_bills_chain_idx');
            $table->dropColumn(['revision_chain_id', 'is_revised', 'revision_count']);
        });

        Schema::table('bill_revisions', function (Blueprint $table): void {
            $table->dropIndex('bill_revisions_chain_idx');
            $table->dropConstrainedForeignId('replacement_journal_entry_id');
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('original_journal_entry_id');
            $table->dropColumn(['revision_chain_id', 'original_amount', 'revised_amount', 'delta_amount']);
        });
    }
};
