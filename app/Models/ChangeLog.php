<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLog extends Model
{
    protected $table = 'change_logs';

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'entity_type',
        'entity_id',
        'entity_uuid',
        'action',
        'version',
        'device_id',
        'user_id',
        'changes',
        'changed_at',
        'synced'
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'version' => 'integer',
        'changes' => 'array',
        'changed_at' => 'datetime',
        'synced' => 'boolean'
    ];

    // Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceRegistration::class, 'device_id', 'device_id');
    }

    // Scopes
    public function scopeForEntity($query, $entityType, $entityId = null)
    {
        $query->where('entity_type', $entityType);
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }
        return $query;
    }

    public function scopeUnsynced($query)
    {
        return $query->where('synced', false);
    }

    public function scopeSince($query, $timestamp)
    {
        return $query->where('changed_at', '>=', $timestamp);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    // Static Helper Methods
    public static function logChange($entityType, $entityId, $entityUuid, $action, $version, $changes = [], $deviceId = null, $userId = null, $businessId = null)
    {
        return static::create([
            'business_id' => $businessId ?? auth()->user()?->business_id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_uuid' => $entityUuid,
            'action' => $action,
            'version' => $version,
            'device_id' => $deviceId,
            'user_id' => $userId ?? auth()->id(),
            'changes' => $changes,
            'changed_at' => now(),
            'synced' => false
        ]);
    }

    public static function getChangesSince($businessId, $timestamp, $entityTypes = null)
    {
        $query = static::where('business_id', $businessId)
            ->since($timestamp)
            ->orderBy('changed_at', 'asc');

        if ($entityTypes) {
            $query->whereIn('entity_type', $entityTypes);
        }

        return $query->get()->groupBy('entity_type');
    }

    public static function getEntityHistory($entityType, $entityId, $limit = 50)
    {
        return static::forEntity($entityType, $entityId)
            ->orderBy('changed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function markSynced()
    {
        $this->update(['synced' => true]);
    }
}
