<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id',
        'status',
        'total_price',
        'commission_amount',
        'payout_status',
        'discount_id',
        'discount_code',
        'discount_amount',
        'payment_id',
        'payment_url',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'delivery_method',
        'delivery_price',
        'notes',
        'paid_at',
        'consent_offer_accepted',
        'consent_privacy_accepted',
        'consent_accepted_at',
        'consent_ip',
        'consent_ua',
        'weighed_at',
        'surcharge_amount',
        'surcharge_status',
        'surcharge_payment_id',
        'surcharge_payment_url',
        'surcharge_requested_at',
        'surcharge_deadline_at',
        'surcharge_auto_charged_at',
        'payment_method_id',
        'collector_id',
        'collector_name',
        'picking_started_at',
        'pick_note',
        'pick_note_at',
        'pick_note_edited_at',
        'seen_at',
    ];

    protected $casts = [
        'total_price'              => 'integer',
        'commission_amount'        => 'integer',
        'discount_amount'          => 'integer',
        'delivery_price'           => 'integer',
        'shipping_address'         => 'array',
        'consent_offer_accepted'   => 'boolean',
        'consent_privacy_accepted' => 'boolean',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
        'paid_at'                  => 'datetime',
        'consent_accepted_at'      => 'datetime',
        'weighed_at'               => 'datetime',
        'surcharge_amount'         => 'integer',
        'surcharge_requested_at'   => 'datetime',
        'surcharge_deadline_at'    => 'datetime',
        'surcharge_auto_charged_at' => 'datetime',
        'picking_started_at'       => 'datetime',
        'seen_at'                  => 'datetime',
        'pick_note_at'             => 'datetime',
        'pick_note_edited_at'      => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function discount()
    {
        return $this->belongsTo(\App\Models\Discount::class);
    }

    public function activities()
    {
        return $this->hasMany(OrderActivity::class);
    }

    /**
     * Допустимые переходы статуса через updateStatus() (OrderController и
     * Api\WriteController) — до этой проверки её не было вообще, только
     * целевой статус валидировался против общего списка. Значило, что API-
     * запросом можно было переоткрыть уже completed/cancelled заказ: остаток
     * уже списан/возвращён, комиссия уже посчитана — повторный проход через
     * эту логику не предусмотрен нигде. completed/cancelled — терминальные,
     * из них переходов нет. Набор целей на каждый статус подобран строго под
     * то, что уже умеет делать фронт (кнопки «В работу»/«Вернуть в работу»/
     * «Завершить»/«Отменить» в OrderDetailView.vue и «Готово» у сборщика) —
     * бэкенд теперь просто не доверяет фронту то же самое молча.
     * `paid`/`needs_attention` сюда не входят ни как цель (их проставляют
     * вебхук ЮKassa и reportProblem() соответственно, не updateStatus).
     */
    public const TRANSITIONS = [
        'pending'         => ['processing', 'completed', 'cancelled'],
        'paid'            => ['processing', 'completed', 'cancelled'],
        'processing'      => ['completed', 'cancelled'],
        'needs_attention' => ['processing', 'cancelled'],
        'completed'       => [],
        'cancelled'       => [],
    ];

    public const STATUS_LABELS = [
        'pending'         => 'Ожидает',
        'paid'            => 'Оплачен',
        'processing'      => 'В работе',
        'needs_attention' => 'Требует внимания',
        'completed'       => 'Завершён',
        'cancelled'       => 'Отменён',
    ];

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_at');
    }

    public function getTotalPriceRublesAttribute(): float
    {
        return $this->total_price / 100;
    }

    public function calculateTotal(): void
    {
        $this->total_price = $this->items()->sum(\DB::raw('price * quantity'));
        $this->save();
    }

    /**
     * Вебхук ЮKassa теперь может прилететь, когда заказ уже ушёл из pending
     * (claim() сборщика переводит в processing без проверки оплаты, см.
     * PaymentController::handleYooKassaWebhook) — нельзя просто писать
     * status='paid' поверх чего угодно, иначе поздний вебхук откатывал бы
     * "В работе"/"Требует внимания" обратно на "Оплачен". Статус двигаем
     * только из pending; из любой другой стадии — оставляем как есть,
     * только фиксируем сам факт оплаты (paid_at/payment_id/комиссия).
     */
    public function markAsPaid(string $paymentId): void
    {
        $wasPaid = $this->status === 'paid';
        $isClosed = in_array($this->status, ['completed', 'cancelled'], true);

        $this->update([
            'status' => $this->status === 'pending' ? 'paid' : $this->status,
            'payment_id' => $paymentId,
            'paid_at' => now(),
            'commission_amount' => $this->calculateCommission(),
        ]);

        if ($this->customer) {
            $this->customer->updateStats();
        }

        // На закрытый заказ (completed/cancelled) пуш "Оплата получена" не
        // шлём — поздняя оплата уже отменённого/завершённого заказа не
        // повод путать покупателя, персонал увидит это в самом заказе.
        if (!$wasPaid && !$isClosed) {
            \App\Jobs\SendOrderStatusPush::dispatchFor($this, 'paid');
        }
    }

    /**
     * 20% от суммы заказа (см. config/platform.php). Снапшот на момент
     * оплаты — не пересчитывается задним числом при смене ставки.
     */
    public function calculateCommission(): int
    {
        $percent = config('platform.commission_percent');
        return (int) round($this->total_price * $percent / 100);
    }
}
