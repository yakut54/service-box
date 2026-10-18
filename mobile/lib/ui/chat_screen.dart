import 'dart:async';
import 'dart:io';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show SelectedContent;
import 'package:flutter/services.dart';
import 'package:gal/gal.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../core/image_compress.dart';
import '../core/uuid.dart';
import '../data/chat_repository.dart';
import '../models/chat_message.dart';
import '../services/chat_notifications.dart';
import '../services/notification_permission.dart';
import '../state/auth_state.dart';
import '../state/chat_state.dart';
import '../state/shop_state.dart';
import 'widgets/photo_picker_sheet.dart';
import 'widgets/app_dialog.dart';
import 'widgets/chat_background.dart';
import 'widgets/error_view.dart';
import 'widgets/skeleton.dart';

/// Диалог байера с магазином — один тред, лента снизу вверх, отправка
/// текста и фото. Доставка — WebSocket (Reverb) как основной путь, обычный
/// опрос остаётся редкой подстраховкой на случай обрыва сокета (см.
/// PLAN-CHAT.md §12).
///
/// [chatScreenOpen] — экран чата смонтирован и на переднем плане. Читает
/// PushRouter: если пришёл foreground-push о новом сообщении, а чат и так
/// открыт — системную плашку/баннер не показываем (WS уже доставил).
bool chatScreenOpen = false;

// Отступ ленты от края экрана — общий для самой ленты (_ChatScreenState),
// полосы подсветки выбора и спиннера подгрузки старых (_MessageBubbleState) —
// один литерал на весь экран чата вместо трёх одинаковых чисел порознь.
const _kChatHorizontalPadding = 12.0;

class ChatScreen extends StatefulWidget {
  /// Путь к файлу, с которым открыли приложение через системное
  /// «Поделиться» (Android, см. main.dart + MainActivity.kt) — фото сразу
  /// подгружается и встаёт в композер, как будто выбрано из галереи.
  final String? sharedImagePath;

  /// Черновик, с которым открывается композер — например «Вопрос по товару
  /// «...»: » с кнопки «Спросить» на странице товара (см. openChat() в
  /// widgets/chat_button.dart). У чата нет привязки сообщения к товару на
  /// уровне БД — так владелец видит контекст прямо в тексте, без миграции.
  final String? initialDraft;

  // Только для тестов (см. test/helpers/chat_test_harness.dart) — боевой код
  // всегда оставляет null и получает репозиторий через ChatRepository.create().
  final ChatRepository? repository;

