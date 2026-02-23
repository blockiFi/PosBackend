<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShelfStoreMoveRequest extends Model
{
    use SoftDeletes;

    const DIRECTION_TO_SHELF = 'to_shelf';

    const DIRECTION_TO_STORE = 'to_store';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'request_number',
        'business_id',
        'branch_id',
        'branch_product_id',
        'direction',
        'quantity',
        'reason',
        'status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ShelfStoreMoveRequest $request): void {
            if (empty($request->request_number)) {
                $request->request_number = self::generateRequestNumber();
            }
            if (empty($request->requested_at)) {
                $request->requested_at = now();
            }
        });
    }

    private static function generateRequestNumber(): string
    {
        $date = now()->format('Ymd');
        $lastRequest = self::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastRequest ? (int) substr($lastRequest->request_number, -4) + 1 : 1;

        return 'SSM-'.$date.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
