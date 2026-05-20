<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Mail\NewBookingMail;
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
use Illuminate\Support\Facades\Mail;

class WriteController extends Controller
{
    /**
     * POST /api/v1/bookings
     * Same rules as widget/admin booking, shop comes from API key context.
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
}
