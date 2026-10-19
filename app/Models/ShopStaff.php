<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShopStaff extends Model
{
    use HasUuids;

    protected $table = 'shop_staff';

    protected $fillable = [
        'shop_id',
        'user_id',
        'role',
        'master_id',
        'admin_id',
        'category_ids',
        'telegram_chat_id',
        'max_user_id',
        'messenger_link_token',
        'messenger_link_token_expires_at',
        'invite_email',
        'invite_name',
        'avatar_url',
        'phone',
        'last_login_at',
        'invite_token',
        'accepted_at',
        'invite_expires_at',
    ];

    protected $casts = [
        'accepted_at'                    => 'datetime',
        'invite_expires_at'              => 'datetime',
        'last_login_at'                  => 'datetime',
        'messenger_link_token_expires_at'=> 'datetime',
        'category_ids'                   => 'array',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Администратор, к которому привязан этот сборщик (null — подчиняется владельцу). */
    public function admin()
    {
        return $this->belongsTo(ShopStaff::class, 'admin_id');
    }

    /** Сборщики, привязанные к этому администратору. */
    public function collectors()
    {
        return $this->hasMany(ShopStaff::class, 'admin_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Ограничение по категориям, которое реально действует на этого
     * сотрудника — для admin это его собственный category_ids; для
     * collector, привязанного к админу, это ограничение НАСЛЕДУЕТСЯ от
     * админа (та же логика, что раньше была продублирована инлайном в
     * SetShopFromAuth — вынесено сюда, чтобы остаться одной копией:
     * нужна и там, и в фильтре кандидатов на делегирование заказа).
     * null — ограничения нет (владелец, обычный админ, сборщик без
     * привязки к админу).
     */
    public function effectiveCategoryIds(): ?array
    {
        if ($this->role === 'collector' && $this->admin_id) {
            return $this->admin?->category_ids;
        }

        return $this->category_ids;
    }
}
