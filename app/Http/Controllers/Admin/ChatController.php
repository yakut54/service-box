<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ShopStaff;
use App\Services\ImageCompressionService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Чат с покупателями — сторона магазина (владелец + администраторы, общий
 * почтовый ящик — см. PLAN-CHAT.md §3.2/§7). Мастера сюда не попадают
 * (RequireNotMaster на всей группе /admin). Не путать с покупательским
 * \App\Http\Controllers\ChatController.
 */
class ChatController extends Controller
{
    private const PAGE_SIZE = 30;

    /**
     * GET /api/admin/chat/threads
     *
     * Список диалогов, отсортирован по свежести. Поиск — по имени/телефону
     * покупателя, тот же паттерн, что уже есть в CustomerController::index.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChatThread::with('customer:id,name,phone,avatar_url')
            ->orderByRaw('last_message_at DESC NULLS LAST');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%");
            });
        }

        $threads = $query->get();

        return response()->json(['data' => $threads]);
    }

    /**
     * GET /api/admin/chat/threads/{id}/messages
     *
     * Курсор ?before=<message_id> — та же логика резолва в (created_at, id)
     * на сервере, что и у покупательской стороны (см. PLAN-CHAT.md §3.4).
     * Контекст покупателя (кол-во/сумма заказов) — в шапке диалога у
     * сотрудника, данные уже есть на модели `Customer`, ничего не считаем
     * заново (§7, П11).
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $request->validate(['before' => 'nullable|uuid']);

        $thread = ChatThread::with('customer:id,name,phone,avatar_url,total_orders,total_spent')
            ->findOrFail($id);

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
                'id'                 => $thread->id,
                'is_blocked_by_shop' => $thread->is_blocked_by_shop,
                'customer'           => $thread->customer,
            ],
        ]);
    }

    /**
     * POST /api/admin/chat/threads/{id}/messages
     *
     * sender_staff_id — только для сотрудника (роль admin), у владельца
     * записи ShopStaff нет вообще (он не сотрудник самого себя), поэтому
     * для owner остаётся null — так и задумано (§7: покупателю видно
     * только «магазин ответил», не «Оля ответила»).
     */
    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $thread = ChatThread::findOrFail($id);

        $data = $request->validate([
            'body'                 => 'nullable|string|max:2000',
            'image_url'            => 'nullable|url|max:1000',
            'client_message_id'    => 'required|uuid',
            'reply_to_message_id'  => 'nullable|uuid',
        ]);

        if (empty($data['body']) && empty($data['image_url'])) {
            return response()->json(['message' => 'Сообщение не может быть пустым'], 422);
        }

