<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TextToMusicCategory extends Model
{
    protected $table = 'text_to_music_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(TextToMusicModel::class, 'category_id')
            ->orderBy('sort')
            ->orderBy('name');
    }
}
