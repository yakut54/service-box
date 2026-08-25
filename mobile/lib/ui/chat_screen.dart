import 'dart:async';
import 'dart:io';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gal/gal.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../core/image_compress.dart';
import '../core/uuid.dart';
import '../data/chat_realtime_client.dart';
import '../data/chat_repository.dart';
import '../models/chat_message.dart';
import '../state/auth_state.dart';
import '../state/chat_state.dart';
import 'widgets/app_dialog.dart';
import 'widgets/chat_background.dart';
import 'widgets/error_view.dart';

/// Диалог байера с магазином — один тред, лента снизу вверх, отправка
/// текста и фото. Доставка — WebSocket (Reverb) как основной путь, обычный
/// опрос остаётся редкой подстраховкой на случай обрыва сокета (см.
/// PLAN-CHAT.md §12).
class ChatScreen extends StatefulWidget {
  /// Путь к файлу, с которым открыли приложение через системное
  /// «Поделиться» (Android, см. main.dart + MainActivity.kt) — фото сразу
  /// подгружается и встаёт в композер, как будто выбрано из галереи.
  final String? sharedImagePath;

  const ChatScreen({super.key, this.sharedImagePath});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> with WidgetsBindingObserver {
  final _repository = ChatRepository.create();
  late final _realtime = ChatRealtimeClient(_repository);
  final _scrollController = ScrollController();
  final _draftController = TextEditingController();
  final _player = AudioPlayer();

  List<ChatMessage> _messages = [];
  bool _loading = true;
  bool _loadingOlder = false;
  bool _hasMoreOlder = true;
  bool _isBlockedByShop = false;
  bool _initialScrollSettled = false;
  final Map<String, GlobalKey> _messageKeys = {};
  String? _jumpingToMessageId;
  String? _highlightedMessageId;
  Timer? _highlightTimer;
  AppException? _error;
  Timer? _pollTimer;
  bool _sending = false;
  Uint8ListHolder? _pendingImage;
  bool _uploadingImage = false;
  ChatMessage? _replyTarget;
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
    WidgetsBinding.instance.addObserver(this);
    _scrollController.addListener(_onScroll);
    _draftController.addListener(_onDraftChanged);
    _load();
    final sharedPath = widget.sharedImagePath;
    if (sharedPath != null) {
      File(sharedPath).readAsBytes().then((bytes) {
        if (mounted) _uploadPickedBytes(bytes);
      });
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    _shopTypingClearTimer?.cancel();
    _sendTypingClearTimer?.cancel();
    _realtime.disconnect();
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
    if (state == AppLifecycleState.resumed) {
      _startPolling();
      if (_threadId != null) _connectRealtime(_threadId!);
    } else {
      _pollTimer?.cancel();
      _realtime.disconnect();
    }
  }

  void _startPolling() {
    _pollTimer?.cancel();
    // Раньше это был единственный путь доставки (4с) — теперь основной путь
    // это WebSocket, poll остаётся редкой подстраховкой на случай обрыва
    // сокета/пропущенного события при реконнекте.
    _pollTimer = Timer.periodic(const Duration(seconds: 20), (_) => _poll());
  }

  void _connectRealtime(String threadId) {
    _realtime.connect(
      threadId: threadId,
      sessionToken: _sessionToken,
      onEvent: (event, data) {
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
      },
    );
    // "Байер тут" для магазина — тот же пинг, что и печать, is_typing:false.
    _repository.sendPresence(_sessionToken, isTyping: false).catchError((_) {});
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
      if (_threadId != null) _connectRealtime(_threadId!);
      _scrollToBottom(force: true);
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
        // force для своих сообщений — тот же список уже вырос ДО этого
        // вызова (см. setState выше), сравнивать текущий pixels со свежим
        // maxScrollExtent бессмысленно для бабла выше ~80px, см. комментарий
        // в _jumpToBottomIfAtBottom.
        _scrollToBottom(force: newOnes.any((m) => m.isMine));
      }
    } catch (_) {
      // фоновый опрос — молча пропускаем
    }
  }

  bool get _isAtBottom {
    if (!_scrollController.hasClients) return true;
    return _scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 80;
  }

  void _scrollToBottom({bool force = false}) {
    // force — список уже вырос (сообщение дописано в _messages) ДО этого
    // вызова, поэтому текущий pixels сравнивать с новым maxScrollExtent
    // бессмысленно: _isAtBottom всегда провалится для бабла выше ~80px,
    // и мы молча не долистаем до своего же свежего сообщения (баг найден
    // 2026-08-25 живым тестом). force пропускает эту проверку для первого
    // прыжка — мы и так точно знаем, что должны оказаться внизу.
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => _jumpToBottomIfAtBottom(force: force),
    );
    // Фото в истории декодируются асинхронно и увеличивают высоту списка
    // уже ПОСЛЕ первого прыжка вниз — без повторных попыток байер остаётся
    // чуть выше настоящего конца чата, пока не долистает руками. Повторяем
    // прыжок несколько раз с нарастающей паузой, но только если байер за
    // это время сам никуда не проскроллил.
    for (final delay in const [
      Duration(milliseconds: 150),
      Duration(milliseconds: 400),
      Duration(milliseconds: 900),
    ]) {
      Future.delayed(delay, _jumpToBottomIfAtBottom);
    }
  }

  void _jumpToBottomIfAtBottom({bool force = false}) {
    if (!mounted || !_scrollController.hasClients) return;
    if (!force && !_isAtBottom) return;
    _scrollController.jumpTo(_scrollController.position.maxScrollExtent);
    // Разрешаем автоподгрузку старой истории по скроллу только после того,
    // как мы хотя бы раз осознанно долистали до низа. Иначе ScrollController
    // успевает уведомить _onScroll о pixels=0 ещё на самом первом кадре,
    // ДО этого прыжка — тот читает это как "долистали до самого верха" и
    // сразу тянет ещё страницу истории, чей anchor-jump уносит байера в
    // середину объединённого списка вместо низа (баг найден 2026-08-25
    // живым тестом — ровно то самое "открываю чат и я где-то в центре").
    _initialScrollSettled = true;
  }

  void _onScroll() {
    if (!_initialScrollSettled) return;
    if (!_scrollController.hasClients || _loadingOlder || !_hasMoreOlder) {
      return;
    }
    if (_scrollController.position.pixels <= 60) {
      _loadOlder();
    }
  }

  Future<void> _loadOlder() async {
    final prevExtent = _scrollController.hasClients
        ? _scrollController.position.maxScrollExtent
        : 0.0;
    final fetched = await _fetchOlderBatch();
    if (!fetched) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      final newExtent = _scrollController.position.maxScrollExtent;
      _scrollController.jumpTo(newExtent - prevExtent);
    });
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
        final approx =
            (index / _messages.length) *
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
    // Раньше здесь был свой чекер "Сделать фото / Выбрать из галереи" перед
    // системным пикером. Убрали лишний шаг — системная галерея на
    // современном Android (image_picker уходит именно в неё для
    // ImageSource.gallery) уже сама показывает грид недавних фото с
    // плиткой камеры в углу, один общий bottom sheet, как в Telegram
    // (идея пользователя, 2026-08-25) — свой экран поверх него не нужен.
    final picked = await ImagePicker().pickImage(source: ImageSource.gallery);
    if (picked == null || !mounted) return;

    await _uploadPickedBytes(await picked.readAsBytes());
  }

  /// Общий путь для картинки из пикера и картинки, с которой открыли
  /// приложение через системное «Поделиться» (см. widget.sharedImagePath).
  Future<void> _uploadPickedBytes(Uint8List bytes) async {
    if (!mounted) return;
    setState(() => _uploadingImage = true);
    try {
      // Сжимаем и на клиенте — экономит трафик байера на отправке, но это
      // не гарантия: сервер сам ЖЁСТКО сжимает до ≤100 КБ вне зависимости
      // от того, что пришло (см. ImageCompressionService, П20.1).
      final compressed = await compressImageBytes(
        bytes,
        maxDimension: 1024,
        targetBytes: 100 * 1024,
      );
      final url = await _repository.uploadImage(
        _sessionToken,
        compressed,
        'chat.jpg',
      );
      if (mounted) setState(() => _pendingImage = Uint8ListHolder(url));
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось загрузить фото');
    } finally {
      if (mounted) setState(() => _uploadingImage = false);
    }
  }

  Future<void> _send() async {
    final body = _draftController.text.trim();
    final imageUrl = _pendingImage?.url;
    if (body.isEmpty && imageUrl == null) return;

    // Не ждать 3с таймер — отправка сама по себе уже сигнал "закончил печатать".
    if (_wasTyping) {
      _wasTyping = false;
      _sendTypingClearTimer?.cancel();
      _repository.sendPresence(_sessionToken, isTyping: false).catchError((_) {});
    }

    setState(() => _sending = true);
    try {
      final message = await _repository.sendMessage(
        _sessionToken,
        body: body.isEmpty ? null : body,
        imageUrl: imageUrl,
        clientMessageId: generateUuidV4(),
        replyToMessageId: _replyTarget?.id,
      );
      setState(() {
        _messages = [..._messages, message];
        _draftController.clear();
        _pendingImage = null;
        _replyTarget = null;
        _threadId ??= message.threadId;
      });
      if (_threadId != null && !_realtime.isConnected) {
        _connectRealtime(_threadId!);
      }
      _scrollToBottom(force: true);
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось отправить сообщение');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _startReply(ChatMessage m) {
    setState(() => _replyTarget = m);
  }

  void _cancelReply() {
    setState(() => _replyTarget = null);
  }

  Future<void> _copyMessage(ChatMessage m) async {
    if (m.body == null || m.body!.isEmpty) return;
    await Clipboard.setData(ClipboardData(text: m.body!));
    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Скопировано')));
    }
  }

  Future<void> _deleteMessage(ChatMessage m) async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Удалить сообщение?',
      message: 'Сообщение и приложенное фото (если есть) будут удалены безвозвратно.',
      confirmLabel: 'Удалить',
    );
    if (!confirmed || !mounted) return;

    try {
      await _repository.deleteMessage(_sessionToken, m.id);
      setState(() => _messages = _messages.where((x) => x.id != m.id).toList());
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось удалить сообщение');
    }
  }

  void _openMessageMenu(ChatMessage m) {
    HapticFeedback.selectionClick();
    showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.reply_rounded),
              title: const Text('Ответить'),
              onTap: () {
                Navigator.of(ctx).pop();
                _startReply(m);
              },
            ),
            if (m.body != null && m.body!.isNotEmpty)
              ListTile(
                leading: const Icon(Icons.copy_rounded),
                title: const Text('Скопировать'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _copyMessage(m);
                },
              ),
            if (m.isMine)
              ListTile(
                leading: Icon(
                  Icons.delete_outline_rounded,
                  color: Theme.of(ctx).colorScheme.error,
                ),
                title: Text(
                  'Удалить',
                  style: TextStyle(color: Theme.of(ctx).colorScheme.error),
                ),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _deleteMessage(m);
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  void _showError(Object e, String fallback) {
    final message = e is AppException ? e.message : fallback;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
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
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
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
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 8,
                  ),
                  itemCount: _messages.length + (_loadingOlder ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (_loadingOlder && index == 0) {
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
                    final message = _messages[index - (_loadingOlder ? 1 : 0)];
                    final key = _messageKeys.putIfAbsent(
                      message.id,
                      () => GlobalKey(),
                    );
                    return _MessageBubble(
                      key: key,
                      message: message,
                      isHighlighted: _highlightedMessageId == message.id,
                      onLongPress: () => _openMessageMenu(message),
                      onSwipeReply: () => _startReply(message),
                      onDelete: message.isMine ? () => _deleteMessage(message) : null,
                      onQuoteTap: message.replyToMessageId != null
                          ? () => _jumpToMessage(message.replyToMessageId!)
                          : null,
                    );
                  },
                ),
        ),
        _buildComposer(),
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
            if (_replyTarget != null)
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
                            'Ответ на сообщение',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: theme.colorScheme.primary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            _replyTarget!.body ?? (_replyTarget!.imageUrl != null ? '📷 Фото' : ''),
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
            if (_pendingImage != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8, left: 4),
                child: Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.network(
                        _pendingImage!.url,
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
                        onPressed: () => setState(() => _pendingImage = null),
                      ),
                    ),
                  ],
                ),
              ),
            if (_uploadingImage)
              const Padding(
                padding: EdgeInsets.only(bottom: 8, left: 4),
                child: Row(
                  children: [
                    SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                    SizedBox(width: 8),
                    Text('Загрузка фото...'),
                  ],
                ),
              ),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                IconButton(
                  icon: const Icon(Icons.image_outlined),
                  onPressed: _isBlockedByShop || _uploadingImage
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
                      hintText: 'Сообщение...',
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
                  icon: const Icon(Icons.send_rounded),
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

  const _MessageBubble({
    super.key,
    required this.message,
    this.isHighlighted = false,
    required this.onLongPress,
    required this.onSwipeReply,
    this.onDelete,
    this.onQuoteTap,
  });

  @override
  State<_MessageBubble> createState() => _MessageBubbleState();
}