  const ChatScreen({
    super.key,
    this.sharedImagePath,
    this.initialDraft,
    this.repository,
  });

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> with WidgetsBindingObserver {
  late final _repository = widget.repository ?? ChatRepository.create();
  // Соединением владеет ChatState (живёт весь сеанс, не только этот экран)
  // — здесь только добавляем/снимаем свой листенер на уже открытый сокет,
  // см. ChatState.realtime.
  late final ChatState _chatState;
  final _scrollController = ScrollController();
  late final _draftController = TextEditingController(text: widget.initialDraft);
  final _player = AudioPlayer();

  List<ChatMessage> _messages = [];
  bool _loading = true;
  bool _loadingOlder = false;
  bool _hasMoreOlder = true;
  bool _isBlockedByShop = false;
  final Map<String, GlobalKey<_MessageBubbleState>> _messageKeys = {};
  // Нужен только чтобы узнать верхнюю границу композера — см.
  // _scrollSelectedBubbleIntoView.
  final _composerKey = GlobalKey();
  String? _jumpingToMessageId;
  String? _highlightedMessageId;
  Timer? _highlightTimer;
  AppException? _error;
  Timer? _pollTimer;
  bool _sending = false;
  List<Uint8ListHolder> _pendingImages = [];
  // (сколько уже загружено, всего в этом батче) — null, если сейчас ничего
  // не грузится. Используется и для гейта кнопок, и для текста "N из M".
  (int, int)? _uploadProgress;
  ChatMessage? _replyTarget;
  // Отрывок текста, выделенный пользователем в _replyTarget (не весь текст)
  // — заполняется через режим выделения текста (2-е долгое нажатие на уже
  // выбранный бабл, см. PLAN.md 2026-10-18). null — обычный полный ответ.
  String? _quoteExcerpt;
  // Режим множественного выбора, как в Телеграме: 1-е долгое нажатие на
  // ЛЮБОЙ бабл добавляет его сюда и показывает AppBar выбора (крестик /
  // «Выбрано N» / иконки действий); пока набор не пуст — обычный тап по
  // любому сообщению тоже тоггл-ит выбор. 2-е долгое нажатие на уже
  // ЕДИНСТВЕННОМ выбранном — не трогает это множество, переводит в
  // посимвольное выделение (_selectingMessageId ниже).
  final Set<String> _selectedIds = {};
  // id бабла в режиме выделения текста (SelectableText + ручки захвата) —
  // включается ТОЛЬКО вторым долгим нажатием на единственный выбранный.
  String? _selectingMessageId;
  // Редактируемое сообщение — композер переиспользуется (как в админке,
  // не отдельный диалог), см. _startEdit/_cancelEdit/_send.
  ChatMessage? _editingMessage;
  String? _threadId;

  // ── "Печатает…" / "в сети" (магазин) — эфемерный пинг в тот же WS-канал,
  // не настоящий presence-канал (см. ChatController::presence на бэкенде и
  // PLAN-CHAT.md). "В сети" тут условность — "недавно прислал такой пинг".
  static const _presenceOnlineWindow = Duration(seconds: 40);
  bool _shopIsTyping = false;
  DateTime? _shopLastActiveAt;
  Timer? _shopTypingClearTimer;
  Timer? _sendTypingClearTimer;
  bool _wasTyping = false;

  bool get _shopOnline =>
      _shopLastActiveAt != null &&
      DateTime.now().difference(_shopLastActiveAt!) < _presenceOnlineWindow;

  String get _sessionToken => context.read<AuthState>().session!.sessionToken;

  @override
  void initState() {
    super.initState();
    chatScreenOpen = true;
    _chatState = context.read<ChatState>();
    _chatState.realtime.addListener(_onRealtimeEvent);
    WidgetsBinding.instance.addObserver(this);
    _scrollController.addListener(_onScroll);
    _draftController.addListener(_onDraftChanged);
    _load();
    final sharedPath = widget.sharedImagePath;
    if (sharedPath != null) {
      File(sharedPath).readAsBytes().then((bytes) {
        if (mounted) _uploadPickedBytes([bytes]);
      });
    }
    // Первый вход в чат — момент очевидной ценности уведомлений («сообщения от
    // магазина»). No-op, если уже спрашивали дважды или разрешение дано.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        NotificationPermission.maybeShowPrimer(context, trigger: 'chat');
      }
    });
  }

  @override
  void dispose() {
    chatScreenOpen = false;
    WidgetsBinding.instance.removeObserver(this);
    _chatState.realtime.removeListener(_onRealtimeEvent);
    _pollTimer?.cancel();
    _shopTypingClearTimer?.cancel();
    _sendTypingClearTimer?.cancel();
    _scrollController.dispose();
    _draftController.removeListener(_onDraftChanged);
    _draftController.dispose();
    _player.dispose();
    super.dispose();
  }

  // ── Своё "печатает" — дебаунс, не на каждый символ (см. ChatView.vue,
  // тот же принцип: is_typing:true сразу, false через 3с молчания).
  void _onDraftChanged() {
    if (_threadId == null) return;
    if (_draftController.text.trim().isNotEmpty && !_wasTyping) {
      _wasTyping = true;
      _repository.sendPresence(_sessionToken, isTyping: true).catchError((_) {});
    }
    _sendTypingClearTimer?.cancel();
    _sendTypingClearTimer = Timer(const Duration(seconds: 3), () {
      _wasTyping = false;
      _repository.sendPresence(_sessionToken, isTyping: false).catchError((_) {});
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Само соединение (ChatRealtimeClient) поднимает/гасит ChatState —
    // здесь только свой локальный 5с-опрос-подстраховка для открытого
    // экрана. Когда ChatState переподключит общий сокет на resume, наш
    // листенер уже зарегистрирован и снова начнёт получать события — делать
    // это самим не нужно.
    if (state == AppLifecycleState.resumed) {
      _startPolling();
    } else {
      _pollTimer?.cancel();
    }
  }

  void _startPolling() {
    _pollTimer?.cancel();
    // Основной путь доставки — WebSocket (ChatState держит соединение,
    // ChatRealtimeClient сам переподключается). Poll — подстраховка на
    // моменты, когда сокет переустанавливается: 5с, чтобы «пришло из
    // админки, а в чате тишина» не длилось дольше пары секунд, если WS
    // моргнул.
    _pollTimer = Timer.periodic(const Duration(seconds: 5), (_) => _poll());
  }

  // Тред только что стал известен (первая загрузка ленты или только что
  // отправленное первое сообщение) — сообщаем ChatState (он откроет общий
  // сокет, если ещё не открыт) и пингуем presence «байер тут».
  void _onThreadKnown(String threadId) {
    _chatState.onThreadCreated(threadId);
    _repository.sendPresence(_sessionToken, isTyping: false).catchError((_) {});
  }

  void _onRealtimeEvent(String event, Map<String, dynamic> data) {
    if (event == 'presence') {
      _handlePresenceEvent(data);
      return; // эфемерный пинг — не повод перезапрашивать сообщения
    }
    if (event == 'message.new') {
      final raw = data['message'] as Map<String, dynamic>?;
      if (raw != null && raw['sender_type'] == 'shop') {
        _playNotifySound();
      }
    }
    _poll();
  }

  void _handlePresenceEvent(Map<String, dynamic> data) {
    if (data['sender_type'] != 'shop') return;
    if (!mounted) return;
    setState(() {
      _shopLastActiveAt = DateTime.now();
      _shopIsTyping = data['is_typing'] == true;
    });
    _shopTypingClearTimer?.cancel();
    if (_shopIsTyping) {
      // Страховка — если магазин не пришлёт is_typing:false (закрыли вкладку
      // посреди набора), индикатор не должен висеть вечно.
      _shopTypingClearTimer = Timer(const Duration(seconds: 6), () {
        if (mounted) setState(() => _shopIsTyping = false);
      });
    }
  }

  Future<void> _playNotifySound() async {
    try {
      await _player.play(AssetSource('sounds/chat_notify.wav'));
    } catch (_) {
      // отсутствие звука не должно ронять чат ни при каких обстоятельствах
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await _repository.fetchMessages(_sessionToken);
      setState(() {
        _messages = page.messages.reversed.toList();
        _isBlockedByShop = page.isBlockedByShop;
        _hasMoreOlder = page.messages.length >= 30;
        _threadId = page.threadId;
      });
      // Диалога может ещё не существовать (байер ничего не писал) — тогда
      // markRead отвечает 404 «Диалог не найден», это ожидаемо и не должно
      // ронять весь экран ошибкой.
      try {
        await _repository.markRead(_sessionToken);
        if (mounted) context.read<ChatState>().clearUnread();
      } catch (_) {
        // нет диалога — нечего отмечать прочитанным, это нормально
      }
      _startPolling();
      if (_threadId != null) {
        _onThreadKnown(_threadId!);
        // Байер открыл чат — плашки этого треда в шторке больше не нужны.
        ChatNotifications.dismissThread(_threadId!);
      }
      _scrollToBottom();
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _poll() async {
    try {
      final page = await _repository.fetchMessages(_sessionToken);
      final fresh = page.messages.reversed.toList();
      final known = _messages.map((m) => m.id).toSet();
      final newOnes = fresh.where((m) => !known.contains(m.id)).toList();
      final byId = {for (final m in fresh) m.id: m};

      // fresh — это только последние ~30 сообщений. Если сообщение из этого
      // же окна времени пропало (модератор удалил), убираем его и у нас —
      // иначе оно зависает в памяти навсегда, раз poll умеет только
      // добавлять. Более старые сообщения (подгруженные через "загрузить
      // раньше") этим окном не покрываются — их не трогаем.
      final windowStart = fresh.isNotEmpty ? fresh.first.createdAt : null;
      final freshIds = fresh.map((m) => m.id).toSet();

      final wasAtBottom = _isAtBottom;
      setState(() {
        _isBlockedByShop = page.isBlockedByShop;
        _messages = _messages
            .where(
              (m) =>
                  windowStart == null ||
                  m.createdAt.isBefore(windowStart) ||
                  freshIds.contains(m.id),
            )
            .map((m) => byId[m.id] ?? m)
            .toList();
        if (newOnes.isNotEmpty) _messages.addAll(newOnes);
      });

      if (newOnes.any((m) => m.senderType == 'shop')) {
        await _repository.markRead(_sessionToken);
        if (mounted) context.read<ChatState>().clearUnread();
      }
      if (newOnes.isNotEmpty && (wasAtBottom || newOnes.any((m) => m.isMine))) {
        _scrollToBottom(animated: true);
      }
    } catch (_) {
      // фоновый опрос — молча пропускаем
    }
  }

  // Лента строится с reverse:true, поэтому смещение 0 — это низ ленты
  // (последнее сообщение). Настоящий фиксированный якорь, а не оценка
  // maxScrollExtent ленивого ListView, из-за которой чат раньше открывался
  // «где-то в середине», и три страховочных таймера ничего не спасали.
  bool get _isAtBottom {
    if (!_scrollController.hasClients) return true;
    return _scrollController.position.pixels <= 80;
  }

  void _scrollToBottom({bool animated = false}) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_scrollController.hasClients) return;
      if (animated) {
        _scrollController.animateTo(
          0,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      } else {
        _scrollController.jumpTo(0);
      }
    });
  }

  void _onScroll() {
    if (!_scrollController.hasClients || _loadingOlder || !_hasMoreOlder) {
      return;
    }
    final pos = _scrollController.position;
    // Верх reverse-ленты — это maxScrollExtent. Пока сообщений меньше экрана,
    // maxScrollExtent == 0 — это ещё не «долистали до истории».
    if (pos.maxScrollExtent <= 0) return;
    if (pos.pixels >= pos.maxScrollExtent - 300) {
      _loadOlder();
    }
  }

  Future<void> _loadOlder() async {
    // В reverse-ленте более старые сообщения встают в конец списка (визуально
    // — вверх). Смещение отсчитывается от низа и от этого не меняется, поэтому
    // байер остаётся ровно на том же месте — anchor-jump больше не нужен.
    await _fetchOlderBatch();
  }

  /// Подгружает одну страницу более старых сообщений и добавляет их в
  /// начало списка. Общий кусок для обычной подгрузки при скролле вверх
  /// (_loadOlder) и для прыжка к сообщению, на которое ответили и которого
  /// ещё нет в памяти (_jumpToMessage) — им обеим нужна ровно эта логика,
  /// разное только то, что происходит со скроллом после.
  Future<bool> _fetchOlderBatch() async {
    if (_messages.isEmpty || _loadingOlder) return false;
    setState(() => _loadingOlder = true);
    try {
      final oldest = _messages.first;
      final page = await _repository.fetchMessages(
        _sessionToken,
        before: oldest.id,
      );
      if (page.messages.length < 30) _hasMoreOlder = false;
      final older = page.messages.reversed.toList();
      if (older.isEmpty) return false;
      setState(() => _messages = [...older, ..._messages]);
      return true;
    } catch (_) {
      // тихо — попробует ещё раз при следующем скролле к верху
      return false;
    } finally {
      if (mounted) setState(() => _loadingOlder = false);
    }
  }

  /// Тап по цитате «Ответ на сообщение» внутри пузыря — прокручивает к
  /// оригиналу, как в Телеграме. Сообщение может быть глубже, чем уже
  /// подгруженное окно (~30 последних) — тогда молча подгружаем историю
  /// назад, пока не найдём его или не упрёмся в начало треда.
  Future<void> _jumpToMessage(String targetId) async {
    if (_jumpingToMessageId != null) return; // уже идёт один прыжок
    setState(() => _jumpingToMessageId = targetId);
    try {
      while (!_messages.any((m) => m.id == targetId) && _hasMoreOlder) {
        final fetched = await _fetchOlderBatch();
        if (!fetched) break;
      }
      if (!mounted) return;

      final index = _messages.indexWhere((m) => m.id == targetId);
      if (index == -1) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Сообщение не найдено — возможно, оно было удалено'),
          ),
        );
        return;
      }

      var targetContext = _messageKeys[targetId]?.currentContext;
      if (targetContext == null && _scrollController.hasClients) {
        // Сообщение ЕСТЬ в _messages, но ListView.builder ещё не построил
        // для него виджет (оно вне текущего окна отрисовки) — GlobalKey
        // появится только после того, как элемент реально попадёт в кадр.
        // Грубо прыгаем в его окрестность по индексу в списке, чтобы
        // попасть в зону построения, и на следующем кадре пробуем снова.
        // Лента reverse: смещение 0 — последнее сообщение, поэтому индекс
        // считаем от конца списка.
        final reverseIndex = _messages.length - 1 - index;
        final approx =
            (reverseIndex / _messages.length) *
            _scrollController.position.maxScrollExtent;
        _scrollController.jumpTo(
          approx.clamp(0, _scrollController.position.maxScrollExtent),
        );
        await Future.delayed(const Duration(milliseconds: 80));
        if (!mounted) return;
        targetContext = _messageKeys[targetId]?.currentContext;
      }
      if (targetContext == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Не удалось прокрутить к сообщению')),
        );
        return;
      }

      await Scrollable.ensureVisible(
        targetContext,
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeOut,
        alignment: 0.5,
      );

      if (!mounted) return;
      _highlightTimer?.cancel();
      setState(() => _highlightedMessageId = targetId);
      _highlightTimer = Timer(const Duration(milliseconds: 1200), () {
        if (mounted) setState(() => _highlightedMessageId = null);
      });
    } finally {
      if (mounted) setState(() => _jumpingToMessageId = null);
    }
  }

  Future<void> _pickImage() async {
    // До 2026-08-25 здесь был системный ImagePicker.gallery — окно самого
    // телефона, а не приложения (на Android это Google Photo Picker, на iOS
    // PHPickerViewController). Тогда решили, что этого достаточно и похоже
    // на Telegram. По факту не похоже — не наш стиль, и главное, нельзя
    // выбрать больше одной картинки. Своя шторка (widgets/photo_picker_sheet.dart,
    // переиспользуется и в аватаре профиля) — сетка + плитка камеры + мультиселект.
    final picked = await pickPhotos(context);
    if (picked == null || !mounted) return;

    await _uploadPickedBytes(picked);
  }

  /// Общий путь для картинок из своего пикера (может быть несколько сразу)
  /// и картинки, с которой открыли приложение через системное «Поделиться»
  /// (см. widget.sharedImagePath — там всегда список из одного элемента).
  /// Грузит по очереди, а не параллельно — сервер и так ограничивает
  /// загрузку картинок в чат 10 в минуту (throttle chat-image), незачем
  /// торопиться и бить лимит быстрее.
  Future<void> _uploadPickedBytes(List<Uint8List> byteList) async {
    if (!mounted || byteList.isEmpty) return;
    final total = byteList.length;
    for (var i = 0; i < byteList.length; i++) {
      if (!mounted) return;
      setState(() => _uploadProgress = (i, total));
      try {
        // Сжимаем и на клиенте — экономит трафик байера на отправке, но это
        // не гарантия: сервер сам ЖЁСТКО сжимает до ≤100 КБ вне зависимости
        // от того, что пришло (см. ImageCompressionService, П20.1).
        final compressed = await compressImageBytes(
          byteList[i],
          maxDimension: 1024,
          targetBytes: 100 * 1024,
        );
        final url = await _repository.uploadImage(
          _sessionToken,
          compressed,
          'chat.jpg',
        );
        if (!mounted) return;
        setState(() => _pendingImages = [..._pendingImages, Uint8ListHolder(url)]);
      } catch (e) {
        if (mounted) {
          _showError(
            e,
            total > 1
                ? 'Загружено ${_pendingImages.length} из $total — остальные не загрузились'
                : 'Не удалось загрузить фото',
          );
        }
        break;
      }
    }
    if (mounted) setState(() => _uploadProgress = null);
  }

  Future<void> _send() async {
    if (_editingMessage != null) {
      await _saveEdit();
      return;
    }

    final body = _draftController.text.trim();
    final images = _pendingImages;
    if (body.isEmpty && images.isEmpty) return;

    // Не ждать 3с таймер — отправка сама по себе уже сигнал "закончил печатать".
    if (_wasTyping) {
      _wasTyping = false;
      _sendTypingClearTimer?.cancel();
      _repository.sendPresence(_sessionToken, isTyping: false).catchError((_) {});
    }

    setState(() => _sending = true);
    // Несколько картинок сразу уходят N отдельными сообщениями подряд, не
    // единым "альбомом" (в модели/API — одна картинка на сообщение). Текст
    // и ответ-на-сообщение прикрепляются только к первому — иначе непонятно,
    // к какой из N картинок относится подпись.
    final total = images.isEmpty ? 1 : images.length;
    var sent = 0;
    try {
      for (var i = 0; i < total; i++) {
        final isFirst = i == 0;
        final message = await _repository.sendMessage(
          _sessionToken,
          body: isFirst && body.isNotEmpty ? body : null,
          imageUrl: images.isEmpty ? null : images[i].url,
          clientMessageId: generateUuidV4(),
          replyToMessageId: isFirst ? _replyTarget?.id : null,
          replyQuote: isFirst ? _quoteExcerpt : null,
        );
        sent++;
        if (!mounted) return;
        setState(() {
          _messages = [..._messages, message];
          _threadId ??= message.threadId;
          if (isFirst) {
            _draftController.clear();
            _replyTarget = null;
            _quoteExcerpt = null;
            // Отправка — естественное завершение режима выбора (как и
            // начало редактирования в _startEdit выше): если цитировали
            // через множественный выбор, после отправки галочки не должны
            // оставаться висеть.
            _selectedIds.clear();
          }
        });
      }
      if (mounted && images.isNotEmpty) setState(() => _pendingImages = []);
      // Тред мог только что появиться (первое сообщение байера) — ChatState
      // ещё не знал о нём. onThreadCreated идемпотентен, безопасно звать
      // всегда, если id известен.
      if (_threadId != null) {
        _onThreadKnown(_threadId!);
      }
      _scrollToBottom(animated: true);
    } catch (e) {
      if (mounted) {
        _showError(
          e,
          sent > 0
              ? 'Отправлено $sent из $total — остальные не ушли, попробуйте через минуту'
              : 'Не удалось отправить сообщение',
        );
        // Из очереди на отправку убираем только то, что реально ушло —
        // остальное остаётся в композере, можно повторить попытку сразу.
        if (images.isNotEmpty) {
          setState(() => _pendingImages = images.sublist(sent.clamp(0, images.length)));
        }
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  /// Редактирование СВОЕГО сообщения — переиспользует композер (как в
  /// _send() выше, не отдельный диалог). Сервер отклонит чужое/уже
  /// удалённое сообщение (403/422), экран просто покажет ошибку.
  Future<void> _saveEdit() async {
    final target = _editingMessage;
    if (target == null) return;
    final body = _draftController.text.trim();
    if (body.isEmpty) return;

    setState(() => _sending = true);
    try {
      final updated = await _repository.editMessage(_sessionToken, target.id, body);
      if (!mounted) return;
      setState(() {
        _messages = _messages.map((m) => m.id == target.id ? updated : m).toList();
        _editingMessage = null;
        _draftController.clear();
      });
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось изменить сообщение');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _startReply(ChatMessage m) {
    setState(() {
      _replyTarget = m;
      _quoteExcerpt = null;
      _editingMessage = null;
    });
  }

  void _cancelReply() {
    setState(() {
      _replyTarget = null;
      _quoteExcerpt = null;
    });
  }

  /// 1-й "Назад" во время текстового выделения (см. PopScope в build()) — сам
  /// гасим выделение в бабле (через GlobalKey в _messageKeys), а не просто
  /// забываем о нём: иначе капли/меню Flutter остались бы висеть на экране,
  /// хотя _selectingMessageId уже null.
  void _cancelSelecting() {
    final id = _selectingMessageId;
    if (id != null) _messageKeys[id]?.currentState?.clearTextSelection();
    setState(() => _selectingMessageId = null);
  }

  /// Бабл сообщает, что в нём появилось/пропало текстовое выделение (см.
  /// _MessageBubble.onTextSelectionChanged) — экрану это нужно для
  /// ступенчатого "Назад" и чтобы увести бабл от поля ввода, если выделили
  /// самое нижнее сообщение в ленте (см. PLAN.md 2026-10-18).
  void _onBubbleTextSelectionChanged(String messageId, bool hasSelection) {
    final wasAlreadySelecting = _selectingMessageId == messageId;
    setState(() {
      if (hasSelection) {
        _selectingMessageId = messageId;
      } else if (wasAlreadySelecting) {
        _selectingMessageId = null;
      }
    });
    if (hasSelection && !wasAlreadySelecting) _scrollSelectedBubbleIntoView(messageId);
  }

  /// Капли/меню выделения рисует Flutter в Overlay поверх всего экрана, он не
  /// обрезается границами ленты — у самого нижнего пузыря (прижат к полю
  /// ввода) капля иначе вылезала бы на композер. В отличие от _jumpToMessage
  /// (центрирование по цитате) здесь нужно ровно противоположное: не
  /// трогать бабл, если он и так не перекрывается, а если перекрывается —
  /// подвинуть его ровно настолько, чтобы капля освободила поле ввода, не
  /// утаскивая бабл в середину экрана (пользователь явно просил именно так,
  /// см. PLAN.md 2026-10-18).
  void _scrollSelectedBubbleIntoView(String messageId) {
    if (!_scrollController.hasClients) return;
    final bubbleBox = _messageKeys[messageId]?.currentContext?.findRenderObject() as RenderBox?;
    final composerBox = _composerKey.currentContext?.findRenderObject() as RenderBox?;
    if (bubbleBox == null || composerBox == null) return;

    const clearance = 40.0; // зазор, чтобы капля не упиралась в поле ввода
    final bubbleBottom = bubbleBox.localToGlobal(Offset(0, bubbleBox.size.height)).dy;
    final composerTop = composerBox.localToGlobal(Offset.zero).dy;
    final overlap = bubbleBottom + clearance - composerTop;
    if (overlap <= 0) return; // бабл не у самого низа — трогать не нужно

    final position = _scrollController.position;
    final target = (position.pixels + overlap).clamp(0.0, position.maxScrollExtent);
    _scrollController.animateTo(
      target,
      duration: const Duration(milliseconds: 300),
      curve: Curves.easeOut,
    );
  }

  /// Подтверждение выделенного отрывка — превращается в ответ-с-цитатой
  /// (не обычный полный ответ, см. _startReply выше).
  void _confirmQuote(ChatMessage m, String excerpt) {
    final trimmed = excerpt.trim();
    if (trimmed.isEmpty) return;
    setState(() {
      _replyTarget = m;
      _quoteExcerpt = trimmed;
      _selectingMessageId = null;
      _editingMessage = null;
    });
  }

  /// Отрывок скопирован в буфер (кнопка «Копировать» в меню выделения,
  /// см. _MessageBubble._buildSelectionToolbar) — как и цитата, законченное
  /// действие: галочка на бабле снимается, а не остаётся висеть.
  void _onExcerptCopied() {
    if (_selectedIds.isNotEmpty) setState(() => _selectedIds.clear());
  }

  /// `shops.chat_customer_delete_enabled` — вынесено в getter, используется
  /// и для «удалить своё сообщение», и для «стереть уже показанную плашку
  /// „Сообщение удалено“» (тот же тумблер, тот же эндпоинт на бэкенде).
  bool get _canDeleteOwnMessages =>
      context.read<ShopState>().shop?.chatCustomerDeleteEnabled ?? false;

  /// Две ступени на бэкенде (спека 2026-09-16): если сообщение ещё живое,
  /// сервер оставляет «надгробие» и возвращает его — заменяем сообщение в
  /// списке, не убираем. Если это было уже надгробие — сервер стирает его
  /// насовсем и возвращает null — вот тогда убираем из списка.
  Future<void> _deleteMessage(ChatMessage m) async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Удалить сообщение?',
      message: 'Сообщение и приложенное фото (если есть) будут удалены безвозвратно.',
      confirmLabel: 'Удалить',
    );
    if (!confirmed || !mounted) return;

    try {
      final updated = await _repository.deleteMessage(_sessionToken, m.id);
      setState(() {
        _messages = updated != null
            ? _messages.map((x) => x.id == m.id ? updated : x).toList()
            : _messages.where((x) => x.id != m.id).toList();
      });
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось удалить сообщение');
    }
  }

  /// Долгое нажатие на ЕЩЁ не отмеченный бабл — как в Телеграме: входит в
  /// режим множественного выбора (AppBar подменяется на панель выбора). На
  /// уже отмеченном бабле долгое нажатие сюда вообще не доходит (см.
  /// _MessageBubble: onLongPress выключен при isChecked) — его забирает
  /// SelectionArea и сразу выделяет слово под пальцем, на ЛЮБОМ отмеченном
  /// бабле, а не только когда отмечен единственный (см. PLAN.md 2026-10-18).
  void _onBubbleLongPress(ChatMessage m) {
    if (m.isDeleted && !m.isMine) return;
    HapticFeedback.selectionClick();
    setState(() => _selectedIds.add(m.id));
  }

  /// Тап по баблу, пока режим выбора активен — тоггл, не открывает/не
  /// отвечает. Вне режима выбора этот колбэк вообще не подключён.
  void _onBubbleTap(ChatMessage m) {
    setState(() {
      if (!_selectedIds.remove(m.id)) _selectedIds.add(m.id);
    });
  }

  void _exitSelection() {
    // Крестик в AppBar режима выбора — явная команда "выйти целиком";
    // при комбинации (множественный выбор + активное текстовое выделение
    // на одном из баблов, см. _onBubbleLongPress) сбрасывает оба состояния
    // сразу, иначе бабл остался бы залипшим в SelectableText-режиме без
    // AppBar выбора.
    setState(() {
      _selectedIds.clear();
      _selectingMessageId = null;
    });
  }

  Future<void> _copySelection() async {
    final ids = _selectedIds;
    final texts = _messages
        .where((m) => ids.contains(m.id) && m.body != null && m.body!.isNotEmpty)
        .map((m) => m.body!)
        .toList();
    if (texts.isNotEmpty) {
      await Clipboard.setData(ClipboardData(text: texts.join('\n')));
    }
    if (mounted) {
      setState(() => _selectedIds.clear());
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Скопировано')));
    }
  }

  Future<void> _deleteSelection() async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Удалить ${_selectedIds.length} '
          '${pluralRu(_selectedIds.length, 'сообщение', 'сообщения', 'сообщений')}?',
      message: 'Сообщения и приложенные фото (если есть) будут удалены безвозвратно.',
      confirmLabel: 'Удалить',
    );
    if (!confirmed || !mounted) return;

    final targets = _messages.where((m) => _selectedIds.contains(m.id)).toList();
    for (final m in targets) {
      try {
        final updated = await _repository.deleteMessage(_sessionToken, m.id);
        if (!mounted) return;
        setState(() {
          _messages = updated != null
              ? _messages.map((x) => x.id == m.id ? updated : x).toList()
              : _messages.where((x) => x.id != m.id).toList();
        });
      } catch (e) {
        if (mounted) _showError(e, 'Не удалось удалить сообщение');
      }
    }
    if (mounted) setState(() => _selectedIds.clear());
  }

  void _showError(Object e, String fallback) {
    final message = e is AppException ? e.message : fallback;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  /// Композер переиспользуется (как у персонала в админке, не отдельный
  /// диалог), см. _send/_saveEdit. Снимает режим выбора.
  void _startEdit(ChatMessage m) {
    setState(() {
      _editingMessage = m;
      _draftController.text = m.body ?? '';
      _selectedIds.clear();
      _replyTarget = null;
      _quoteExcerpt = null;
    });
  }

  void _cancelEdit() {
    setState(() {
      _editingMessage = null;
      _draftController.clear();
    });
  }

  /// AppBar режима множественного выбора — крестик-отмена / «Выбрано N» /
  /// иконки действий, подменяет обычный AppBar пока _selectedIds не пуст
  /// (см. build() ниже). Карандаш — только когда выбрано ровно одно СВОЁ
  /// неудалённое текстовое сообщение (симметрично новой фиче
  /// редактирования у байера); удалить — только если магазин разрешил
  /// (`chat_customer_delete_enabled`) и все выбранные — свои.
  PreferredSizeWidget _buildSelectionAppBar() {
    final selected = _messages.where((m) => _selectedIds.contains(m.id)).toList();
    final singleEditable = selected.length == 1 &&
        selected.first.isMine &&
        !selected.first.isDeleted &&
        selected.first.body != null &&
        selected.first.body!.isNotEmpty;
    final canDeleteAll = _canDeleteOwnMessages && selected.isNotEmpty && selected.every((m) => m.isMine);

    return AppBar(
      leading: IconButton(
        icon: const Icon(Icons.close_rounded),
        onPressed: _exitSelection,
      ),
      title: Text('Выбрано ${_selectedIds.length}'),
      actions: [
        if (singleEditable)
          IconButton(
            icon: const Icon(Icons.edit_outlined),
            tooltip: 'Изменить',
            onPressed: () => _startEdit(selected.first),
          ),
        IconButton(
          icon: const Icon(Icons.copy_rounded),
          tooltip: 'Скопировать',
          onPressed: _copySelection,
        ),
        if (canDeleteAll)
          IconButton(
            icon: const Icon(Icons.delete_outline_rounded),
            tooltip: 'Удалить',
            onPressed: _deleteSelection,
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return PopScope(
      // Пока активно текстовое выделение ИЛИ множественный выбор — системный
      // "Назад" (аппаратная кнопка/жест Android, и авто-стрелка в AppBar,
      // которую Flutter подставляет сам, раз leading не задан явно) не
      // должен закрывать экран чата, а поэтапно откатывать режимы — иначе
      // случайный свайп-назад посреди копирования цитаты выкидывал бы байера
      // из чата целиком.
      canPop: _selectingMessageId == null && _selectedIds.isEmpty,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        if (_selectingMessageId != null) {
          _cancelSelecting(); // 1-й "Назад" — только текстовое выделение
        } else if (_selectedIds.isNotEmpty) {
          _exitSelection(); // 2-й "Назад" — множественный выбор
        }
        // 3-й "Назад" — canPop уже true, обычный выход с экрана.
      },
      child: Scaffold(
        appBar: _selectedIds.isNotEmpty ? _buildSelectionAppBar() : AppBar(
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('Чат с магазином'),
              if (_shopIsTyping)
                Text(
                  'печатает…',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.primary,
                    fontStyle: FontStyle.italic,
                  ),
                )
              else if (_shopOnline)
                Text(
                  'в сети',
                  style: theme.textTheme.labelSmall?.copyWith(color: Colors.green),
                ),
            ],
          ),
        ),
        body: Stack(
          children: [
            ChatBackground(color: Theme.of(context).colorScheme.primary),
            SafeArea(child: _buildBody()),
          ],
        ),
      ),
    );
  }

  Widget _buildBody() {
    // && _messages.isEmpty — как и на остальных экранах (см. orders_screen.dart
    // и т.д.): _load() зовётся не только при первом открытии, но и retry-кнопкой
    // ErrorView. Без этой проверки повторный _load() поверх уже показанной
    // переписки на секунду затёр бы её skeleton-заглушкой (баг, которого пока
    // не было — _load() до сих пор не вызывался на непустом чате, но это
    // случайность, не гарантия).
    if (_loading && _messages.isEmpty) {
      return const _ChatSkeleton();
    }
    if (_error != null && _messages.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }

    return Column(
      children: [
        if (_isBlockedByShop)
          Container(
            width: double.infinity,
            color: Theme.of(context).colorScheme.errorContainer,
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 16),
            child: Text(
              'Магазин ограничил переписку — отправка сообщений недоступна',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Theme.of(context).colorScheme.onErrorContainer,
                fontSize: 13,
              ),
            ),
          ),
        Expanded(
          child: _messages.isEmpty
              ? Center(
                  child: Text(
                    'Напишите магазину, если есть вопрос по заказу',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                )
              : ListView.builder(
                  controller: _scrollController,
                  // reverse: низ ленты (последнее сообщение) — это смещение 0,
                  // фиксированная точка. Открытие всегда на последнем
                  // сообщении без прыжков; подгрузка старых сверху не двигает
                  // позицию. Индекс 0 — самое новое сообщение, спиннер
                  // «загрузка старых» уезжает в конец (визуально — наверх).
                  reverse: true,
                  // Горизонтальный отступ — НЕ здесь, а внутри каждого бабла
                  // (см. _MessageBubble.build(), AnimatedContainer с
                  // подсветкой выбора) — иначе полоса подсветки отмеченного
                  // сообщения упиралась бы в этот паддинг, а не шла от края
                  // до края экрана (жалоба с реального устройства, 2026-10-18).
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  itemCount: _messages.length + (_loadingOlder ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (_loadingOlder && index == _messages.length) {
                      return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 12),
                        child: Center(
                          child: SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        ),
                      );
                    }
                    final message = _messages[_messages.length - 1 - index];
                    final key = _messageKeys.putIfAbsent(
                      message.id,
                      () => GlobalKey<_MessageBubbleState>(),
                    );
                    return _MessageBubble(
                      key: key,
                      message: message,
                      isHighlighted: _highlightedMessageId == message.id,
                      isSelectionModeActive: _selectedIds.isNotEmpty,
                      isChecked: _selectedIds.contains(message.id),
                      onToggle: () => _onBubbleTap(message),
                      onConfirmQuote: (excerpt) => _confirmQuote(message, excerpt),
                      onCopied: _onExcerptCopied,
                      onTextSelectionChanged: (hasSelection) =>
                          _onBubbleTextSelectionChanged(message.id, hasSelection),
                      // Уже удалённому (плашка «Сообщение удалено») своему
                      // сообщению можно предложить только «Удалить» — стереть
                      // саму плашку насовсем (спека 2026-09-16); ответить или
                      // выбрать уже нечего — см. _onBubbleLongPress. Чужому
                      // (магазина) удалённому сообщению — вообще нечего
                      // предложить, у него так и остаётся пусто.
                      onLongPress: message.isDeleted && !message.isMine
                          ? () {}
                          : () => _onBubbleLongPress(message),
                      onSwipeReply: message.isDeleted ? () {} : () => _startReply(message),
                      // «Удалить» — только если магазин это разрешил
                      // (shops.chat_customer_delete_enabled); сервер тоже
                      // проверяет, но не показываем мёртвую кнопку. Только для
                      // фото на весь экран (см. _openFullImage) — у удалённого
                      // сообщения фото уже нет, поэтому isDeleted тут не при
                      // чём.
                      onDelete: (message.isMine && _canDeleteOwnMessages)
                          ? () => _deleteMessage(message)
                          : null,
                      onQuoteTap: message.replyToMessageId != null
                          ? () => _jumpToMessage(message.replyToMessageId!)
                          : null,
                    );
                  },
                ),
        ),
        KeyedSubtree(key: _composerKey, child: _buildComposer()),
      ],
    );
  }

  Widget _buildComposer() {
    final theme = Theme.of(context);
    return DecoratedBox(
      decoration: BoxDecoration(color: theme.colorScheme.surface),
      child: SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (_editingMessage != null)
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  children: [
                    Icon(Icons.edit_outlined, size: 18, color: theme.colorScheme.primary),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Изменить сообщение',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.primary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close_rounded, size: 18),
                      onPressed: _cancelEdit,
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                    ),
                  ],
                ),
              )
            else if (_replyTarget != null)
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  children: [
                    Icon(Icons.reply_rounded, size: 18, color: theme.colorScheme.primary),
                    const SizedBox(width: 8),
                    if (_replyTarget!.imageUrl != null) ...[
                      ClipRRect(
                        borderRadius: BorderRadius.circular(3),
                        child: Image.network(
                          _replyTarget!.imageUrl!,
                          width: 20,
                          height: 20,
                          fit: BoxFit.cover,
                        ),
                      ),
                      const SizedBox(width: 6),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            _quoteExcerpt != null ? 'Цитата' : 'Ответ на сообщение',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: theme.colorScheme.primary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            _quoteExcerpt ??
                                _replyTarget!.body ??
                                (_replyTarget!.imageUrl != null ? '📷 Фото' : ''),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close_rounded, size: 18),
                      onPressed: _cancelReply,
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                    ),
                  ],
                ),
              ),
            if (_pendingImages.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SizedBox(
                  height: 64,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.only(left: 4),
                    itemCount: _pendingImages.length,
                    separatorBuilder: (_, _) => const SizedBox(width: 8),
                    itemBuilder: (context, index) {
                      final image = _pendingImages[index];
                      return Stack(
                        clipBehavior: Clip.none,
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              image.url,
                              height: 64,
                              width: 64,
                              fit: BoxFit.cover,
                            ),
                          ),
                          Positioned(
                            top: -6,
                            right: -6,
                            child: IconButton(
                              icon: const Icon(Icons.cancel, size: 20),
                              onPressed: () => setState(() {
                                _pendingImages = [..._pendingImages]..removeAt(index);
                              }),
                            ),
                          ),
                        ],
                      );
                    },
                  ),
                ),
              ),
            if (_uploadProgress != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8, left: 4),
                child: Row(
                  children: [
                    const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      _uploadProgress!.$2 > 1
                          ? 'Загрузка ${_uploadProgress!.$1 + 1} из ${_uploadProgress!.$2}...'
                          : 'Загрузка фото...',
                    ),
                  ],
                ),
              ),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                IconButton(
                  icon: const Icon(Icons.image_outlined),
                  // Вложение фото недоступно во время редактирования — как
                  // у персонала в админке (ChatView.vue::startEdit), чтобы
                  // не путать "добавить фото к новому сообщению" с правкой
                  // уже отправленного текста.
                  onPressed: _isBlockedByShop || _uploadProgress != null || _editingMessage != null
                      ? null
                      : _pickImage,
                ),
                Expanded(
                  child: TextField(
                    controller: _draftController,
                    minLines: 1,
                    maxLines: 4,
                    enabled: !_isBlockedByShop,
                    decoration: InputDecoration(
                      hintText: _editingMessage != null ? 'Изменить текст…' : 'Сообщение...',
                      filled: true,
                      fillColor: theme.colorScheme.surfaceContainerHighest,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(20),
                        borderSide: BorderSide.none,
                      ),
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 10,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                IconButton(
                  icon: Icon(_editingMessage != null ? Icons.check_rounded : Icons.send_rounded),
                  onPressed: _isBlockedByShop || _sending ? null : _send,
                ),
              ],
            ),
          ],
        ),
      ),
      ),
    );
  }
}

