<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Mail\NewBookingMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Master;
use App\Models\Product;
use App\Services\MasterCascadeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WriteController extends Controller
{
    /**
     * POST /api/v1/bookings
     */
    public function storeBooking(StoreBookingRequest $request): JsonResponse
    {
        $service = Product::with('service')->findOrFail($request->service_id);

        if ($service->type !== 'service') {
            return response()->json(['error' => 'This product is not a service'], 400);
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
                        ->lockForUpdate()
                        ->exists();

                    if (!$conflict) {
                        $masterId = $master->id;
                        break;
                    }
                }

                if (!$masterId) {
                    abort(409, 'No available masters for this time slot');
                }
            } else {
                Master::findOrFail($masterId);

                $conflict = Booking::whereNotIn('status', ['cancelled', 'no_show'])
                    ->where('master_id', $masterId)
                    ->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime)
                    ->lockForUpdate()
                    ->exists();

                if ($conflict) {
                    abort(409, 'This time slot is already taken');
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

        $shop = $request->get('_shop');
        if ($shop && !$shop->relationLoaded('user')) {
            $shop->load('user');
        }

        if ($shop?->user?->email) {
            try { Mail::to($shop->user->email)->send(new NewBookingMail($booking, $shop->name)); } catch (\Throwable) {}
        }
        if ($shop) {
            try { \App\Services\TelegramService::notifyNewBooking($shop, $booking); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyNewBooking($shop, $booking); } catch (\Throwable) {}
        }

        return response()->json([
            'message' => 'Booking created successfully',
            'data'    => $booking,
        ], 201);
    }

    /**
     * PATCH /api/v1/bookings/{id}/status
     * Allowed transitions: pending→confirmed, confirmed→completed
     */
    public function updateBookingStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:confirmed,completed']);

        $booking   = Booking::findOrFail($id);
        $newStatus = $request->status;

        $allowed = [
            'pending'   => ['confirmed'],
            'confirmed' => ['completed'],
        ];

        if (!isset($allowed[$booking->status]) || !in_array($newStatus, $allowed[$booking->status])) {
            return response()->json([
                'error'   => 'Invalid status transition',
                'message' => "Cannot change '{$booking->status}' → '{$newStatus}'. Allowed: pending→confirmed, confirmed→completed",
            ], 422);
        }

        $booking->update(['status' => $newStatus]);
        $booking->load(['service', 'master']);

        $shop = $request->get('_shop');
        if ($shop) {
            try { \App\Services\TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyCustomerStatus($booking->id, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}
        }

        return response()->json([
            'message' => "Booking status updated to {$newStatus}",
            'data'    => $booking,
        ]);
    }

    /**
     * DELETE /api/v1/bookings/{id}
     * Cancels booking — only pending/confirmed can be cancelled.
     */
    public function cancelBooking(string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        if (!in_array($booking->status, ['pending', 'confirmed'])) {
            return response()->json([
                'error'   => 'Cannot cancel this booking',
                'message' => 'Only pending or confirmed bookings can be cancelled (current: ' . $booking->status . ')',
            ], 422);
        }

        $booking->update(['status' => 'cancelled']);
        $booking->load(['service', 'master']);

        $shop = request()->get('_shop');
        if ($shop) {
            try { \App\Services\TelegramService::notifyBookingStatusToCustomer($shop, $booking, 'cancelled'); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyCustomerStatus($booking->id, 'cancelled', $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}
        }

        return response()->json([
            'message' => 'Booking cancelled',
            'data'    => $booking,
        ]);
    }

    /**
     * POST /api/v1/masters
     */
    public function storeMaster(Request $request): JsonResponse
    {
        $shop = $request->get('_shop');
        if ($shop) {
            $limits = $shop->getPlanLimits();
            if ($limits['max_masters'] !== null && Master::count() >= $limits['max_masters']) {
                return response()->json(['error' => 'Masters limit reached for your plan'], 403);
            }
        }

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

        return response()->json(['message' => 'Master created', 'data' => $master], 201);
    }

    /**
     * PATCH /api/v1/masters/{id}
     */
    public function updateMaster(Request $request, string $id): JsonResponse
    {
        $master = Master::findOrFail($id);

        $shop = $request->get('_shop');
        if ($shop && isset($request->is_active) && $request->is_active && !$master->is_active) {
            $limits = $shop->getPlanLimits();
            $max    = $limits['max_masters'];
            if ($max !== null && Master::where('is_active', true)->count() >= $max) {
                return response()->json(['error' => 'Active masters limit reached for your plan'], 403);
            }
        }

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'specialization' => 'nullable|string|max:255',
            'avatar_url'     => 'nullable|url|max:1000',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        $wasActive = $master->is_active;
        $master->update($data);

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

        return response()->json(['message' => 'Master updated', 'data' => $master]);
    }

    /**
     * DELETE /api/v1/masters/{id}
     */
    public function deleteMaster(string $id): JsonResponse
    {
        $master = Master::findOrFail($id);
        $master->delete();

        return response()->json(['message' => 'Master deleted']);
    }

    /**
     * POST /api/v1/clients
     */
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
            return response()->json(['error' => 'Client with this phone already exists'], 409);
        }

        $customer = Customer::create([
            'name'  => $data['name'],
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['message' => 'Client created', 'data' => $customer], 201);
    }
}
