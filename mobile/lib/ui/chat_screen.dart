import 'dart:async';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/image_compress.dart';
import '../core/uuid.dart';
import '../data/chat_repository.dart';
import '../models/chat_message.dart';
import '../state/auth_state.dart';
import '../state/chat_state.dart';
import 'widgets/error_view.dart';

/// Диалог байера с магазином — один тред, лента снизу вверх, отправка
/// текста и фото. Доставка через опрос (poll), не WebSocket — инфраструктуры
/// под неё нет (см. PLAN-CHAT.md §3.4/§4).
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> with WidgetsBindingObserver {
  final _repository = ChatRepository.create();
  final _scrollController = ScrollController();
  final _draftController = TextEditingController();

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
    _scrollController.dispose();
    _draftController.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _startPolling();
    } else {
      _pollTimer?.cancel();
    }
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
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
      );
      setState(() {
        _messages = [..._messages, message];
        _draftController.clear();
        _pendingImage = null;
      });
      _scrollToBottom();
    } catch (e) {
      if (mounted) _showError(e, 'Не удалось отправить сообщение');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
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
                    return _MessageBubble(message: message);
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

class _MessageBubble extends StatelessWidget {
  final ChatMessage message;

  const _MessageBubble({required this.message});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isMine = message.isMine;
    final time =
        '${message.createdAt.toLocal().hour.toString().padLeft(2, '0')}:'
        '${message.createdAt.toLocal().minute.toString().padLeft(2, '0')}';

    return Align(
      alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(
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