/// Skeleton первой загрузки чата — чередующиеся пузыри слева/справа, те же
/// радиусы (14, скошенный угол 4), что у настоящего _MessageBubble.
class _ChatSkeleton extends StatelessWidget {
  const _ChatSkeleton();

  static const _widths = [0.6, 0.7, 0.55, 0.65, 0.72, 0.6];

  @override
  Widget build(BuildContext context) {
    final screenWidth = MediaQuery.sizeOf(context).width;

    return Shimmer(
      child: ListView.builder(
        reverse: true,
        // Тот же горизонтальный отступ, что и у настоящей ленты (которая
        // заменит этот скелет после загрузки) — псевдо-баблы должны встать
        // ровно там же, где появятся настоящие.
        padding: const EdgeInsets.symmetric(horizontal: _kChatHorizontalPadding, vertical: 8),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: _widths.length,
        itemBuilder: (context, index) {
          final isMine = index.isOdd;
          return Align(
            alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: SkeletonBox(
                width: screenWidth * _widths[index],
                height: 40,
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(14),
                  topRight: const Radius.circular(14),
                  bottomLeft: Radius.circular(isMine ? 14 : 4),
                  bottomRight: Radius.circular(isMine ? 4 : 14),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Мелкая обёртка, чтобы гонять URL уже загруженного фото по State без
/// путаницы с самим Uint8List байтов — после загрузки байты уже не нужны.
class Uint8ListHolder {
  final String url;
  const Uint8ListHolder(this.url);
}

class _MessageBubble extends StatefulWidget {
  final ChatMessage message;
  final bool isHighlighted;
  final VoidCallback onLongPress;
  final VoidCallback onSwipeReply;
  final VoidCallback? onDelete;
  final VoidCallback? onQuoteTap;
  // Режим множественного выбора активен на экране (набор выбранных не
  // пуст) — у ВСЕХ баблов слева появляется кружок-чекбокс (как в
  // Телеграме), свайп-ответ выключается, тап по баблу тоже тоггл-ит выбор.
  final bool isSelectionModeActive;
  // Этот конкретный бабл отмечен галочкой в наборе выбора.
  final bool isChecked;
  // Тап по кружку/баблу в режиме выбора — тоггл в наборе на экране.
  final VoidCallback? onToggle;
  final ValueChanged<String>? onConfirmQuote;
  // Отрывок скопирован в буфер — так же, как и цитата, законченное действие,
  // после которого галочка на бабле снимается (см. _buildSelectionToolbar).
  final VoidCallback? onCopied;
  // Сообщает экрану, что в ЭТОМ бабле появилось/пропало текстовое выделение
  // (см. PLAN.md 2026-10-18) — экрану нужно это для ступенчатого PopScope
  // (1-й "Назад" снимает только выделение) и чтобы увести бабл от поля
  // ввода, если выделили самое нижнее сообщение в ленте.
  final ValueChanged<bool>? onTextSelectionChanged;

  const _MessageBubble({
    super.key,
    required this.message,
    this.isHighlighted = false,
    required this.onLongPress,
    required this.onSwipeReply,
    this.onDelete,
    this.onQuoteTap,
    this.isSelectionModeActive = false,
    this.isChecked = false,
    this.onToggle,
    this.onConfirmQuote,
    this.onCopied,
    this.onTextSelectionChanged,
  });

  @override
  State<_MessageBubble> createState() => _MessageBubbleState();
}

class _MessageBubbleState extends State<_MessageBubble>
    with SingleTickerProviderStateMixin {
  double _dragOffset = 0;
  static const _replyThreshold = 64.0;
  static const _maxDrag = 80.0;

  // SelectionArea оборачивает текст ТОЛЬКО когда бабл отмечен (widget.isChecked,
  // см. build() ниже) — выделение слова по долгому нажатию, капли и плавающее
  // меню делает сам Flutter, нам остаётся не мешать (см. PLAN.md 2026-10-18,
  // «отдать выделение самому Flutter»). Ключ — чтобы снять выделение снаружи
  // (ступенчатый "Назад", см. ChatScreen._cancelSelecting) через _messageKeys.
  final GlobalKey<SelectionAreaState> _selectionAreaKey = GlobalKey<SelectionAreaState>();
  String? _selectedExcerpt;

  void clearTextSelection() {
    _selectionAreaKey.currentState?.selectableRegion.clearSelection();
  }

  void _onTextSelectionChanged(SelectedContent? content) {
    final text = content?.plainText;
    setState(() => _selectedExcerpt = (text == null || text.isEmpty) ? null : text);
    widget.onTextSelectionChanged?.call(_selectedExcerpt != null);
  }

  Widget _buildSelectionToolbar(BuildContext context, SelectableRegionState state) {
    // Отрывок читаем прямо здесь, а не кэшируем на момент onSelectionChanged —
    // Flutter строит этот тулбар уже ПОСЛЕ того, как _selectedExcerpt успел
    // обновиться (см. _onTextSelectionChanged), поэтому кнопки всегда видят
    // актуальный отрывок, без прежнего бага с пустым меню.
    final excerpt = _selectedExcerpt;
    return AdaptiveTextSelectionToolbar.buttonItems(
      anchors: state.contextMenuAnchors,
      buttonItems: [
        ContextMenuButtonItem(
          label: 'Копировать',
          onPressed: () async {
            state.hideToolbar();
            if (excerpt != null) await Clipboard.setData(ClipboardData(text: excerpt));
            state.clearSelection();
            // Копирование — тоже законченное действие (как и цитата,
            // см. onConfirmQuote ниже) — галочка на бабле не должна
            // оставаться висеть.
            widget.onCopied?.call();
          },
        ),
        ContextMenuButtonItem(
          label: 'Цитировать',
          onPressed: () {
            state.hideToolbar();
            if (excerpt != null) widget.onConfirmQuote?.call(excerpt);
            state.clearSelection();
          },
        ),
      ],
    );
  }

  void _onDragUpdate(DragUpdateDetails details) {
    // Свайп ВЛЕВО = ответить (delta.dx отрицательный при движении влево,
    // поэтому знак инвертирован — _dragOffset всегда положительный "как
    // далеко утянули влево").
    setState(() {
      _dragOffset = (_dragOffset - details.delta.dx).clamp(0, _maxDrag);
    });
  }

  void _onDragEnd(DragEndDetails details) {
    if (_dragOffset >= _replyThreshold) {
      HapticFeedback.mediumImpact();
      widget.onSwipeReply();
    }
    setState(() => _dragOffset = 0);
  }

  @override
  Widget build(BuildContext context) {
    final message = widget.message;
    final theme = Theme.of(context);
    final isMine = message.isMine;
    final time =
        '${message.createdAt.toLocal().hour.toString().padLeft(2, '0')}:'
        '${message.createdAt.toLocal().minute.toString().padLeft(2, '0')}';

    final bubble = GestureDetector(
      // В режиме множественного выбора свайп-ответ выключен у ВСЕХ баблов
      // (как в Телеграме). Долгое нажатие выключаем только у уже ОТМЕЧЕННОГО
      // бабла — его перехватывает SelectionArea вокруг текста (см. ниже) и
      // сразу выделяет слово под пальцем, без конкуренции двух
      // LongPressGestureRecognizer в одной gesture arena (риск, описанный в
      // PLAN.md 2026-10-18). На неотмеченном бабле долгое нажатие по-прежнему
      // добавляет его в выбор (_onBubbleLongPress в ChatScreen).
      onHorizontalDragUpdate: widget.isSelectionModeActive ? null : _onDragUpdate,
      onHorizontalDragEnd: widget.isSelectionModeActive ? null : _onDragEnd,
      onLongPress: widget.isChecked ? null : widget.onLongPress,
      // Обычный тап по баблу, пока активен режим выбора — тоже тоггл-ит
      // выбор (как в Телеграме), не только кружок слева.
      onTap: widget.isSelectionModeActive ? widget.onToggle : null,
      // behavior: opaque — без этого тап ловился бы ТОЛЬКО там, где
      // реально что-то нарисовано (сам цветной бабл, см. decoration ниже),
      // а не во всей ширине ряда (пустое место рядом с коротким баблом —
      // "мёртвая зона", по умолчанию GestureDetector там не отвечает). Вся
      // "сущность сообщения" должна быть тапабельна во всю ширину экрана
      // в режиме выбора — отсюда opaque, не только на самом бабле.
      behavior: HitTestBehavior.opaque,
      child: Stack(
        children: [
          if (_dragOffset > 4)
            Positioned.fill(
              child: Align(
                alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
                child: Opacity(
                  opacity: (_dragOffset / _replyThreshold).clamp(0, 1),
                  child: Icon(Icons.reply_rounded, color: theme.colorScheme.primary),
                ),
              ),
            ),
          AnimatedContainer(
            duration: _dragOffset == 0
                ? const Duration(milliseconds: 200)
                : Duration.zero,
            curve: Curves.easeOut,
            transform: Matrix4.translationValues(-_dragOffset, 0, 0),
            child: Align(
              alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 300),
                constraints: BoxConstraints(
                  minWidth: MediaQuery.of(context).size.width * 0.5,
                  maxWidth: MediaQuery.of(context).size.width * 0.75,
                ),
                margin: const EdgeInsets.symmetric(vertical: 3),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  // Короткая подсветка при прыжке по цитате к оригиналу
                  // (см. ChatScreen._jumpToMessage) — тот же цвет для обеих
                  // сторон бабла, чтобы не путать с обычным «своим» цветом.
                  // Отмеченный в режиме выбора бабл — слегка темнее (как в
                  // Телеграме), не рамка — сам фон.
                  color: _bubbleColor(theme, isMine),
                  borderRadius: BorderRadius.only(
                    topLeft: const Radius.circular(14),
                    topRight: const Radius.circular(14),
                    bottomLeft: Radius.circular(isMine ? 14 : 4),
                    bottomRight: Radius.circular(isMine ? 4 : 14),
                  ),
                ),
                child: IntrinsicWidth(
                  child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Блок цитаты — только у ЖИВОГО сообщения. У удалённого
                    // (message.isDeleted, см. ветку ниже) от него остаётся
                    // только плашка "Сообщение удалено" — сам блок, что
                    // именно это сообщение когда-то цитировало, не нужен,
                    // иначе он виснет артефактом поверх уже удалённого
                    // пузыря (баг с реального устройства, 2026-10-18).
                    if (message.replyTo != null && !message.isDeleted)
                      GestureDetector(
                        // В режиме выбора тап по цитате тоже тоггл-ит выбор,
                        // не прыгает к оригиналу — тот же приём, что у
                        // картинки выше (behavior: opaque тоже нужен —
                        // Container ниже без decoration.color не ловит тап
                        // в своей "пустой" части, только там, где рисуется
                        // border).
                        onTap: widget.isSelectionModeActive ? widget.onToggle : widget.onQuoteTap,
                        behavior: HitTestBehavior.opaque,
                        child: Container(
                        margin: const EdgeInsets.only(bottom: 6),
                        padding: const EdgeInsets.only(left: 8, bottom: 6),
                        decoration: BoxDecoration(
                          border: Border(
                            left: BorderSide(
                              color: isMine
                                  ? theme.colorScheme.onPrimary.withValues(alpha: 0.5)
                                  : theme.colorScheme.primary,
                              width: 2,
                            ),
                            bottom: BorderSide(
                              color: isMine
                                  ? theme.colorScheme.onPrimary.withValues(alpha: 0.3)
                                  : theme.colorScheme.outlineVariant,
                              width: 1,
                            ),
                          ),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (message.replyTo!.imageUrl != null) ...[
                              ClipRRect(
                                borderRadius: BorderRadius.circular(3),
                                child: Image.network(
                                  message.replyTo!.imageUrl!,
                                  width: 20,
                                  height: 20,
                                  fit: BoxFit.cover,
                                ),
                              ),
                              const SizedBox(width: 6),
                            ],
                            Flexible(
                              child: Text(
                                message.replyTo!.isDeleted
                                    ? 'Сообщение удалено'
                                    // replyQuote — отрывок, который реально
                                    // выделили (см. PLAN.md 2026-10-18), а не
                                    // весь текст оригинала.
                                    : (message.replyQuote ??
                                        message.replyTo!.body ??
                                        (message.replyTo!.imageUrl != null ? '📷 Фото' : '')),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: isMine
                                      ? theme.colorScheme.onPrimary.withValues(alpha: 0.8)
                                      : theme.colorScheme.onSurfaceVariant,
                                ),
                              ),
                            ),
                          ],
                        ),
                        ),
                      )
                    else if (message.replyToMessageId != null && !message.isDeleted)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Text(
                          'Сообщение удалено',
                          style: theme.textTheme.bodySmall?.copyWith(
                            fontStyle: FontStyle.italic,
                            color: isMine
                                ? theme.colorScheme.onPrimary.withValues(alpha: 0.6)
                                : theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ),
                    if (message.isDeleted)
                      Text(
                        'Сообщение удалено',
                        textAlign: isMine ? TextAlign.right : TextAlign.left,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontStyle: FontStyle.italic,
                          color: isMine
                              ? theme.colorScheme.onPrimary.withValues(alpha: 0.7)
                              : theme.colorScheme.onSurfaceVariant,
                        ),
                      )
                    else ...[
                      if (message.imageUrl != null)
                        ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: GestureDetector(
                            // В режиме выбора тап по картинке тоже тоггл-ит
                            // выбор, а не открывает просмотр — иначе этот
                            // внутренний GestureDetector перехватывал бы тап
                            // раньше внешнего (победил бы в gesture arena,
                            // так как он глубже), и фото всегда открывалось
                            // бы вместо выбора.
                            onTap: widget.isSelectionModeActive
                                ? widget.onToggle
                                : () => _openFullImage(context, message),
                            behavior: HitTestBehavior.opaque,
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(maxHeight: 220),
                              child: Image.network(message.imageUrl!, fit: BoxFit.cover),
                            ),
                          ),
                        ),
                      if (message.body != null && message.body!.isNotEmpty)
                        Padding(
                          padding: EdgeInsets.only(
                            top: message.imageUrl != null ? 6 : 0,
                          ),
                          // Отмеченный бабл оборачиваем в SelectionArea — долгое
                          // нажатие на нём больше не ловит onLongPress выше (см.
                          // GestureDetector), поэтому его забирает сама
                          // SelectionArea: слово выделяется, капли и плавающее
                          // меню «Копировать/Цитировать» показывает штатный
                          // механизм Flutter (см. PLAN.md 2026-10-18).
                          // Неотмеченный — обычный Text, как раньше.
                          child: widget.isChecked
                              ? DefaultSelectionStyle(
                                  // Подсветка выделения по умолчанию —
                                  // полупрозрачный серый (DefaultSelectionStyle.
                                  // defaultColor) — на тёмном «своём» бабле
                                  // (цвет primary) она почти не видна. Берём
                                  // цвет текста с небольшой прозрачностью —
                                  // так же, как Flutter берёт цвет курсора/
                                  // выделения в TextField по умолчанию.
                                  selectionColor: (isMine
                                          ? theme.colorScheme.onPrimary
                                          : theme.colorScheme.primary)
                                      .withValues(alpha: 0.35),
                                  child: SelectionArea(
                                    key: _selectionAreaKey,
                                    onSelectionChanged: _onTextSelectionChanged,
                                    contextMenuBuilder: _buildSelectionToolbar,
                                    // Тап по тексту отмеченного бабла — тоггл
                                    // выбора, как и везде в режиме выбора. Без
                                    // этого отдельного GestureDetector тап
                                    // проглатывает сама SelectionArea (у неё
                                    // свой обработчик тапа — схлопнуть
                                    // выделение), и внешний GestureDetector
                                    // бабла (см. выше, onTap) до тогла не
                                    // доходит: во внутренней arena жестов
                                    // SelectionArea стабильно побеждает внешний
                                    // GestureDetector на простом тапе — только
                                    // ВНУТРЕННИЙ (ближе к тексту) выигрывает.
                                    // Долгое нажатие это не трогает — тут нет
                                    // onLongPress, им по-прежнему управляет
                                    // сама SelectionArea.
                                    child: GestureDetector(
                                      behavior: HitTestBehavior.opaque,
                                      onTap: widget.onToggle,
                                      child: Text(
                                        message.body!,
                                        textAlign: isMine ? TextAlign.right : TextAlign.left,
                                        style: TextStyle(
                                          color: isMine
                                              ? theme.colorScheme.onPrimary
                                              : theme.colorScheme.onSurface,
                                        ),
                                      ),
                                    ),
                                  ),
                                )
                              : Text(
                                  message.body!,
                                  textAlign: isMine ? TextAlign.right : TextAlign.left,
                                  style: TextStyle(
                                    color: isMine
                                        ? theme.colorScheme.onPrimary
                                        : theme.colorScheme.onSurface,
                                  ),
                                ),
                        ),
                    ],
                    const SizedBox(height: 2),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      mainAxisAlignment: isMine ? MainAxisAlignment.end : MainAxisAlignment.start,
                      children: [
                        if (message.editedAt != null) ...[
                          Text(
                            'изменено',
                            style: theme.textTheme.labelSmall?.copyWith(
                              fontStyle: FontStyle.italic,
                              color: isMine
                                  ? theme.colorScheme.onPrimary.withValues(alpha: 0.6)
                                  : theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                          const SizedBox(width: 4),
                        ],
                        Text(
                          time,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: isMine
                                ? theme.colorScheme.onPrimary.withValues(alpha: 0.7)
                                : theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                        if (isMine) ...[
                          const SizedBox(width: 4),
                          Icon(
                            message.status == 'read' ? Icons.done_all : Icons.done,
                            size: 14,
                            color: message.status == 'read'
                                ? Colors.lightBlueAccent
                                : theme.colorScheme.onPrimary.withValues(alpha: 0.7),
                          ),
                        ],
                      ],
                    ),
                  ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );

    // Кружок-чекбокс слева у ВСЕХ баблов (и своих, и чужих — как в
    // Телеграме), появляется/исчезает плавно с режимом выбора. Сам бабл
    // по-прежнему выравнивается внутри Expanded через Align (см. выше) —
    // поэтому «правые стоят на месте», просто теснее на ширину кружка.
    //
    // Подсветка отмеченного сообщения — полоса ОТ КРАЯ ДО КРАЯ экрана (не
    // только внутри горизонтального отступа ленты), как в Телеграме. Поэтому
    // сам отступ (12px) переехал сюда, внутрь подсветки, а не остался на
    // ListView — см. padding у ListView.builder выше (жалоба пользователя со
    // скриншотом, где красным обведён незатемнённый паддинг, 2026-10-18).
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      color: widget.isChecked
          ? theme.colorScheme.primary.withValues(alpha: 0.16)
          : Colors.transparent,
      padding: const EdgeInsets.symmetric(horizontal: _kChatHorizontalPadding),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          AnimatedSize(
            duration: const Duration(milliseconds: 200),
            curve: Curves.easeOut,
            child: widget.isSelectionModeActive
                ? Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: GestureDetector(
                      onTap: widget.onToggle,
                      behavior: HitTestBehavior.opaque,
                      child: Icon(
                        widget.isChecked ? Icons.check_circle_rounded : Icons.radio_button_unchecked,
                        color: widget.isChecked
                            ? theme.colorScheme.primary
                            : theme.colorScheme.outline,
                        size: 22,
                      ),
                    ),
                  )
                : const SizedBox(width: 0, height: 0),
          ),
          Expanded(child: bubble),
        ],
      ),
    );
  }

  /// Фон бабла: обычный цвет, либо подсветка при прыжке по цитате. Подсветка
  /// отметки в режиме выбора теперь на уровне всего ряда (см. build() выше),
  /// не здесь — цвет самого бабла при отметке не меняется.
  Color _bubbleColor(ThemeData theme, bool isMine) {
    if (widget.isHighlighted) return theme.colorScheme.tertiaryContainer;
    return isMine ? theme.colorScheme.primary : theme.colorScheme.surfaceContainerHighest;
  }

  void _openFullImage(BuildContext context, ChatMessage message) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => _FullImageViewer(
          message: message,
          onReply: widget.onSwipeReply,
          onDelete: widget.onDelete,
        ),
      ),
    );
  }
}

/// Просмотр фото на весь экран — по образцу Telegram (идея пользователя,
/// 2026-08-25): дата вместо голого AppBar, подпись поверх фото снизу, меню
/// действий вместо системного "поделиться скриншотом". onReply/onDelete —
/// колбэки в _ChatScreenState, а не самостоятельная логика здесь: этот
/// виджет ничего не знает про список сообщений и сессию.
class _FullImageViewer extends StatelessWidget {
  final ChatMessage message;
  final VoidCallback onReply;
  final VoidCallback? onDelete;