        $existing = ChatMessage::where('thread_id', $thread->id)
            ->where('client_message_id', $data['client_message_id'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $existing->load('replyTo')], 200);
        }

        // Реплай — только на сообщение из ЭТОГО ЖЕ треда, иначе можно было бы
        // сослаться на чужую переписку по угаданному/подсмотренному UUID.
        $replyToId = null;
        if (!empty($data['reply_to_message_id'])) {
            $replyToId = ChatMessage::where('thread_id', $thread->id)
                ->where('id', $data['reply_to_message_id'])
                ->value('id');
        }

        $message = ChatMessage::create([
            'thread_id'            => $thread->id,
            'sender_type'          => 'shop',
            'sender_staff_id'      => $this->currentStaffId($request),
            'body'                 => $data['body'] ?? null,
            'image_url'            => $data['image_url'] ?? null,
            'client_message_id'    => $data['client_message_id'],
            'reply_to_message_id'  => $replyToId,
        ]);
        $message->load('replyTo');

        $preview = $data['body'] ?? '📷 Фото';
        $thread->update([
            'last_message_at'      => $message->created_at,
            'last_message_preview' => mb_substr($preview, 0, 80),
        ]);
        $thread->increment('unread_by_customer');

        return response()->json(['data' => $message], 201);
    }

    /**
     * PATCH /api/admin/chat/threads/{id}/messages/{message}
     *
     * Только текст, только СВОЁ сообщение — сравнение по sender_staff_id
     * (null у владельца тоже сравнивается как обычное значение, так что
     * владелец правит только свои же сообщения, не чужие — как в Telegram:
     * даже админ группы не может отредактировать чужой текст, только
     * удалить, см. PLAN-CHAT.md §11.5). Фото не редактируется — подпись это
     * и есть `body`, значит правка подписи уже работает через этот же метод.
     */
    public function editMessage(Request $request, string $id, string $message): JsonResponse
    {
        $thread = ChatThread::findOrFail($id);
        $chatMessage = ChatMessage::where('thread_id', $thread->id)->findOrFail($message);

        if ($chatMessage->sender_type !== 'shop' || $chatMessage->sender_staff_id !== $this->currentStaffId($request)) {
            return response()->json(['message' => 'Можно редактировать только свои сообщения'], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $chatMessage->update([
            'body'       => $data['body'],
            'edited_at'  => now(),
        ]);

        if ($thread->last_message_at?->equalTo($chatMessage->created_at)) {
            $thread->update(['last_message_preview' => mb_substr($data['body'], 0, 80)]);
        }

        return response()->json(['data' => $chatMessage->load('replyTo')]);
    }

    /**
     * DELETE /api/admin/chat/threads/{id}/messages/{message}
     *
     * Модерация — жёсткое удаление, не пометка «сообщение удалено» (это не
     * «я передумал» у отправителя, а «магазин убрал неприемлемый контент»,
     * см. PLAN-CHAT.md §5.2). Обязательно чистит файл на диске, если было
     * фото — тот же класс бага, что уже дважды ловили на StorageCleanup:
     * БД забыла про файл, а он остался висеть мёртвым грузом навсегда.
     */
    public function deleteMessage(string $id, string $message): JsonResponse
    {
        $thread = ChatThread::findOrFail($id);
        $chatMessage = ChatMessage::where('thread_id', $thread->id)->findOrFail($message);

        StorageService::deleteByUrl($chatMessage->image_url);
        $chatMessage->delete();

        // Пересчитываем превью последнего сообщения в списке диалогов —
        // могли удалить как раз то, что там сейчас показано.
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
     * POST /api/admin/chat/threads/{id}/read
     */
    public function markRead(string $id): JsonResponse
    {
        $thread = ChatThread::findOrFail($id);

        DB::table('chat_messages')
            ->where('thread_id', $thread->id)
            ->where('sender_type', 'customer')
            ->where('status', 'sent')
            ->update(['status' => 'read']);

        $thread->update([
            'unread_by_shop'    => 0,
            'shop_last_read_at' => now(),
        ]);

        return response()->json(['message' => 'Прочитано']);
    }

    /**
     * POST /api/admin/chat/threads/{id}/block
     *
     * Явный булев параметр, не тумблер без состояния — двойной клик/повтор
     * запроса не должен случайно переключить обратно (§6).
     */
    public function block(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['blocked' => 'required|boolean']);

        $thread = ChatThread::findOrFail($id);
        $thread->update(['is_blocked_by_shop' => $data['blocked']]);

        return response()->json(['data' => $thread->fresh()]);
    }

    /**
     * GET /api/admin/chat/poll
     *
     * Один запрос на всю админку, не по треду (PLAN-CHAT.md §4) — карта
     * непрочитанных по диалогам + сумма для бейджа в меню/на вкладке
     * браузера (§7, П5).
     */
    public function poll(): JsonResponse
    {
        $unread = ChatThread::where('unread_by_shop', '>', 0)
            ->pluck('unread_by_shop', 'id');

        return response()->json([
            'unread_by_thread' => $unread,
            'total_unread'     => $unread->sum(),
        ]);
    }

    /**
     * POST /api/admin/chat/image
     *
     * Та же гарантия, что и на покупательской стороне — сервер сам сжимает
     * до ≤100 КБ, никакой проверки размера файла (PLAN-CHAT.md §5, П20.1).
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

    /**
     * У владельца записи ShopStaff нет вообще — он не сотрудник самого
     * себя, только у admin/master она есть. `null` для owner — ожидаемо.
     */
    private function currentStaffId(Request $request): ?string
    {
        $shop = $request->attributes->get('shop');

        return ShopStaff::where('shop_id', $shop->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('accepted_at')
            ->value('id');
    }

    private function resolveCursor(ChatThread $thread, string $messageId): ?ChatMessage
    {
        return ChatMessage::where('thread_id', $thread->id)
            ->where('id', $messageId)
            ->first();
    }
}
