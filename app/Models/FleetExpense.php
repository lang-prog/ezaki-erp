<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetExpense extends Model
{
    protected $fillable = ['company_id', 'vehicle_id', 'trip_id', 'expense_date', 'category', 'amount', 'cashbox_id', 'bank_id', 'account_id', 'expense_account_id', 'document', 'created_by', 'status', 'journal_entry_id', 'notes'];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2'];
}
