<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDailySummary extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_daily_summaries';

    protected $fillable = [
        'business_id',
        'branch_id',
        'sale_date',
        'txn_count',
        'items_sold',
        'revenue',
        'discount',
        'cost',
        'profit',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'txn_count' => 'integer',
            'items_sold' => 'integer',
            'revenue' => 'decimal:2',
            'discount' => 'decimal:2',
            'cost' => 'decimal:2',
            'profit' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
