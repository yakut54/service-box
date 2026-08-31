import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/auth_state.dart';
import '../../state/chat_state.dart';
import '../chat_screen.dart';
import '../phone_login_screen.dart';

/// Открыть чат — если байер не вошёл, сперва просит войти по телефону
/// (переписка привязана к сессии). Общая точка входа для иконки чата в
/// шапке (ChatButton ниже) и для «Спросить» на странице товара
/// (ProductRatingAskRow) — раньше гейт был вписан прямо в ChatButton,
/// вынесено, чтобы не плодить третью копию той же проверки.
void openChat(BuildContext context, {String? initialDraft}) {
  final auth = context.read<AuthState>();
  Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => auth.isLoggedIn
          ? ChatScreen(initialDraft: initialDraft)
          : const PhoneLoginScreen(),
    ),
  );
}

/// Иконка чата с бейджем непрочитанных — если байер не вошёл, сперва просит
/// войти по телефону (переписка привязана к сессии), как и AccountButton.
/// Сама включает/выключает опрос бейджа в ChatState при входе/выходе —
/// единственное место в дереве, которое видит и AuthState, и ChatState.
class ChatButton extends StatefulWidget {
  const ChatButton({super.key});

  @override
  State<ChatButton> createState() => _ChatButtonState();
}

class _ChatButtonState extends State<ChatButton> {
  bool? _wasLoggedIn;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final chat = context.watch<ChatState>();

    if (_wasLoggedIn != auth.isLoggedIn) {
      _wasLoggedIn = auth.isLoggedIn;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        if (auth.isLoggedIn) {
          chat.startPolling(auth.session!.sessionToken);
        } else {
          chat.stopPolling();
        }
      });
    }

    final unread = auth.isLoggedIn ? chat.unreadTotal : 0;

    return Stack(
      alignment: Alignment.center,
      children: [
        IconButton(
          icon: const Icon(Icons.chat_bubble_outline_rounded),
          onPressed: () => openChat(context),
        ),
        if (unread > 0)
          Positioned(
            right: 6,
            top: 6,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.error,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                unread > 99 ? '99+' : '$unread',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: Theme.of(context).colorScheme.onError,
                ),
              ),
            ),
          ),
      ],
    );
  }
}
