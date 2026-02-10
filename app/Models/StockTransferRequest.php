<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class StockTransferRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'request_number',
        'business_id',
        'branch_id',
        'branch_product_id',
        'quantity_requested',
        'reason',
        'priority',
        'status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'confirmed_by',
        'confirmed_at',
        'confirmation_notes',
        'quantity_transferred',
        'version',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_transferred' => 'integer',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'version' => 'integer',
    ];

    // State machine constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';

    // Valid state transitions
    const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [],
        self::STATUS_CONFIRMED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($request) {
            if (empty($request->request_number)) {
                $request->request_number = self::generateRequestNumber();
            }
            if (empty($request->requested_at)) {
                $request->requested_at = now();
            }
            if (!isset($request->version)) {
                $request->version = 1;
            }
        });
    }

    /**
     * Generate unique request number
     */
    private static function generateRequestNumber(): string
    {
        $date = now()->format('Ymd');
        $lastRequest = self::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastRequest ? (int) substr($lastRequest->request_number, -4) + 1 : 1;
        
        return 'STR-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Relationships
     */
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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * State machine methods
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if (!isset(self::VALID_TRANSITIONS[$this->status])) {
            return false;
        }

        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status]);
    }

    public function approve(User $approver, ?string $notes = null): bool
    {
        if (!$this->canTransitionTo(self::STATUS_APPROVED)) {
            throw new \Exception("Cannot approve request in {$this->status} status");
        }

        // Check if there's enough stock in store
        if ($this->branchProduct->store_quantity < $this->quantity_requested) {
            throw new \Exception("Insufficient stock in store. Available: {$this->branchProduct->store_quantity}, Requested: {$this->quantity_requested}");
        }

        return $this->updateWithVersionCheck([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    public function reject(User $reviewer, string $reason): bool
    {
        if (!$this->canTransitionTo(self::STATUS_REJECTED)) {
            throw new \Exception("Cannot reject request in {$this->status} status");
        }

        return $this->updateWithVersionCheck([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);
    }

    public function confirm(User $confirmer, ?int $actualQuantity = null, ?string $notes = null): bool
    {
        if (!$this->canTransitionTo(self::STATUS_CONFIRMED)) {
            throw new \Exception("Cannot confirm request in {$this->status} status");
        }

        $quantityToTransfer = $actualQuantity ?? $this->quantity_requested;

        // Reload the branch product to get fresh data
        $this->load('branchProduct');

        // Verify stock availability again before transfer
        if ($this->branchProduct->store_quantity < $quantityToTransfer) {
            throw new \Exception("Insufficient stock in store for confirmation");
        }

        // Get IDs for transaction
        $branchProductId = $this->branch_product_id;
        $requestId = $this->id;

        return DB::transaction(function () use ($confirmer, $quantityToTransfer, $notes, $branchProductId, $requestId) {
            // Lock both records for update
            $branchProduct = BranchProduct::lockForUpdate()->find($branchProductId);
            $request = StockTransferRequest::lockForUpdate()->find($requestId);
            
            // Perform the actual inventory movement
            $success = $branchProduct->moveToShelf($quantityToTransfer);
            
            if (!$success) {
                throw new \Exception("Failed to move stock to shelf");
            }

            // Update request status - use the locked instance's current version
            $updated = DB::table('stock_transfer_requests')
                ->where('id', $request->id)
                ->where('version', $request->version)
                ->update([
                    'status' => self::STATUS_CONFIRMED,
                    'confirmed_by' => $confirmer->id,
                    'confirmed_at' => now(),
                    'confirmation_notes' => $notes,
                    'quantity_transferred' => $quantityToTransfer,
                    'version' => $request->version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \Exception("Request was modified by another user. Please refresh and try again.");
            }

            // Refresh this instance
            $this->refresh();
            
            return true;
        });
    }

    public function cancel(User $canceller, string $reason): bool
    {
        if (!$this->canTransitionTo(self::STATUS_CANCELLED)) {
            throw new \Exception("Cannot cancel request in {$this->status} status");
        }

        return $this->updateWithVersionCheck([
            'status' => self::STATUS_CANCELLED,
            'review_notes' => $reason,
            'reviewed_by' => $canceller->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Optimistic locking for concurrency control
     */
    private function updateWithVersionCheck(array $attributes): bool
    {
        $currentVersion = $this->version;
        $attributes['version'] = $currentVersion + 1;

        $updated = DB::table('stock_transfer_requests')
            ->where('id', $this->id)
            ->where('version', $currentVersion)
            ->update($attributes + ['updated_at' => now()]);

        if ($updated === 0) {
            throw new \Exception("Request was modified by another user. Please refresh and try again.");
        }

        // Automatically refresh the model to get the latest state
        $this->refresh();
        return true;
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeRequestedBy($query, int $userId)
    {
        return $query->where('requested_by', $userId);
    }

    /**
     * Helper methods
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_REJECTED,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED
        ]);
    }
}