class _MessageBubbleState extends State<_MessageBubble>
    with SingleTickerProviderStateMixin {
  double _dragOffset = 0;
  static const _replyThreshold = 64.0;
  static const _maxDrag = 80.0;

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

    return GestureDetector(
      onHorizontalDragUpdate: _onDragUpdate,
      onHorizontalDragEnd: _onDragEnd,
      onLongPress: widget.onLongPress,
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
                  color: widget.isHighlighted
                      ? theme.colorScheme.tertiaryContainer
                      : isMine
                          ? theme.colorScheme.primary
                          : theme.colorScheme.surfaceContainerHighest,
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
                    if (message.replyTo != null)
                      GestureDetector(
                        onTap: widget.onQuoteTap,
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
                                message.replyTo!.body ??
                                    (message.replyTo!.imageUrl != null ? '📷 Фото' : ''),
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
                    else if (message.replyToMessageId != null)
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
                    if (message.imageUrl != null)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: GestureDetector(
                          onTap: () => _openFullImage(context, message),
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

  Future<void> _save(BuildContext context) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final granted = await Gal.requestAccess();
      if (!granted) {
        messenger.showSnackBar(const SnackBar(content: Text('Нет доступа к галерее')));
        return;
      }
      final response = await http.get(Uri.parse(message.imageUrl!));
      await Gal.putImageBytes(
        response.bodyBytes,
        name: 'servicebox_${DateTime.now().millisecondsSinceEpoch}',
      );
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
      final file = File('${dir.path}/chat_share_${DateTime.now().millisecondsSinceEpoch}.jpg');
      await file.writeAsBytes(response.bodyBytes);
      await Share.shareXFiles([XFile(file.path)]);
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('Не удалось поделиться фото')));
    }
  }

  void _openMenu(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.download_rounded),
              title: const Text('Сохранить в галерею'),
              onTap: () {
                Navigator.of(ctx).pop();
                _save(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.reply_rounded),
              title: const Text('Ответить'),
              onTap: () {
                Navigator.of(ctx).pop();
                Navigator.of(context).pop();
                onReply();
              },
            ),
            ListTile(
              leading: const Icon(Icons.share_rounded),
              title: const Text('Поделиться'),
              onTap: () {
                Navigator.of(ctx).pop();
                _share(context);
              },
            ),
            if (onDelete != null)
              ListTile(
                leading: Icon(Icons.delete_outline_rounded, color: Theme.of(ctx).colorScheme.error),
                title: Text('Удалить', style: TextStyle(color: Theme.of(ctx).colorScheme.error)),
                onTap: () {
                  Navigator.of(ctx).pop();
                  Navigator.of(context).pop();
                  onDelete!();
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
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
          IconButton(
            icon: const Icon(Icons.more_vert_rounded),
            onPressed: () => _openMenu(context),
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
