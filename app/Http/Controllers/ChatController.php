<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageBroadcast;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Services\ImageCompressionService;
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
        $this->touchSeen($thread);

        if (!$thread) {
            return response()->json(['data' => [], 'thread' => null]);
        }

        // hidden_from_customer_at — покупатель убрал у себя 2-й ступенью
        // удаления (см. destroy() ниже); строка остаётся в базе навсегда —
        // персонал продолжает её видеть, см. Admin\ChatController::messages()
        // (без этого фильтра, 2026-10-18).
        $query = ChatMessage::with('replyTo')
            ->where('thread_id', $thread->id)
            ->whereNull('hidden_from_customer_at');

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
            'data'   => $messages->map->toCustomerArray(),
            'thread' => [
                'id'                 => $thread->id,
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
            'reply_quote'          => 'nullable|string|max:500',
        ]);

        if (empty($data['body']) && empty($data['image_url'])) {
            return response()->json(['message' => 'Сообщение не может быть пустым'], 422);
        }

        // Цитата-отрывок (выделение части текста, см. PLAN.md 2026-10-18) без
        // сообщения, к которому она относится, — бессмысленна. Только
        // мобильное приложение сейчас умеет её создавать (эта точка входа,
        // не Admin\ChatController — у персонала нет UI для выделения).
        if (!empty($data['reply_quote']) && empty($data['reply_to_message_id'])) {
            return response()->json(['message' => 'Цитата должна ссылаться на сообщение'], 422);
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
            return response()->json(['data' => $existing->load('replyTo')->toCustomerArray()], 200);
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
            // Только если reply_to реально нашёлся в этом треде — иначе
            // цитата без своего сообщения-родителя (см. проверку выше).
            'reply_quote'          => $replyToId ? ($data['reply_quote'] ?? null) : null,
        ]);
        $message->load('replyTo');

        $preview = $data['body'] ?? '📷 Фото';
        DB::table('chat_threads')->where('id', $threadId)->update([
            'last_message_at'      => $message->created_at,
            'last_message_preview' => mb_substr($preview, 0, 80),
            'updated_at'           => now(),
        ]);
        DB::table('chat_threads')->where('id', $threadId)->increment('unread_by_shop');

        ChatMessageBroadcast::dispatch(
            $request->get('_shop')->api_key,
            $threadId,
            'message.new',
            ['message' => $message->toCustomerArray()],
        );

        // Бейдж «Чат» в сайдбаре обновляется сам, даже если у сотрудника не
        // открыт именно этот тред (там своя подписка, см. ChatView.vue).
        \App\Events\ChatUpdated::dispatch($request->get('_shop')->id);

        return response()->json(['data' => $message->toCustomerArray()], 201);
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
        $this->touchSeen($thread);

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

        ChatMessageBroadcast::dispatch(
            $request->get('_shop')->api_key,
            $thread->id,
            'thread.read',
            ['reader' => 'customer'],
        );

        return response()->json(['message' => 'Прочитано']);
    }

    /**
     * POST /api/widget/chat/presence
     *
     * Эфемерный пинг "печатаю"/"я тут" — не пишется в БД, просто
     * пролетает в уже существующий приватный канал треда тем же
     * ChatMessageBroadcast, что и обычные события (тип 'presence').
     * Сознательно НЕ presence-канал Reverb — тот потребовал бы переделать
     * оба пути авторизации (Sanctum у админки и ручную подпись здесь),
     * см. PLAN-CHAT.md. "Онлайн" на клиенте — просто "недавно был этот
     * пинг", не настоящий список подключений.
     */
    public function presence(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $thread   = $customer->chatThread;
        $this->touchSeen($thread);

        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 404);
        }

        $data = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        ChatMessageBroadcast::dispatch(
            $request->get('_shop')->api_key,
            $thread->id,
            'presence',
            ['sender_type' => 'customer', 'is_typing' => $data['is_typing']],
        );

        return response()->json(['message' => 'ok']);
    }

    /**
     * DELETE /api/widget/chat/messages/{message}
     *
     * Покупатель может удалить только СВОЁ сообщение, и только если владелец
     * магазина явно включил это в настройках (`shops.chat_customer_delete_enabled`,
     * выключено по умолчанию — см. PLAN-CHAT.md §7.5). Сообщения магазина
     * покупателю недоступны для удаления ни при каких настройках — это
     * модерация только со стороны магазина (Admin\ChatController::deleteMessage,
     * которая всегда тихая, без следа).
     *
     * Две ступени (спека 2026-09-16, пересмотрена 2026-10-18): первое
     * удаление оставляет «надгробие» — покупатель видит плашку «Сообщение
     * удалено» на месте текста. Повторное удаление — уже самой плашки —
     * убирает её из ЕГО СОБСТВЕННОЙ ленты насовсем (`hidden_from_customer_at`).
     * На ЭТОМ ни разу не трогаются ни body/image_url в базе, ни файл фото —
     * покупатель не может по-настоящему уничтожить своё сообщение: у
     * персонала (Admin\ChatController, не вызывает toCustomerArray()) оно
     * остаётся видно всегда, с тем же текстом/фото, просто с пометкой
     * «Удалено» — намеренно, модерация (см. PLAN.md). Отличие от
     * Admin\ChatController::deleteMessage: там плашки не бывает никогда,
     * сразу настоящее жёсткое удаление строки и файла.
     */
    public function destroy(Request $request, string $message): JsonResponse
    {
        $shop = $request->get('_shop');
        if (!$shop || !$shop->chat_customer_delete_enabled) {
            return response()->json(['message' => 'Удаление сообщений недоступно'], 403);
        }

        $customer = $this->customer($request);
        $thread   = $customer->chatThread;
        $this->touchSeen($thread);
        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 404);
        }

        $chatMessage = ChatMessage::where('thread_id', $thread->id)
            ->where('sender_type', 'customer')
            ->findOrFail($message);

        if ($chatMessage->deleted_at) {
            // Строка и файл остаются — скрываем только от покупателя
            // (см. index() выше, фильтр whereNull('hidden_from_customer_at')).
            // Персонал как видел это сообщение, так и продолжает его видеть.
            $chatMessage->update(['hidden_from_customer_at' => now()]);
            $thread->refreshLastMessagePreview();

            ChatMessageBroadcast::dispatch($shop->api_key, $thread->id, 'message.deleted', ['id' => $chatMessage->id]);

            return response()->json(['message' => 'Сообщение удалено']);
        }

        // Покупатель мог удалить своё же ещё непрочитанное магазином
        // сообщение — тогда unread_by_shop не должен продолжать его считать.
        $wasUnreadByShop = !$thread->shop_last_read_at || $chatMessage->created_at->gt($thread->shop_last_read_at);

        // body/image_url и сам файл НЕ трогаем — персонал должен видеть
        // исходное содержимое навсегда (см. doc-блок метода и toCustomerArray()
        // на модели). Раньше здесь был StorageService::deleteByUrl(...) и
        // зануление body/image_url — убрано 2026-10-18.
        $chatMessage->update(['deleted_at' => now()]);

        if ($wasUnreadByShop && $thread->unread_by_shop > 0) {
            $thread->decrement('unread_by_shop');
        }

        $thread->refreshLastMessagePreview();

        // 'message.updated', не 'message.deleted' — строка никуда не делась,
        // только её содержимое, ровно как при редактировании (см.
        // Admin\ChatController::editMessage). Собеседник (магазин) уже
        // умеет мёрджить такое обновление в открытом чате, ничего нового
        // на приёмной стороне писать не нужно.
        ChatMessageBroadcast::dispatch($shop->api_key, $thread->id, 'message.updated', ['message' => $chatMessage->toCustomerArray()]);

        return response()->json(['data' => $chatMessage->toCustomerArray()]);
    }

    /**
     * Байер редактирует СВОЁ сообщение — зеркало Admin\ChatController::editMessage,
     * но проверка владения как у destroy() выше (sender_type='customer' +
     * принадлежность треду этого покупателя). Без доп-флага в настройках
     * магазина (в отличие от удаления) — self-edit у персонала тоже без
     * такого ограничения, симметрично (2026-10-18, см. PLAN.md).
     *
     * PATCH /api/widget/chat/messages/{message}
     */
    public function update(Request $request, string $message): JsonResponse
    {
        $shop = $request->get('_shop');
        $customer = $this->customer($request);
        $thread   = $customer->chatThread;

        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 404);
        }

        $chatMessage = ChatMessage::where('thread_id', $thread->id)
            ->where('sender_type', 'customer')
            ->findOrFail($message);

        if ($chatMessage->deleted_at) {
            return response()->json(['message' => 'Нельзя редактировать удалённое сообщение'], 422);
        }

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $chatMessage->update([
            'body'      => $data['body'],
            'edited_at' => now(),
        ]);

        if ($thread->last_message_at?->equalTo($chatMessage->created_at)) {
            $thread->update(['last_message_preview' => mb_substr($data['body'], 0, 80)]);
        }

        $chatMessage->load('replyTo');
        ChatMessageBroadcast::dispatch($shop->api_key, $thread->id, 'message.updated', ['message' => $chatMessage->toCustomerArray()]);

        return response()->json(['data' => $chatMessage->toCustomerArray()]);
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
        $this->touchSeen($thread);

        if (!$thread) {
            return response()->json(['has_new' => false, 'unread_total' => 0, 'shop_read_up_to' => null, 'thread_id' => null]);
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
            'thread_id'       => $thread->id,
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

        $compressed = ImageCompressionService::compressToWebp($request->file('image'));

        $filename = Str::uuid() . '.webp';
        Storage::disk('public')->put('uploads/' . $filename, $compressed);
        $url = Storage::disk('public')->url('uploads/' . $filename);

        return response()->json(['url' => $url]);
    }

    /**
     * POST /api/widget/chat/broadcasting-auth
     *
     * Покупатель не Sanctum-пользователь (авторизован `X-Phone-Session`,
     * см. VerifyPhoneSession), поэтому стандартный `/api/broadcasting/auth`
     * (auth:sanctum, см. bootstrap/app.php) ему не подходит — свой
     * эндпоинт с той же ручной подписью через Pusher-совместимый SDK,
     * которым говорит Reverb (см. PLAN-CHAT.md §12). Пускаем только на
     * канал СВОЕГО же треда — угадать чужой thread_id недостаточно, имя
     * канала целиком сверяется со своим.
     */
    public function broadcastAuth(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $shop     = $request->get('_shop');

        $data = $request->validate([
            'channel_name' => 'required|string',
            'socket_id'    => 'required|string',
        ]);

        $thread = $customer->chatThread;
        if (!$thread) {
            return response()->json(['message' => 'Диалог не найден'], 403);
        }

        $expectedChannel = "private-chat.thread.{$shop->api_key}.{$thread->id}";
        if ($data['channel_name'] !== $expectedChannel) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $pusher = new \Pusher\Pusher(
            config('reverb.apps.apps.0.key'),
            config('reverb.apps.apps.0.secret'),
            config('reverb.apps.apps.0.app_id'),
        );

        $auth = json_decode($pusher->socket_auth($data['channel_name'], $data['socket_id']), true);

        return response()->json($auth);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    /**
     * Отметить, что покупатель сейчас в чате — для индикатора «в сети» в
     * админке (chat_threads.customer_last_seen_at). Пишем не чаще раза в 20с,
     * чтобы poll (каждые несколько секунд) не долбил UPDATE.
     */
    private function touchSeen(?ChatThread $thread): void
    {
        if (!$thread) {
            return;
        }
        if ($thread->customer_last_seen_at
            && $thread->customer_last_seen_at->diffInSeconds(now()) < 20) {
            return;
        }
        $thread->forceFill(['customer_last_seen_at' => now()])->saveQuietly();
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
