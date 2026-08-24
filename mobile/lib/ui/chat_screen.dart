import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/image_compress.dart';
import '../core/uuid.dart';
import '../data/chat_realtime_client.dart';
import '../data/chat_repository.dart';
import '../models/chat_message.dart';
import '../state/auth_state.dart';
import '../state/chat_state.dart';
import 'widgets/app_dialog.dart';
import 'widgets/error_view.dart';

/// Диалог байера с магазином — один тред, лента снизу вверх, отправка
/// текста и фото. Доставка — WebSocket (Reverb) как основной путь, обычный
/// опрос остаётся редкой подстраховкой на случай обрыва сокета (см.
/// PLAN-CHAT.md §12).
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key});

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
  AppException? _error;
  Timer? _pollTimer;
  bool _sending = false;
  Uint8ListHolder? _pendingImage;
  bool _uploadingImage = false;
  ChatMessage? _replyTarget;
  String? _threadId;

  String get _sessionToken => context.read<AuthState>().session!.sessionToken;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _scrollController.addListener(_onScroll);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    _realtime.disconnect();
    _scrollController.dispose();
    _draftController.dispose();
    _player.dispose();
    super.dispose();
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
        if (event == 'message.new') {
          final raw = data['message'] as Map<String, dynamic>?;
          if (raw != null && raw['sender_type'] == 'shop') {
            _playNotifySound();
          }
        }
        _poll();
      },
    );
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
        _scrollToBottom();
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

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      _scrollController.jumpTo(_scrollController.position.maxScrollExtent);
    });
  }

  void _onScroll() {
    if (!_scrollController.hasClients || _loadingOlder || !_hasMoreOlder) {
      return;
    }
    if (_scrollController.position.pixels <= 60) {
      _loadOlder();
    }
  }

  Future<void> _loadOlder() async {
    if (_messages.isEmpty) return;
    setState(() => _loadingOlder = true);
    try {
      final oldest = _messages.first;
      final page = await _repository.fetchMessages(
        _sessionToken,
        before: oldest.id,
      );
      if (page.messages.length < 30) _hasMoreOlder = false;
      final older = page.messages.reversed.toList();
      final prevExtent = _scrollController.position.maxScrollExtent;
      setState(() => _messages = [...older, ..._messages]);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!_scrollController.hasClients) return;
        final newExtent = _scrollController.position.maxScrollExtent;
        _scrollController.jumpTo(newExtent - prevExtent);
      });
    } catch (_) {
      // тихо — попробует ещё раз при следующем скролле к верху
    } finally {
      if (mounted) setState(() => _loadingOlder = false);
    }
  }

  Future<void> _pickImage() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Сделать фото'),
              onTap: () => Navigator.of(ctx).pop(ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Выбрать из галереи'),
              onTap: () => Navigator.of(ctx).pop(ImageSource.gallery),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;

    final picked = await ImagePicker().pickImage(source: source);
    if (picked == null || !mounted) return;

    setState(() => _uploadingImage = true);
    try {
      final bytes = await picked.readAsBytes();
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
      _scrollToBottom();
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
    return Scaffold(
      appBar: AppBar(title: const Text('Чат с магазином')),
      body: SafeArea(child: _buildBody()),
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
                    return _MessageBubble(
                      message: message,
                      onLongPress: () => _openMessageMenu(message),
                      onSwipeReply: () => _startReply(message),
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
    return SafeArea(
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
  final VoidCallback onLongPress;
  final VoidCallback onSwipeReply;

  const _MessageBubble({
    required this.message,
    required this.onLongPress,
    required this.onSwipeReply,
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
                alignment: Alignment.centerRight,
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
              child: Container(
                constraints: BoxConstraints(
                  minWidth: MediaQuery.of(context).size.width * 0.5,
                  maxWidth: MediaQuery.of(context).size.width * 0.75,
                ),
                margin: const EdgeInsets.symmetric(vertical: 3),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: isMine
                      ? theme.colorScheme.primary
                      : theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.only(
                    topLeft: const Radius.circular(14),
                    topRight: const Radius.circular(14),
                    bottomLeft: Radius.circular(isMine ? 14 : 4),
                    bottomRight: Radius.circular(isMine ? 4 : 14),
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (message.replyTo != null)
                      Container(
                        margin: const EdgeInsets.only(bottom: 6),
                        padding: const EdgeInsets.only(left: 8),
                        decoration: BoxDecoration(
                          border: Border(
                            left: BorderSide(
                              color: isMine
                                  ? theme.colorScheme.onPrimary.withValues(alpha: 0.5)
                                  : theme.colorScheme.primary,
                              width: 2,
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
                          onTap: () => _openFullImage(context, message.imageUrl!),
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
        ],
      ),
    );
  }

  void _openFullImage(BuildContext context, String url) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(backgroundColor: Colors.black),
          backgroundColor: Colors.black,
          body: Center(child: InteractiveViewer(child: Image.network(url))),
        ),
      ),
    );
  }
}
