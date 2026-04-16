<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'business_id',
        'branch_id',
        'group_id',
        'user_id',
        'device_name',
        'device_type',
        'os',
        'app_version',
        'ip_address',
        'status',
        'last_seen_at',
        'last_sync_at',
        'total_syncs',
        'capabilities',
        'metadata',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'total_syncs' => 'integer',
        'capabilities' => 'array',
        'metadata' => 'array',
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }

    public function syncSessions(): HasMany
    {
        return $this->hasMany(SyncSession::class, 'device_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Scope to devices last seen within the given minutes.
     */
    public function scopeOnline($query, int $withinMinutes = 5)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($withinMinutes));
    }

    /**
     * Methods
     */
    public function updateLastSeen(): void
    {
        $this->update([
            'last_seen_at' => now(),
            'ip_address' => request()->ip(),
        ]);
    }

    public function recordSync(): void
    {
        $this->increment('total_syncs');
        $this->update(['last_sync_at' => now()]);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function hasCapability(string $capability): bool
    {
        $capabilities = $this->capabilities ?? [];

        if (array_key_exists($capability, $capabilities)) {
            return (bool) $capabilities[$capability];
        }

        return false;
    }
}
