import 'package:flutter_test/flutter_test.dart';

import 'helpers/chat_test_harness.dart';

/// Живой разбор 2026-10-19 (скриншот из мобильного приложения) — сообщение
/// отредактировали, а потом удалили. deletedAt не трогает editedAt (это
/// разные факты, оба остаются в БД — специально, см. спеку удаления
/// сообщений), но бабл показывал одновременно "Сообщение удалено" И
/// "изменено" под ним — выглядит как противоречие. Веб-админка (ChatView.vue
/// messageStatusLabel()) уже решала это раньше: "удалено" вытесняет
/// "изменено". Мобильное приложение показывало оба — этот тест закрепляет,
/// что теперь оно ведёт себя так же, как веб.
void main() {
  testWidgets('удалённое сообщение не показывает пометку "изменено", даже если оно было отредактировано', (tester) async {
    await pumpChatScreen(
      tester,
      messages: [
        buildMessage(
          id: 'm1',
          body: 'тест',
          isMine: true,
          editedAt: DateTime(2026, 10, 19, 17, 40),
          deletedAt: DateTime(2026, 10, 19, 17, 44),
        ),
      ],
    );

    expect(find.text('Сообщение удалено'), findsOneWidget);
    expect(find.text('изменено'), findsNothing);
  });

  testWidgets('живое (не удалённое) отредактированное сообщение по-прежнему показывает "изменено"', (tester) async {
    await pumpChatScreen(
      tester,
      messages: [
        buildMessage(
          id: 'm1',
          body: 'тест 2',
          isMine: true,
          editedAt: DateTime(2026, 10, 19, 9, 52),
        ),
      ],
    );

    expect(find.text('изменено'), findsOneWidget);
  });
}
