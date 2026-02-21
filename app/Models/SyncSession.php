<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'device_id',
        'business_id',
        'user_id',
        'direction',
        'status',
        'started_at',
        'completed_at',
        'records_pushed',
        'records_pulled',
        'conflicts_detected',
        'conflicts_resolved',
        'errors_count',
        'last_activity_at',
        'summary',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'records_pushed' => 'integer',
        'records_pulled' => 'integer',
        'conflicts_detected' => 'integer',
        'conflicts_resolved' => 'integer',
        'errors_count' => 'integer',
        'summary' => 'array',
        'metadata' => 'array',
    ];

    // Relationships
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceRegistration::class, 'device_id', 'id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['initiated', 'in_progress']);
    }

    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    // Helper Methods
    public function startSession()
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    public function completeSession($status = 'completed')
    {
        $this->update([
            'status' => $status,
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    public function recordPush($count)
    {
        $this->increment('records_pushed', $count);
        $this->touch('last_activity_at');
    }

    public function recordPull($count)
    {
        $this->increment('records_pulled', $count);
        $this->touch('last_activity_at');
    }

    public function recordConflict($resolved = false)
    {
        $this->increment('conflicts_detected');
        if ($resolved) {
            $this->increment('conflicts_resolved');
        }
        $this->touch('last_activity_at');
    }

    public function recordError($message = null)
    {
        $this->increment('errors_count');
        if ($message) {
            $this->update(['error_message' => $message]);
        }
        $this->touch('last_activity_at');
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'partial']);
    }

    public function hasErrors(): bool
    {
        return $this->errors_count > 0;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts_detected > 0;
    }
}
