<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Services\ImageCompressionService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Чат покупателя с магазином — один диалог на покупателя (PLAN-CHAT.md §2),
 * только мобильное приложение в v1 (§3.1). Владелец/администраторы отвечают
 * через отдельный ChatController в admin-неймспейсе (Ч3).
 */
class ChatController extends Controller
{
    private const PAGE_SIZE = 30;

    /**
     * GET /api/widget/chat
     *
     * Курсор ?before=<message_id> резолвится в (created_at, id) на сервере —
     * id это gen_random_uuid(), пагинация по нему напрямую бессмысленна
     * (см. PLAN-CHAT.md §3.4).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['before' => 'nullable|uuid']);

        $customer = $this->customer($request);
        $thread   = $customer->chatThread;

        if (!$thread) {
            return response()->json(['data' => [], 'thread' => null]);
        }

        $query = ChatMessage::with('replyTo')->where('thread_id', $thread->id);

        if ($request->filled('before')) {
            $cursor = $this->resolveCursor($thread, $request->input('before'));
            if ($cursor) {
                $query->where(function ($q) use ($cursor) {
                    $q->where('created_at', '<', $cursor->created_at)
                      ->orWhere(function ($q2) use ($cursor) {
                          $q2->where('created_at', '=', $cursor->created_at)
                             ->where('id', '<', $cursor->id);
                      });
                });
            }
        }

        $messages = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        return response()->json([
            'data'   => $messages,
            'thread' => [
                'is_blocked_by_shop' => $thread->is_blocked_by_shop,
            ],
        ]);
    }

    /**
     * POST /api/widget/chat/messages
     *
     * Тред создаётся под капотом при первом сообщении (PLAN-CHAT.md §2) —
     * через INSERT ... ON CONFLICT, а не firstOrCreate(), чтобы не словить
     * гонку при двойном тапе/retry (§1.1 П3). client_message_id делает
     * повторную отправку идемпотентной (§5.1).
     */
    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'body'                 => 'nullable|string|max:2000',
            'image_url'            => 'nullable|url|max:1000',
            'client_message_id'    => 'required|uuid',
            'reply_to_message_id'  => 'nullable|uuid',
        ]);

        if (empty($data['body']) && empty($data['image_url'])) {
            return response()->json(['message' => 'Сообщение не может быть пустым'], 422);
        }

        $existingThread = ChatThread::where('customer_id', $customer->id)->first();
        if ($existingThread?->is_blocked_by_shop) {
            return response()->json(['message' => 'Магазин ограничил переписку'], 403);
        }

        $threadId = DB::selectOne(
            'INSERT INTO chat_threads (id, customer_id) VALUES (gen_random_uuid(), ?)
             ON CONFLICT (customer_id) DO UPDATE SET customer_id = EXCLUDED.customer_id
             RETURNING id',
            [$customer->id]
        )->id;

        // Идемпотентность: тот же client_message_id — тот же результат,
        // без создания дубля (см. §5.1).
        $existing = ChatMessage::where('thread_id', $threadId)
            ->where('client_message_id', $data['client_message_id'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $existing->load('replyTo')], 200);
        }

        // Реплай — только на сообщение из ЭТОГО ЖЕ треда (см. Admin\ChatController::sendMessage).
        $replyToId = null;
        if (!empty($data['reply_to_message_id'])) {
            $replyToId = ChatMessage::where('thread_id', $threadId)
                ->where('id', $data['reply_to_message_id'])
                ->value('id');
        }

        $message = ChatMessage::create([
            'thread_id'            => $threadId,
            'sender_type'          => 'customer',
            'body'                 => $data['body'] ?? null,
            'image_url'            => $data['image_url'] ?? null,
            'client_message_id'    => $data['client_message_id'],
            'reply_to_message_id'  => $replyToId,
        ]);
        $message->load('replyTo');

        $preview = $data['body'] ?? '📷 Фото';
        DB::table('chat_threads')->where('id', $threadId)->update([
            'last_message_at'      => $message->created_at,
            'last_message_preview' => mb_substr($preview, 0, 80),
            'updated_at'           => now(),
        ]);
        DB::table('chat_threads')->where('id', $threadId)->increment('unread_by_shop');

        return response()->json(['data' => $message], 201);
    }

    /**
     * POST /api/widget/chat/read
     *
     * `status` на сообщении — единственный источник правды для read-статуса,
     * массово проставляется здесь одним UPDATE (см. PLAN-CHAT.md §1.2).
     * `customer_last_read_at` — только справочная метка, не используется для
     * статуса отдельных сообщений.
     */
    public function markRead(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $thread   = $customer->chatThread;

        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 404);
        }

        DB::table('chat_messages')
            ->where('thread_id', $thread->id)
            ->where('sender_type', 'shop')
            ->where('status', 'sent')
            ->update(['status' => 'read']);

        $thread->update([
            'unread_by_customer'    => 0,
            'customer_last_read_at' => now(),
        ]);

        return response()->json(['message' => 'Прочитано']);
    }

    /**
     * DELETE /api/widget/chat/messages/{message}
     *
     * Покупатель может удалить только СВОЁ сообщение, и только если владелец
     * магазина явно включил это в настройках (`shops.chat_customer_delete_enabled`,
     * выключено по умолчанию — см. PLAN-CHAT.md §11.10, решение принято
     * 2026-08-24). Сообщения магазина покупателю недоступны для удаления ни
     * при каких настройках — это модерация только со стороны магазина
     * (Admin\ChatController::deleteMessage).
     */
    public function destroy(Request $request, string $message): JsonResponse
    {
        $shop = $request->get('_shop');
        if (!$shop || !$shop->chat_customer_delete_enabled) {
            return response()->json(['message' => 'Удаление сообщений недоступно'], 403);
        }

        $customer = $this->customer($request);
        $thread   = $customer->chatThread;
        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 404);
        }

        $chatMessage = ChatMessage::where('thread_id', $thread->id)
            ->where('sender_type', 'customer')
            ->findOrFail($message);

        StorageService::deleteByUrl($chatMessage->image_url);
        $chatMessage->delete();

        $latest = ChatMessage::where('thread_id', $thread->id)
            ->orderByDesc('created_at')
            ->first();

        $thread->update([
            'last_message_at'      => $latest?->created_at,
            'last_message_preview' => $latest
                ? mb_substr($latest->body ?? '📷 Фото', 0, 80)
                : null,
        ]);

        return response()->json(['message' => 'Сообщение удалено']);
    }

    /**
     * GET /api/widget/chat/poll?after=<message_id>
     *
     * Компактный маркер вместо полного списка сообщений (PLAN-CHAT.md §4) —
     * `shop_read_up_to` позволяет клиенту локально обновить галочки на своих
     * уже отрисованных сообщениях без полного перезапроса ленты.
     */
    public function poll(Request $request): JsonResponse
    {
        $request->validate(['after' => 'nullable|uuid']);

        $customer = $this->customer($request);
        $thread   = $customer->chatThread;

        if (!$thread) {
            return response()->json(['has_new' => false, 'unread_total' => 0, 'shop_read_up_to' => null]);
        }

        $hasNew = false;
        if ($request->filled('after')) {
            $cursor = $this->resolveCursor($thread, $request->input('after'));
            $hasNew = $cursor
                ? ChatMessage::where('thread_id', $thread->id)
                    ->where(function ($q) use ($cursor) {
                        $q->where('created_at', '>', $cursor->created_at)
                          ->orWhere(function ($q2) use ($cursor) {
                              $q2->where('created_at', '=', $cursor->created_at)
                                 ->where('id', '>', $cursor->id);
                          });
                    })
                    ->exists()
                : $thread->unread_by_customer > 0;
        } else {
            $hasNew = $thread->unread_by_customer > 0;
        }

        return response()->json([
            'has_new'         => $hasNew,
            'unread_total'    => $thread->unread_by_customer,
            'shop_read_up_to' => $thread->shop_last_read_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/widget/chat/image
     *
     * Без svg в списке типов (в отличие от ImageController::upload) — фото
     * грузит посторонний покупатель, а откроет сотрудник магазина, SVG может
     * нести <script> (см. PLAN-CHAT.md §5, П7).
     *
     * ВАЖНО: правило `image` в Laravel НЕ принимает параметров — это просто
     * ярлык для `mimes:jpg,jpeg,png,bmp,gif,svg,webp` целиком, и
     * `image:jpeg,png,gif,webp` молча игнорирует список, пропуская svg как
     * ни в чём не бывало (баг найден 2026-08-23 при живом тесте — залитый
     * .svg с <script> внутри прошёл валидацию). Правильно — `mimes:...`
     * само по себе, без `image` рядом.
     *
     * Без gif (П21, §1.1) — ни browser-image-compression (админка), ни
     * flutter_image_compress (мобильное) не умеют сжимать gif с сохранением
     * анимации, а честно сжать до 100 КБ больше нечем — раз сжать нельзя,
     * не принимаем совсем.
     *
     * НЕТ проверки на максимальный размер файла и никакого сообщения
     * пользователю про размер (распоряжение 2026-08-23) — каким бы огромным
     * ни был исходник, сервер сам гарантированно сжимает его до ≤100 КБ
     * через ImageCompressionService, а не отклоняет с ошибкой. Единственный
     * потолок — общий лимит PHP на входящий запрос (upload_max_filesize в
     * Dockerfile, 25 МБ) — это инфраструктурная защита от откровенного
     * DoS, а не бизнес-правило, которое видит пользователь.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,webp',
        ], [
            'image.mimes' => 'Файл должен быть изображением (JPEG, PNG или WebP)',
        ]);

        $compressed = ImageCompressionService::compressToJpeg($request->file('image'));

        $filename = Str::uuid() . '.jpg';
        Storage::disk('public')->put('uploads/' . $filename, $compressed);
        $url = Storage::disk('public')->url('uploads/' . $filename);

        return response()->json(['url' => $url]);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    /**
     * Резолвит message_id в (created_at, id) для сравнения курсора — только
     * в пределах СВОЕГО треда, чужой/случайный id просто игнорируется.
     */
    private function resolveCursor(ChatThread $thread, string $messageId): ?ChatMessage
    {
        return ChatMessage::where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->first();
    }
}
