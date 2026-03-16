<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeedImport extends Model
{
    protected $fillable = [
        'uuid',
        'business_id',
        'branch_id',
        'user_id',
        'entity',
        'status',
        'file_path',
        'mapping',
        'unique_key',
        'delete',
        'total_rows',
        'created',
        'updated',
        'deleted',
        'failed',
        'errors',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'errors' => 'array',
            'delete' => 'boolean',
            'total_rows' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'deleted' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => 'completed',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
            'errors' => ! empty($result['errors']) ? $result['errors'] : null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'errors' => ['general' => $error],
            'completed_at' => now(),
        ]);
    }
}
