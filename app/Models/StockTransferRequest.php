<?php

namespace App\Models;

use App\Services\InventoryBatchService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferRequest extends Model
{
    use HasFactory, SoftDeletes;

    const DIRECTION_OUT = 'out';

    const DIRECTION_IN = 'in';

    protected $fillable = [
        'request_number',
        'business_id',
        'branch_id',
        'branch_from_id',
        'branch_to_id',
        'transfer_out_request_id',
        'direction',
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

    const STATUS_PENDING_ACCEPTANCE = 'pending_acceptance';

    const STATUS_BRANCH_REJECTED = 'branch_rejected';

    // Valid state transitions
    const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_BRANCH_REJECTED],
        self::STATUS_REJECTED => [],
        self::STATUS_CONFIRMED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_PENDING_ACCEPTANCE => [self::STATUS_CONFIRMED, self::STATUS_BRANCH_REJECTED],
        self::STATUS_BRANCH_REJECTED => [],
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
            if (! isset($request->version)) {
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

        return 'STR-'.$date.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
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

    public function branchFrom(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_from_id');
    }

    public function branchTo(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_to_id');
    }

    public function transferOutRequest(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'transfer_out_request_id');
    }

    public function transferInRequest(): HasOne
    {
        return $this->hasOne(StockTransferRequest::class, 'transfer_out_request_id');
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
        if (! isset(self::VALID_TRANSITIONS[$this->status])) {
            return false;
        }

        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status]);
    }

    public function approve(User $approver, ?string $notes = null): bool
    {
        if ($this->direction !== self::DIRECTION_OUT) {
            throw new \Exception('Only outbound transfer requests can be approved');
        }
        if (! $this->canTransitionTo(self::STATUS_APPROVED)) {
            throw new \Exception("Cannot approve request in {$this->status} status");
        }

        $this->load('branchProduct.product');
        $branchProduct = $this->branchProduct;
        $product = $branchProduct->product;
        $quantity = $this->quantity_requested;
        $branchFromId = $this->branch_from_id ?? $this->branch_id;
        $branchToId = $this->branch_to_id;

        $totalAvailable = $branchProduct->store_quantity + $branchProduct->shelf_quantity;
        if ($totalAvailable < $quantity) {
            throw new \Exception("Insufficient stock. Available: {$totalAvailable}, Requested: {$quantity}");
        }

        // Legacy: no destination branch → just update status (no inventory, no in-request)
        if ($branchToId === null) {
            return $this->updateWithVersionCheck([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
        }

        return DB::transaction(function () use ($approver, $notes, $branchProduct, $product, $quantity, $branchFromId, $branchToId) {
            $branchProduct = BranchProduct::lockForUpdate()->find($this->branch_product_id);
            $request = StockTransferRequest::lockForUpdate()->find($this->id);

            // Deduct from store first (then shelf if needed)
            $fromStore = min($quantity, $branchProduct->store_quantity);
            $fromShelf = $quantity - $fromStore;
            if ($fromShelf > $branchProduct->shelf_quantity) {
                throw new \Exception('Insufficient stock in store and shelf');
            }

            $shelfBefore = $branchProduct->shelf_quantity;
            $storeBefore = $branchProduct->store_quantity;
            $quantityBefore = $shelfBefore + $storeBefore;
            $branchProduct->store_quantity -= $fromStore;
            $branchProduct->shelf_quantity -= $fromShelf;
            $branchProduct->stock_quantity = $branchProduct->store_quantity + $branchProduct->shelf_quantity;
            $branchProduct->save();

            $transferOutTransaction = InventoryTransaction::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $this->business_id,
                'branch_id' => $branchFromId,
                'product_id' => $product->id,
                'user_id' => $approver->id,
                'type' => InventoryTransaction::TYPE_TRANSFER_OUT,
                'quantity' => -$quantity,
                'shelf_quantity' => -$fromShelf,
                'store_quantity' => -$fromStore,
                'quantity_before' => $quantityBefore,
                'shelf_quantity_before' => $shelfBefore,
                'store_quantity_before' => $storeBefore,
                'quantity_after' => $quantityBefore - $quantity,
                'shelf_quantity_after' => $shelfBefore - $fromShelf,
                'store_quantity_after' => $storeBefore - $fromStore,
                'related_branch_id' => $branchToId,
                'reference_number' => $this->request_number,
                'notes' => "Stock transfer to branch (STR: {$this->request_number})",
                'stock_transfer_request_id' => $this->id,
            ]);

            app(InventoryBatchService::class)->allocateStockOut(
                $product->id,
                $branchFromId,
                $quantity,
                $transferOutTransaction,
                [
                    'reference_number' => $this->request_number,
                    'notes' => "Stock transfer to branch (STR: {$this->request_number})",
                ]
            );

            $request->updateWithVersionCheck([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            // Get or create BranchProduct at receiving branch (so in-request has valid branch_product_id on all DBs)
            $receivingBranchProduct = BranchProduct::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'branch_id' => $branchToId,
                ],
                [
                    'shelf_quantity' => 0,
                    'store_quantity' => 0,
                    'stock_quantity' => 0,
                ]
            );

            // Create "transfer in" request for receiving branch
            $inRequest = new StockTransferRequest;
            $inRequest->business_id = $this->business_id;
            $inRequest->branch_id = $branchToId;
            $inRequest->branch_from_id = $branchFromId;
            $inRequest->branch_to_id = $branchToId;
            $inRequest->transfer_out_request_id = $this->id;
            $inRequest->direction = self::DIRECTION_IN;
            $inRequest->branch_product_id = $receivingBranchProduct->id;
            $inRequest->quantity_requested = $quantity;
            $inRequest->reason = $this->reason;
            $inRequest->priority = $this->priority;
            $inRequest->status = self::STATUS_PENDING_ACCEPTANCE;
            $inRequest->requested_by = $approver->id;
            $inRequest->requested_at = now();
            $inRequest->version = 1;
            $inRequest->request_number = self::generateRequestNumber();
            $inRequest->save();

            return true;
        });
    }

    public function reject(User $reviewer, string $reason): bool
    {
        if (! $this->canTransitionTo(self::STATUS_REJECTED)) {
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
        if ($this->direction === self::DIRECTION_OUT && $this->branch_to_id !== null) {
            throw new \Exception('Use accept on the transfer-in request at the receiving branch to complete this transfer');
        }
        if (! $this->canTransitionTo(self::STATUS_CONFIRMED)) {
            throw new \Exception("Cannot confirm request in {$this->status} status");
        }

        $quantityToTransfer = $actualQuantity ?? $this->quantity_requested;

        // Reload the branch product to get fresh data
        $this->load('branchProduct');

        // Verify stock availability again before transfer
        if ($this->branchProduct->store_quantity < $quantityToTransfer) {
            throw new \Exception('Insufficient stock in store for confirmation');
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

            if (! $success) {
                throw new \Exception('Failed to move stock to shelf');
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
                throw new \Exception('Request was modified by another user. Please refresh and try again.');
            }

            // Refresh this instance
            $this->refresh();

            return true;
        });
    }

    /**
     * Accept the transfer at receiving branch: create transfer_in inventory, update stock.
     * Call on the in-request (direction=in).
     */
    public function acceptInRequest(User $accepter): bool
    {
        if ($this->direction !== self::DIRECTION_IN) {
            throw new \Exception('Only inbound transfer requests can be accepted');
        }
        if (! $this->canTransitionTo(self::STATUS_CONFIRMED)) {
            throw new \Exception("Cannot accept request in {$this->status} status");
        }

        $outRequest = $this->transferOutRequest;
        if (! $outRequest || ! $outRequest->branchProduct) {
            throw new \Exception('Transfer out request or branch product not found');
        }

        $productId = $outRequest->branchProduct->product_id;
        $quantity = $this->quantity_requested;
        $branchToId = $this->branch_to_id;
        $branchFromId = $this->branch_from_id;

        return DB::transaction(function () use ($accepter, $productId, $quantity, $branchToId, $branchFromId) {
            $receivingBranchProduct = BranchProduct::where('product_id', $productId)
                ->where('branch_id', $branchToId)
                ->lockForUpdate()
                ->first();

            $shelfBefore = $receivingBranchProduct ? $receivingBranchProduct->shelf_quantity : 0;
            $storeBefore = $receivingBranchProduct ? $receivingBranchProduct->store_quantity : 0;
            $quantityBefore = $shelfBefore + $storeBefore;
            $quantityAfter = $quantityBefore + $quantity;

            if ($receivingBranchProduct) {
                $receivingBranchProduct->store_quantity += $quantity;
                $receivingBranchProduct->stock_quantity = $receivingBranchProduct->shelf_quantity + $receivingBranchProduct->store_quantity;
                $receivingBranchProduct->save();
            } else {
                $receivingBranchProduct = BranchProduct::create([
                    'product_id' => $productId,
                    'branch_id' => $branchToId,
                    'store_quantity' => $quantity,
                    'shelf_quantity' => 0,
                    'stock_quantity' => $quantity,
                ]);
            }

            $transferInTransaction = InventoryTransaction::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $this->business_id,
                'branch_id' => $branchToId,
                'product_id' => $productId,
                'user_id' => $accepter->id,
                'type' => InventoryTransaction::TYPE_TRANSFER_IN,
                'quantity' => $quantity,
                'shelf_quantity' => 0,
                'store_quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'shelf_quantity_before' => $shelfBefore,
                'store_quantity_before' => $storeBefore,
                'quantity_after' => $quantityAfter,
                'shelf_quantity_after' => $receivingBranchProduct->shelf_quantity,
                'store_quantity_after' => $receivingBranchProduct->store_quantity,
                'related_branch_id' => $branchFromId,
                'reference_number' => $this->transferOutRequest->request_number,
                'notes' => "Stock transfer received (STR: {$this->transferOutRequest->request_number})",
                'stock_transfer_request_id' => $this->id,
            ]);

            app(InventoryBatchService::class)->addStockIn(
                $productId,
                $branchToId,
                $this->business_id,
                $quantity,
                $transferInTransaction,
                null,
                []
            );

            $this->updateWithVersionCheck([
                'status' => self::STATUS_CONFIRMED,
                'confirmed_by' => $accepter->id,
                'confirmed_at' => now(),
                'quantity_transferred' => $quantity,
            ]);

            $outRequest = $this->transferOutRequest;
            $outRequest->updateWithVersionCheck([
                'status' => self::STATUS_CONFIRMED,
                'confirmed_by' => $accepter->id,
                'confirmed_at' => now(),
                'quantity_transferred' => $quantity,
            ]);

            return true;
        });
    }

    /**
     * Reject the transfer at receiving branch: reverse stock at sending branch, update statuses.
     * Call on the in-request (direction=in).
     */
    public function rejectInRequest(User $rejecter, string $reason): bool
    {
        if ($this->direction !== self::DIRECTION_IN) {
            throw new \Exception('Only inbound transfer requests can be rejected');
        }
        if (! $this->canTransitionTo(self::STATUS_BRANCH_REJECTED)) {
            throw new \Exception("Cannot reject request in {$this->status} status");
        }

        $outRequest = $this->transferOutRequest;
        if (! $outRequest || ! $outRequest->branchProduct) {
            throw new \Exception('Transfer out request or branch product not found');
        }

        $quantity = $this->quantity_requested;
        $branchFromId = $this->branch_from_id;
        $outRequestId = $outRequest->id;

        return DB::transaction(function () use ($rejecter, $reason, $quantity, $branchFromId, $outRequestId) {
            $outRequest = StockTransferRequest::lockForUpdate()->find($outRequestId);
            $branchProduct = BranchProduct::lockForUpdate()->find($outRequest->branch_product_id);

            $storeBefore = $branchProduct->store_quantity;
            $shelfBefore = $branchProduct->shelf_quantity;
            $quantityBefore = $storeBefore + $shelfBefore;

            $branchProduct->store_quantity += $quantity;
            $branchProduct->stock_quantity = $branchProduct->shelf_quantity + $branchProduct->store_quantity;
            $branchProduct->save();

            $quantityAfter = $quantityBefore + $quantity;

            $reversalTransaction = InventoryTransaction::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $this->business_id,
                'branch_id' => $branchFromId,
                'product_id' => $branchProduct->product_id,
                'user_id' => $rejecter->id,
                'type' => InventoryTransaction::TYPE_TRANSFER_IN,
                'quantity' => $quantity,
                'shelf_quantity' => 0,
                'store_quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'shelf_quantity_before' => $shelfBefore,
                'store_quantity_before' => $storeBefore,
                'quantity_after' => $quantityAfter,
                'shelf_quantity_after' => $shelfBefore,
                'store_quantity_after' => $branchProduct->store_quantity,
                'related_branch_id' => $this->branch_to_id,
                'reference_number' => $outRequest->request_number,
                'notes' => "Reversal: receiving branch rejected STR {$outRequest->request_number}. {$reason}",
                'stock_transfer_request_id' => $this->id,
            ]);

            app(InventoryBatchService::class)->addStockIn(
                $branchProduct->product_id,
                $branchFromId,
                $this->business_id,
                $quantity,
                $reversalTransaction,
                null,
                []
            );

            $this->updateWithVersionCheck([
                'status' => self::STATUS_BRANCH_REJECTED,
                'review_notes' => $reason,
                'reviewed_by' => $rejecter->id,
                'reviewed_at' => now(),
            ]);

            $outRequest->updateWithVersionCheck([
                'status' => self::STATUS_BRANCH_REJECTED,
                'review_notes' => $reason,
                'reviewed_by' => $rejecter->id,
                'reviewed_at' => now(),
            ]);

            return true;
        });
    }

    public function cancel(User $canceller, string $reason): bool
    {
        if (! $this->canTransitionTo(self::STATUS_CANCELLED)) {
            throw new \Exception("Cannot cancel request in {$this->status} status");
        }

        return $this->updateWithVersionCheck([
            'status' => self::STATUS_CANCELLED,
            'reason' => $reason,
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

        $updated = static::query()
            ->where('id', $this->id)
            ->where('version', $currentVersion)
            ->update($attributes);

        if ($updated === 0) {
            throw new \Exception('Request was modified by another user. Please refresh and try again.');
        }

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
            self::STATUS_CANCELLED,
        ]);
    }
}
