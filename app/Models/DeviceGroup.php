<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceGroup extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceGroupFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class, 'group_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(SalesShift::class, 'group_id');
    }

    public function scopeForBusiness($query, int|string $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
