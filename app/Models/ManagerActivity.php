<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock or product change made by a manager or storekeeper, shown to
 * the owner on the Manager Activity page.
 */
class ManagerActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'role',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'changes',
        'pin_ok',
        'method',
        'path',
    ];

    protected $casts = [
        'changes' => 'array',
        'pin_ok' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
