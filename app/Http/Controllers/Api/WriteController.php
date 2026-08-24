<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Mail\NewBookingMail;
use App\Mail\NewOrderMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Master;
use App\Models\Order;
use App\Models\Product;
use App\Services\DiscountService;
use App\Services\MasterCascadeService;
use App\Services\StorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WriteController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    // ── Bookings ─────────────────────────────────────────────────────────────

    public function storeBooking(StoreBookingRequest $request): JsonResponse
    {
        $service = Product::with('service')->findOrFail($request->service_id);

        if ($service->type !== 'service') {
            return response()->json(['error' => 'Этот товар не является услугой'], 400);
        }

        $startTime     = Carbon::parse($request->start_time)->utc();
        $endTime       = $startTime->copy()->addMinutes($service->service->duration_minutes);
        $customerPhone = Customer::normalizePhone($request->input('customer.phone'));

        $booking = DB::transaction(function () use ($request, $service, $startTime, $endTime, $customerPhone) {
            $masterId = $request->master_id;

            if (!$masterId) {
                $masters = Master::active()->canPerform($service->id)->get();
                foreach ($masters as $master) {
                    $conflict = Booking::whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('master_id', $master->id)
                        ->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime)
                        ->lockForUpdate()->exists();

                    if (!$conflict) { $masterId = $master->id; break; }
                }
                if (!$masterId) { abort(409, 'Нет свободных мастеров на это время'); }
            } else {
                Master::findOrFail($masterId);
                $conflict = Booking::whereNotIn('status', ['cancelled', 'no_show'])
                    ->where('master_id', $masterId)
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime)
                    ->lockForUpdate()->exists();
                if ($conflict) { abort(409, 'Это время уже занято'); }
            }

            $customer = Customer::findOrCreateByPhone($customerPhone, [
                'name'  => $request->input('customer.name'),
                'email' => $request->input('customer.email'),
            ]);

            return Booking::create([
                'service_id'     => $service->id,
                'customer_id'    => $customer->id,
                'master_id'      => $masterId,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
                'status'         => 'pending',
                'customer_name'  => $request->input('customer.name'),
                'customer_phone' => $customerPhone,
                'customer_email' => $request->input('customer.email'),
                'notes'          => $request->notes,
            ]);
        });

        $booking->load(['service', 'master', 'customer']);
        $shop = $request->get('_shop');
        if ($shop && !$shop->relationLoaded('user')) { $shop->load('user'); }

        if ($shop?->user?->email) {
            try { Mail::to($shop->user->email)->send(new NewBookingMail($booking, $shop->name)); } catch (\Throwable) {}
        }
        if ($shop) {
            try { \App\Services\TelegramService::notifyNewBooking($shop, $booking); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyNewBooking($shop, $booking); } catch (\Throwable) {}
        }

        return response()->json(['message' => 'Запись создана', 'data' => $booking], 201);
    }

    public function updateBookingStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:confirmed,completed']);

        $booking   = Booking::findOrFail($id);
        $newStatus = $request->status;

        $allowed = ['pending' => ['confirmed'], 'confirmed' => ['completed']];

        if (!isset($allowed[$booking->status]) || !in_array($newStatus, $allowed[$booking->status])) {
            return response()->json([
                'error'   => 'Недопустимый переход статуса',
                'message' => "Нельзя изменить статус с «{$booking->status}» на «{$newStatus}». Разрешено: pending→confirmed, confirmed→completed",
            ], 422);
        }

        $booking->update(['status' => $newStatus]);
        $booking->load(['service', 'master']);

        $shop = $request->get('_shop');
        if ($shop) {
            try { \App\Services\TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyCustomerStatus($booking->id, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}
        }

        return response()->json(['message' => "Статус записи изменён на {$newStatus}", 'data' => $booking]);
    }

    public function cancelBooking(string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        if (!in_array($booking->status, ['pending', 'confirmed'])) {
            return response()->json([
                'error'   => 'Эту запись нельзя отменить',
                'message' => 'Отменить можно только запись со статусом «ожидает» или «подтверждена» (сейчас: ' . $booking->status . ')',
            ], 422);
        }

        $booking->update(['status' => 'cancelled']);
        $booking->load(['service', 'master']);

        $shop = request()->get('_shop');
        if ($shop) {
            try { \App\Services\TelegramService::notifyBookingStatusToCustomer($shop, $booking, 'cancelled'); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyCustomerStatus($booking->id, 'cancelled', $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}
        }

        return response()->json(['message' => 'Запись отменена', 'data' => $booking]);
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    public function storeOrder(StoreOrderRequest $request): JsonResponse
    {
        $shop = $request->get('_shop');

        $customer = Customer::findOrCreateByPhone(
            $request->input('customer.phone'),
            ['name' => $request->input('customer.name'), 'email' => $request->input('customer.email')]
        );

        [$order, $cartItems] = DB::transaction(function () use ($request, $customer) {
            $order = Order::create([
                'customer_id'              => $customer->id,
                'status'                   => 'pending',
                'customer_name'            => $request->input('customer.name'),
                'customer_email'           => $request->input('customer.email'),
                'customer_phone'           => Customer::normalizePhone($request->input('customer.phone')),
                'shipping_address'         => $request->shipping_address,
                'delivery_method'          => $request->delivery_method,
                'delivery_price'           => (int) ($request->delivery_price ?? 0),
                'notes'                    => $request->notes,
                'consent_offer_accepted'   => (bool) $request->input('consent_offer_accepted', false),
                'consent_privacy_accepted' => (bool) $request->input('consent_privacy_accepted', false),
                'consent_accepted_at'      => now(),
                'consent_ip'               => $request->ip(),
                'consent_ua'               => substr($request->userAgent() ?? '', 0, 500),
            ]);

            $cartItems = [];
            foreach ($request->items as $item) {
                $product  = Product::with('physical')->lockForUpdate()->findOrFail($item['product_id']);
                $saleMode = $product->physical->sale_mode ?? 'piece';
                $weightGrams = null;

                if ($saleMode === 'piece') {
                    if (!isset($item['quantity'])) {
                        abort(422, "Не указано количество для товара «{$product->name}»");
                    }
                    $quantity  = (int) $item['quantity'];
                    $itemPrice = $product->price;

                    if ($product->type === 'physical' && $product->physical) {
                        if ($product->physical->stock_quantity < $quantity) {
                            abort(409, "Товар «{$product->name}» закончился на складе");
                        }
                        $product->physical->decrement('stock_quantity', $quantity);
                    }
                } else {
                    // weight_fixed / weight_variable — см. PLAN.md, «Развесной товар».
                    if (!isset($item['weight_grams'])) {
                        abort(422, "Не указан вес для товара «{$product->name}»");
                    }
                    $weightGrams = (int) $item['weight_grams'];
                    $min  = $product->physical->weight_min_grams ?? 100;
                    $max  = $product->physical->weight_max_grams ?? 5000;
                    $step = $product->physical->weight_step_grams ?? 100;

                    if ($weightGrams < $min || $weightGrams > $max) {
                        abort(422, "Вес товара «{$product->name}» должен быть от {$min} до {$max} г");
                    }
                    if (($weightGrams - $min) % $step !== 0) {
                        abort(422, "Вес товара «{$product->name}» должен быть кратен шагу {$step} г");
                    }

                    $quantity  = 1;
                    $itemPrice = (int) round($product->price * $weightGrams / 1000);
                }

                $order->items()->create([
                    'product_id'   => $product->id,
                    'quantity'     => $quantity,
                    'price'        => $itemPrice,
                    'product_name' => $product->name,
                    'product_type' => $product->type,
                    'weight_grams' => $weightGrams,
                ]);

                $cartItems[] = [
                    'product_id'  => $product->id,
                    'category_id' => $product->category_id ?? null,
                    'quantity'    => $quantity,
                    'price'       => $itemPrice,
                ];
            }

            $order->calculateTotal();

            $minOrder = config('platform.min_order_amount_kopecks');
            if ($order->total_price < $minOrder) {
                abort(422, 'Минимальная сумма заказа — ' . number_format($minOrder / 100, 0, ',', ' ') . ' ₽');
            }

            return [$order, $cartItems];
        });

        // Best discount wins (promo code vs auto-apply)
        $discount = $discountAmount = null;
        $promoDiscount = $promoAmount = null;

        if ($request->filled('discount_code')) {
            try {
                $result        = $this->discountService->validate($request->discount_code, $order->total_price, $request->input('customer.phone'), $cartItems);
                $promoDiscount = $result['discount'];
                $promoAmount   = $result['amount'];
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['error' => $e->errors()['discount_code'][0] ?? 'Недействительный промокод', 'errors' => $e->errors()], 422);
            }
        }

        $autoDiscount = $this->discountService->findAutoApply($order->total_price, $request->input('customer.phone'), $cartItems);
        $autoAmount   = $autoDiscount ? $this->discountService->calculate($autoDiscount, $order->total_price, $cartItems) : 0;

        if ($promoDiscount && $promoAmount >= $autoAmount) {
            $discount = $promoDiscount; $discountAmount = $promoAmount;
        } elseif ($autoDiscount && $autoAmount > (int) $promoAmount) {
            $discount = $autoDiscount; $discountAmount = $autoAmount;
        }

        if ($discount && $discountAmount > 0) {
            $order->update([
                'discount_id'     => $discount->id,
                'discount_code'   => $discount->code,
                'discount_amount' => $discountAmount,
                'total_price'     => max(0, $order->total_price - $discountAmount),
            ]);
            $this->discountService->recordUse($discount, $order);
        }

        if ($order->delivery_price > 0) {
            $order->update(['total_price' => $order->total_price + $order->delivery_price]);
            $order->refresh();
        }

        $customer->updateStats();
        $order->load(['items', 'customer']);

        if ($shop && $shop->user?->email) {
            try { Mail::to($shop->user->email)->send(new NewOrderMail($order, $shop->name)); } catch (\Throwable) {}
        }
        if ($shop) {
            try { \App\Services\TelegramService::notifyNewOrder($shop, $order); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyNewOrder($shop, $order); } catch (\Throwable) {}
        }

        return response()->json(['message' => 'Заказ создан', 'data' => $order], 201);
    }

    public function updateOrderStatus(Request $request, string $id): JsonResponse
    {
        // 'paid' сознательно не входит — см. OrderController::updateStatus,
        // тот же вопрос комиссии актуален и для внешнего API.
        $request->validate(['status' => 'required|in:processing,completed,cancelled']);

        $order     = Order::with('items.product')->findOrFail($id);
        $oldStatus = $order->status;
        $newStatus = $request->status;

        if ($newStatus === 'completed' && !$order->paid_at) {
            return response()->json([
                'message' => 'Нельзя завершить неоплаченный заказ — оплата проходит только через ЮKassa',
            ], 422);
        }

        $order->update(['status' => $newStatus]);

        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            foreach ($order->items as $item) {
                if ($item->product && $item->product->type === 'physical') {
                    $item->product->physical?->increment('stock_quantity', $item->quantity);
                }
            }

            if ($order->commission_amount > 0) {
                $order->update(['commission_amount' => 0]);
            }
        }

        $order->load(['items', 'customer']);

        return response()->json(['message' => "Статус заказа изменён на {$newStatus}", 'data' => $order]);
    }

    // ── Masters ──────────────────────────────────────────────────────────────

    public function storeMaster(Request $request): JsonResponse
    {
        $shop = $request->get('_shop');

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'specialization' => 'nullable|string|max:255',
            'avatar_url'     => 'nullable|url|max:1000',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        $master = Master::create($data);
        $master->load('services:id,name');

        return response()->json(['message' => 'Мастер добавлен', 'data' => $master], 201);
    }

    public function updateMaster(Request $request, string $id): JsonResponse
    {
        $master = Master::findOrFail($id);
        $shop   = $request->get('_shop');

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'specialization' => 'nullable|string|max:255',
            'avatar_url'     => 'nullable|url|max:1000',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        $oldAvatarUrl = $master->avatar_url;
        $wasActive    = $master->is_active;
        $master->update($data);

        if (isset($data['avatar_url']) && $data['avatar_url'] !== $oldAvatarUrl) {
            StorageService::deleteByUrl($oldAvatarUrl);
        }

        if ($shop && isset($data['is_active'])) {
            if ($wasActive && !$master->is_active) {
                $hidden = MasterCascadeService::handleDeactivation($master->id, $shop->schema_name, $shop);
                MasterCascadeService::notifyDeactivation($shop, $hidden);
            } elseif (!$wasActive && $master->is_active) {
                $restored = MasterCascadeService::handleReactivation($master->id, $shop->schema_name, $shop);
                MasterCascadeService::notifyReactivation($shop, $restored);
            }
        }

        $master->load('services:id,name');
        return response()->json(['message' => 'Мастер обновлён', 'data' => $master]);
    }

    public function deleteMaster(string $id): JsonResponse
    {
        $master    = Master::findOrFail($id);
        $avatarUrl = $master->avatar_url;
        $master->delete();
        StorageService::deleteByUrl($avatarUrl);

        return response()->json(['message' => 'Мастер удалён']);
    }

    // ── Clients ──────────────────────────────────────────────────────────────

    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $phone = Customer::normalizePhone($data['phone']);

        if (Customer::where('phone', $phone)->exists()) {
            return response()->json(['error' => 'Клиент с таким телефоном уже существует'], 409);
        }

        $customer = Customer::create([
            'name'  => $data['name'],
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['message' => 'Клиент создан', 'data' => $customer], 201);
    }
}
