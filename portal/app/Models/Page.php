<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    public const MENU_GROUPS = ['about' => 'About', 'admission' => 'Admission'];

    protected $fillable = ['slug', 'title', 'body', 'menu_group', 'show_in_menu', 'sort_order', 'is_published', 'updated_by'];

    protected function casts(): array
    {
        return ['show_in_menu' => 'boolean', 'is_published' => 'boolean'];
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