  const _FullImageViewer({required this.message, required this.onReply, this.onDelete});

  /// Расширение файла по URL картинки — сервер отдаёт и старые .jpg/.png
  /// (до перехода на WebP 2026-08-24), и новые .webp вперемешку в одном чате.
  /// Фолбэк на .jpg, если в URL расширения почему-то нет.
  static String _extensionFromUrl(String url) {
    final path = Uri.parse(url).path;
    final dot = path.lastIndexOf('.');
    if (dot == -1) return '.jpg';
    const known = {'.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp'};
    final ext = path.substring(dot).toLowerCase();
    return known.contains(ext) ? ext : '.jpg';
  }

  Future<void> _save(BuildContext context) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final granted = await Gal.requestAccess();
      if (!granted) {
        messenger.showSnackBar(const SnackBar(content: Text('Нет доступа к галерее')));
        return;
      }
      final response = await http.get(Uri.parse(message.imageUrl!));
      // На Android Gal.putImageBytes() угадывает формат по содержимому через
      // библиотеку, которая не знает про WebP (наш сервер шлёт .webp с
      // 2026-08-24) и падает с ошибкой на любой такой картинке — тихо
      // проваливается в catch ниже. Gal.putImage() по пути к файлу берёт
      // формат из расширения имени и не угадывает — пишем во временный файл
      // с правильным расширением и сохраняем этим путём.
      final dir = await getTemporaryDirectory();
      final file = File(
        '${dir.path}/servicebox_${DateTime.now().millisecondsSinceEpoch}${_extensionFromUrl(message.imageUrl!)}',
      );
      await file.writeAsBytes(response.bodyBytes);
      await Gal.putImage(file.path);
      messenger.showSnackBar(const SnackBar(content: Text('Сохранено в галерею')));
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('Не удалось сохранить фото')));
    }
  }

  Future<void> _share(BuildContext context) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final response = await http.get(Uri.parse(message.imageUrl!));
      final dir = await getTemporaryDirectory();
      final file = File(
        '${dir.path}/chat_share_${DateTime.now().millisecondsSinceEpoch}${_extensionFromUrl(message.imageUrl!)}',
      );
      await file.writeAsBytes(response.bodyBytes);
      await Share.shareXFiles([XFile(file.path)]);
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('Не удалось поделиться фото')));
    }
  }

  /// «Три точки» в AppBar — меню закреплено у самой кнопки (showMenu), как
  /// в настоящем Телеграме, не шторка снизу (см. PLAN.md 2026-10-18).
  /// `context` — контекст самой кнопки (см. Builder в build() ниже), не
  /// общий контекст экрана, иначе позицию взять было бы не у чего.
  Future<void> _openMenu(BuildContext context) async {
    final box = context.findRenderObject() as RenderBox;
    final topLeft = box.localToGlobal(Offset.zero);
    final overlayBox = Overlay.of(context).context.findRenderObject() as RenderBox;
    final position = RelativeRect.fromLTRB(
      topLeft.dx,
      topLeft.dy + box.size.height,
      overlayBox.size.width - topLeft.dx - box.size.width,
      0,
    );

    final action = await showMenu<String>(
      context: context,
      position: position,
      items: [
        const PopupMenuItem(
          value: 'save',
          child: ListTile(leading: Icon(Icons.download_rounded), title: Text('Сохранить в галерею')),
        ),
        const PopupMenuItem(
          value: 'reply',
          child: ListTile(leading: Icon(Icons.reply_rounded), title: Text('Ответить')),
        ),
        const PopupMenuItem(
          value: 'share',
          child: ListTile(leading: Icon(Icons.share_rounded), title: Text('Поделиться')),
        ),
        if (onDelete != null)
          PopupMenuItem(
            value: 'delete',
            child: ListTile(
              leading: Icon(Icons.delete_outline_rounded, color: Theme.of(context).colorScheme.error),
              title: Text('Удалить', style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
          ),
      ],
    );

    if (!context.mounted) return;
    switch (action) {
      case 'save':
        _save(context);
      case 'reply':
        Navigator.of(context).pop();
        onReply();
      case 'share':
        _share(context);
      case 'delete':
        Navigator.of(context).pop();
        onDelete!();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(formatMessageDateTime(message.createdAt), style: const TextStyle(fontSize: 16)),
        actions: [
          // Builder — контекст СКОПИРОВАН на саму кнопку (не общий контекст
          // экрана), иначе в _openMenu не у чего было бы взять позицию для
          // закреплённого меню (showMenu).
          Builder(
            builder: (btnContext) => IconButton(
              icon: const Icon(Icons.more_vert_rounded),
              onPressed: () => _openMenu(btnContext),
            ),
          ),
        ],
      ),
      body: Stack(
        children: [
          Center(child: InteractiveViewer(child: Image.network(message.imageUrl!))),
          if (message.body != null && message.body!.isNotEmpty)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.fromLTRB(16, 32, 16, 16),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Colors.black.withValues(alpha: 0), Colors.black.withValues(alpha: 0.75)],
                  ),
                ),
                child: SafeArea(
                  top: false,
                  child: Text(message.body!, style: const TextStyle(color: Colors.white)),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
