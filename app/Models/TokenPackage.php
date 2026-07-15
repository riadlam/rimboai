<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenPackage extends Model
{
    protected $table = 'token_packages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'price_dzd' => 'decimal:2',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
