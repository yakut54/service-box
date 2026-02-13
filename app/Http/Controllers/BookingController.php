<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Master;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
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

        $startTime = Carbon::parse($request->start_time);
        $endTime = $startTime->copy()->addMinutes($service->service->duration_minutes);

        $masterId = $request->master_id;
        if (!$masterId) {
            $masterId = $this->findAvailableMaster($startTime, $endTime);
            if (!$masterId) {
                return response()->json([
                    'error' => 'No available masters at this time',
                ], 400);
            }
        } else {
            $master = Master::findOrFail($masterId);
            if (!$master->isAvailableAt($startTime, $endTime)) {
                return response()->json([
                    'error' => 'Master is not available at this time',
                ], 400);
            }
        }

        $customer = Customer::findOrCreateByPhone(
            $request->input('customer.phone'),
            [
                'name' => $request->input('customer.name'),
                'email' => $request->input('customer.email'),
            ]
        );

        $booking = Booking::create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'master_id' => $masterId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'pending',
            'customer_name' => $request->input('customer.name'),
            'customer_phone' => $request->input('customer.phone'),
            'customer_email' => $request->input('customer.email'),
            'notes' => $request->notes,
        ]);

        $booking->load(['service', 'master', 'customer']);

        return response()->json([
            'message' => 'Booking created successfully',
            'data' => $booking,
        ], 201);
    }

    /**
     * Get single booking
     */
    public function show(Booking $booking): JsonResponse
    {
        $booking->load(['service', 'master', 'customer']);

        return response()->json([
            'data' => $booking,
        ]);
    }

    /**
     * Update booking status
     *
     * PATCH /api/admin/bookings/{booking}/status
     */
    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
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
            'date' => 'required|date',
            'master_id' => 'nullable|uuid',
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

        $slots = [];
        $workStart = $date->copy()->setTime(9, 0);
        $workEnd = $date->copy()->setTime(20, 0);

        $current = $workStart->copy();
        while ($current->lessThan($workEnd)) {
            $slotEnd = $current->copy()->addMinutes($duration);

            $availableMasters = $masters->filter(function ($master) use ($current, $slotEnd) {
                return $master->isAvailableAt($current, $slotEnd);
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

            $current->addMinutes(30);
        }

        return response()->json([
            'date' => $date->toDateString(),
            'service_id' => $service->id,
            'service_name' => $service->name,
            'duration_minutes' => $duration,
            'slots' => $slots,
        ]);
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
