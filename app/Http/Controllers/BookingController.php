<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Master;
use App\Models\Product;
use App\Models\Shop;
use App\Services\TenantService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    /**
     * Normalize phone to +7XXXXXXXXXX format (digits only, with leading +)
     */
    private function normalizePhone(string $phone): string
    {
        return Customer::normalizePhone($phone);
    }
    /**
     * Get list of bookings
     *
     * Query params: status, master_id, date
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()
            ->with(['service', 'master', 'customer']);

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('master_id')) {
            $query->where('master_id', $request->master_id);
        }

        if ($request->filled('date')) {
            $query->onDate($request->date);
        } elseif ($request->filled('date_from') || $request->filled('date_to')) {
            if ($request->filled('date_from')) $query->whereDate('start_time', '>=', $request->date_from);
            if ($request->filled('date_to'))   $query->whereDate('start_time', '<=', $request->date_to);
        }

        $bookings = $query->orderBy('start_time')->get();

        return response()->json([
            'data' => $bookings,
            'count' => $bookings->count(),
        ]);
    }

    /**
     * Store a new booking
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $service = Product::with('service')->findOrFail($request->service_id);

        if ($service->type !== 'service') {
            return response()->json([
                'error' => 'This product is not a service',
            ], 400);
        }

        // Приводим к UTC — Eloquent форматирует без offset ('Y-m-d H:i:s'),
        // поэтому Carbon должен быть в UTC перед записью в TIMESTAMPTZ.
        $startTime = Carbon::parse($request->start_time)->utc();
        $endTime = $startTime->copy()->addMinutes($service->service->duration_minutes);

        $customerPhone = $this->normalizePhone($request->input('customer.phone'));

        $booking = DB::transaction(function () use ($request, $service, $startTime, $endTime, $customerPhone) {
            $masterId = $request->master_id;

            if (!$masterId) {
                // Автовыбор мастера внутри транзакции с блокировкой строк
                $masters = Master::active()->get();
                foreach ($masters as $master) {
                    // lockForUpdate предотвращает race condition
                    $conflict = Booking::whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('master_id', $master->id)
                        ->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime)
                        ->lockForUpdate()
                        ->exists();

                    if (!$conflict) {
                        $masterId = $master->id;
                        break;
                    }
                }

                if (!$masterId) {
                    abort(409, 'На это время нет свободных мастеров');
                }
            } else {
                Master::findOrFail($masterId);

                // Проверка с блокировкой строк — защита от race condition
                $conflict = Booking::whereNotIn('status', ['cancelled', 'no_show'])
                    ->where('master_id', $masterId)
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime)
                    ->lockForUpdate()
                    ->exists();

                if ($conflict) {
                    abort(409, 'Этот слот только что заняли — выберите другое время');
                }
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

        return response()->json([
            'message' => 'Booking created successfully',
            'data'    => $booking,
        ], 201);
    }

    /**
     * Get single booking
     */
    public function show(string $booking): JsonResponse
    {
        $booking = Booking::with(['service', 'master', 'customer'])->findOrFail($booking);

        return response()->json([
            'data' => $booking,
        ]);
    }

    /**
     * Update booking status
     *
     * PATCH /api/admin/bookings/{booking}/status
     */
    public function updateStatus(Request $request, string $booking): JsonResponse
    {
        $booking = Booking::findOrFail($booking);
        $request->validate([
            'status' => 'required|in:pending,confirmed,completed,cancelled,no_show',
        ]);

        $booking->update(['status' => $request->status]);
        $booking->load(['service', 'master', 'customer']);

        return response()->json([
            'message' => 'Booking status updated',
            'data' => $booking,
        ]);
    }

    /**
     * Get available time slots for a service
     *
     * GET /api/admin/bookings/available-slots
     */
    public function availableSlots(Request $request): JsonResponse
    {
        $request->validate([
            'service_id' => 'required|uuid',
            'date'       => 'required|date',
            'master_id'  => 'nullable|uuid',
        ]);

        $service = Product::with('service')->findOrFail($request->service_id);
        $date = Carbon::parse($request->date);
        $duration = $service->service->duration_minutes;

        $mastersQuery = Master::active();
        if ($request->filled('master_id')) {
            $mastersQuery->where('id', $request->master_id);
        }
        $masters = $mastersQuery->get();

        if ($masters->isEmpty()) {
            return response()->json([
                'slots' => [],
                'message' => 'No available masters',
            ]);
        }

        // Load shop work hours (from request attr for admin, or via TenantService for widget)
        $shop = $request->attributes->get('shop');
        if (!$shop && TenantService::getCurrentShopId()) {
            $shop = Shop::find(TenantService::getCurrentShopId());
        }

        $timezone      = $shop?->timezone   ?? 'Europe/Moscow';
        $workStartTime = $shop?->work_start ?? '09:00';
        $workEndTime   = $shop?->work_end   ?? '20:00';
        $slotStep      = $shop?->slot_duration ?? 30;

        // Явно создаём Carbon через createFromFormat с timezone магазина.
        // Carbon::parse(date, tz) некорректно трактует полночь — внутренний UTC timestamp
        // остаётся UTC-полночью, и setTime() ставит время в UTC, а не в tz магазина.
        // Итог: workStart оказывался завтрашним днём, diffInMinutes давал -623, current уходил на вчера.
        $reqDate   = $request->date; // 'YYYY-MM-DD'
        $workStart = Carbon::createFromFormat('Y-m-d H:i', "$reqDate $workStartTime", $timezone);
        $workEnd   = Carbon::createFromFormat('Y-m-d H:i', "$reqDate $workEndTime",   $timezone);
        $now       = Carbon::now($timezone);

        $slots   = [];
        $current = $workStart->copy();

        // Пропускаем прошедшие слоты (сравниваем в timezone магазина).
        // isToday() вызываем на $now — он гарантированно в нужном tz.
        // diffInMinutes($workStart, $now) — направленная разница (now - workStart),
        // но мы уже проверили $now > $workStart, поэтому используем abs-версию $workStart->diffInMinutes($now).
        $todayStr = $now->toDateString();
        if ($reqDate === $todayStr && $now->greaterThan($workStart)) {
            $minutesPassed = (int) $workStart->diffInMinutes($now);
            $slotsSkipped  = (int) ceil($minutesPassed / $slotStep);
            $current->addMinutes($slotsSkipped * $slotStep);
        }

        while ($current->copy()->addMinutes($duration)->lessThanOrEqualTo($workEnd)) {
            $slotEnd = $current->copy()->addMinutes($duration);

            // Передаём UTC-копии — иначе Eloquent форматирует Carbon в timezone магазина
            // без offset, и PostgreSQL сравнивает как UTC (неправильное время слота).
            $availableMasters = $masters->filter(function ($master) use ($current, $slotEnd) {
                return $master->isAvailableAt($current->copy()->utc(), $slotEnd->copy()->utc());
            });

            if ($availableMasters->isNotEmpty()) {
                $slots[] = [
                    'time' => $current->format('H:i'),
                    'datetime' => $current->toIso8601String(),
                    'available' => true,
                    'masters' => $availableMasters->map(fn($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                    ])->values(),
                ];
            }

            $current->addMinutes($slotStep);
        }

        return response()->json([
            'date' => $date->toDateString(),
            'service_id' => $service->id,
            'service_name' => $service->name,
            'duration_minutes' => $duration,
            'slots' => $slots,
        ]);
    }

    /**
     * Widget: get bookings by customer phone
     *
     * GET /api/widget/bookings?phone=xxx
     */
    public function widgetBookingsByPhone(Request $request): JsonResponse
    {
        // Phone comes from verified token (injected by VerifyPhoneToken middleware)
        $phone = $this->normalizePhone($request->verified_phone ?? $request->phone);

        $bookings = Booking::with(['service', 'master'])
            ->where('customer_phone', $phone)
            ->latest('start_time')
            ->limit(50)
            ->get()
            ->map(function ($booking) {
                // Strip sensitive data for widget display
                return [
                    'id' => $booking->id,
                    'status' => $booking->status,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'notes' => $booking->notes,
                    'service' => $booking->service ? [
                        'id' => $booking->service->id,
                        'name' => $booking->service->name,
                    ] : null,
                    'master' => $booking->master ? [
                        'id' => $booking->master->id,
                        'name' => $booking->master->name,
                    ] : null,
                ];
            });

        return response()->json([
            'data' => $bookings,
        ]);
    }

    /**
     * Cancel booking from widget
     *
     * PATCH /api/widget/bookings/{id}/cancel
     */
    public function widgetCancel(Request $request, string $booking): JsonResponse
    {
        $phone = $this->normalizePhone($request->verified_phone ?? $request->phone);

        $booking = Booking::findOrFail($booking);

        // Ensure booking belongs to this phone
        if ($booking->customer_phone !== $phone) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($booking->status, ['pending', 'confirmed'])) {
            return response()->json([
                'message' => 'Запись нельзя отменить (статус: ' . $booking->status . ')',
            ], 422);
        }

        $booking->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Запись отменена', 'status' => 'cancelled']);
    }

    /**
     * Booking stats by status (for admin dashboard)
     *
     * GET /api/admin/bookings/stats
     */
    public function adminStats(): JsonResponse
    {
        $counts = Booking::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
        $result = ['total_bookings' => 0];

        foreach ($statuses as $status) {
            $count = (int) ($counts[$status] ?? 0);
            $result["{$status}_bookings"] = $count;
            $result['total_bookings'] += $count;
        }

        return response()->json($result);
    }

    /**
     * Get list of active masters
     */
    public function masters(): JsonResponse
    {
        $masters = Master::active()->orderBy('sort_order')->get();

        return response()->json([
            'data' => $masters,
        ]);
    }

    /**
     * Delete a booking (cascade)
     *
     * DELETE /api/admin/bookings/{booking}
     */
    public function destroy(string $booking): JsonResponse
    {
        $booking = Booking::findOrFail($booking);
        $booking->delete();

        return response()->json(['message' => 'Запись удалена']);
    }

    protected function findAvailableMaster(Carbon $startTime, Carbon $endTime): ?string
    {
        $masters = Master::active()->get();

        foreach ($masters as $master) {
            if ($master->isAvailableAt($startTime, $endTime)) {
                return $master->id;
            }
        }

        return null;
    }
}
