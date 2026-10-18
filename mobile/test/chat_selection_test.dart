import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers/chat_test_harness.dart';

/// Аппаратная/жестовая кнопка "Назад" Android — та же нотификация `popRoute`,
/// что в реальном приложении доходит до PopScope (см. WidgetsBinding.handlePopRoute,
/// @visibleForTesting — штатный способ дёрнуть её из теста, без реального канала).
Future<void> simulateSystemBack(WidgetTester tester) async {
  await tester.binding.handlePopRoute();
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('экран чата поднимается и показывает сообщения', (tester) async {
    await pumpChatScreen(
      tester,
      messages: [
        buildMessage(id: 'm1', body: 'Ку', isMine: false),
        buildMessage(id: 'm2', body: 'тест', isMine: true),
      ],
    );

    expect(find.text('Ку'), findsOneWidget);
    expect(find.text('тест'), findsOneWidget);
  });

  testWidgets('первое долгое нажатие выбирает пузырь (множественный выбор)', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

    await tester.longPress(find.text('тест'));
    await tester.pumpAndSettle();

    expect(find.text('Выбрано 1'), findsOneWidget);
  });

  testWidgets(
    'второе долгое нажатие на уже отмеченном пузыре сразу выделяет слово и показывает меню',
    (tester) async {
      await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();
      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();

      expect(find.text('Копировать'), findsOneWidget);
      expect(find.text('Цитировать'), findsOneWidget);
    },
  );

  testWidgets(
    'выделение текста работает и на втором отмеченном пузыре (не только когда отмечен один)',
    (tester) async {
      await pumpChatScreen(
        tester,
        messages: [
          buildMessage(id: 'm1', body: 'Ку', isMine: false),
          buildMessage(id: 'm2', body: 'тест', isMine: true),
        ],
      );

      await tester.longPress(find.text('Ку'));
      await tester.pumpAndSettle();
      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();
      expect(find.text('Выбрано 2'), findsOneWidget);

      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();

      expect(find.text('Копировать'), findsOneWidget);
    },
  );

  testWidgets('«Цитировать» переносит выделенный отрывок в композер', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'привет мир')]);

    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Цитировать'));
    await tester.pumpAndSettle();

    expect(find.text('Цитата'), findsOneWidget);
    expect(find.text('Ответ на сообщение'), findsNothing);
  });

  testWidgets('«Копировать» кладёт в буфер обмена ровно выделенный отрывок', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'привет мир')]);

    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Копировать'));
    await tester.pumpAndSettle();

    expect(lastClipboardText, isNotNull);
    expect(lastClipboardText, isNot('привет мир')); // отрывок — слово, не вся фраза
    expect('привет мир', contains(lastClipboardText!));
  });

  testWidgets(
    'выделение на последнем сообщении не перекрывается полем ввода',
    (tester) async {
      await pumpChatScreen(
        tester,
        messages: [buildMessage(id: 'm1', body: 'самое нижнее сообщение в ленте')],
      );

      await tester.longPress(find.text('самое нижнее сообщение в ленте'));
      await tester.pumpAndSettle();
      await tester.longPress(find.text('самое нижнее сообщение в ленте'));
      await tester.pumpAndSettle();

      final bubbleRect = tester.getRect(find.text('самое нижнее сообщение в ленте'));
      final composerRect = tester.getRect(find.byType(TextField).first);

      expect(bubbleRect.bottom, lessThanOrEqualTo(composerRect.top));
    },
  );

  testWidgets('кнопка "Назад" снимает режимы поэтапно: выделение → выбор → выход', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

    await tester.longPress(find.text('тест'));
    await tester.pumpAndSettle();
    await tester.longPress(find.text('тест'));
    await tester.pumpAndSettle();
    expect(find.text('Копировать'), findsOneWidget);

    // 1-й "Назад" — снимает только текстовое выделение.
    await simulateSystemBack(tester);
    expect(find.text('Копировать'), findsNothing);
    expect(find.text('Выбрано 1'), findsOneWidget);

    // 2-й "Назад" — снимает множественный выбор.
    await simulateSystemBack(tester);
    expect(find.text('Выбрано 1'), findsNothing);
  });

  testWidgets('на неотмеченном пузыре долгое нажатие по-прежнему просто выбирает его', (tester) async {
    await pumpChatScreen(
      tester,
      messages: [
        buildMessage(id: 'm1', body: 'первое'),
        buildMessage(id: 'm2', body: 'второе'),
      ],
    );

    await tester.longPress(find.text('первое'));
    await tester.pumpAndSettle();

    // Обычный тап по ВТОРОМУ (ещё не отмеченному) пузырю — тоггл выбора,
    // не вход в выделение текста и не открытие/ответ.
    await tester.tap(find.text('второе'));
    await tester.pumpAndSettle();

    expect(find.text('Выбрано 2'), findsOneWidget);
    expect(find.text('Копировать в меню выделения'), findsNothing);
  });
}
