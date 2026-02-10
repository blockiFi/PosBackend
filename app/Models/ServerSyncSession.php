<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerSyncSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'server_id',
        'direction',
        'status',
        'records_sent',
        'records_received',
        'records_accepted',
        'records_rejected',
        'records_applied',
        'error_message'
    ];

    protected $casts = [
        'records_sent' => 'integer',
        'records_received' => 'integer',
        'records_accepted' => 'integer',
        'records_rejected' => 'integer',
        'records_applied' => 'integer',
    ];

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePush($query)
    {
        return $query->where('direction', 'push');
    }

    public function scopePull($query)
    {
        return $query->where('direction', 'pull');
    }

    public function scopeRecent($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
