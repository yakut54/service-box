<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CategoryChange extends Model
{
    use HasUuids;

    protected $table = 'category_changes';

    const UPDATED_AT = null;

    protected $fillable = [
        'category_id',
        'category_name',
        'action',
        'field',
        'old_value',
        'new_value',
        'actor_user_id',
        'actor_name',
        'actor_role',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
