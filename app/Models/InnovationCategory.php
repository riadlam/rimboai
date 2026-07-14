<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InnovationCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'gradient',
        'sort',
        'status',
    ];

    public function innovations(): HasMany
    {
        return $this->hasMany(Innovation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
