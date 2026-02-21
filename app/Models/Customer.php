<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'customer_code',
        'name',
        'email',
        'phone',
        'address',
        'type',
        'credit_limit',
        'outstanding_balance',
        'loyalty_points',
        'metadata',
        'is_active',
        'client_uuid',
        'version',
        'device_id',
        'synced_at',
        'sync_status',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'loyalty_points' => 'integer',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // Scopes
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Helper methods
    public function addLoyaltyPoints(int $points): void
    {
        $this->increment('loyalty_points', $points);
    }

    public function redeemLoyaltyPoints(int $points): bool
    {
        if ($this->loyalty_points >= $points) {
            $this->decrement('loyalty_points', $points);

            return true;
        }

        return false;
    }

    public function updateBalance(float $amount): void
    {
        $this->increment('outstanding_balance', $amount);
    }

    public function hasAvailableCredit(float $amount): bool
    {
        return ($this->outstanding_balance + $amount) <= $this->credit_limit;
    }
}
