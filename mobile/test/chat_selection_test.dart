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

/// `tester.longPress()` в `flutter_test` не может честно сымитировать «попади
/// пальцем ровно в слово» внутри sliver-based списка (ListView/ListView.builder)
/// — проверено отдельно серией изолированных репродукций: даже чистый
/// `SelectionArea`/`SelectableText` внутри голого `ListView` не получает от
/// framework реальной (несхлопнутой) геометрии выделения по синтетическому
/// long press, хотя на реальном телефоне (см. отчёт пользователя — капли
/// появились на 3-м нажатии старого кода) слово выделяется. Это ограничение
/// тестового раннера для точечного попадания по координате, а не баг кода:
/// `selectAll()` — тот же публичный API Flutter, которым реально пользуется
/// плавающее меню — внутри ТОГО ЖЕ ListView работает штатно. Поэтому ниже
/// выделение слова эмулируется через selectAll() на найденном SelectableRegion,
/// а не через вторую точку longPress — это честно проверяет ВЕСЬ наш код
/// (меню, цитату, буфер обмена, автопрокрутку, "Назад"), не трогая то, что
/// framework и так гарантирует сам.
Future<void> selectAllInBubble(WidgetTester tester, String text) async {
  final region = find.ancestor(
    of: find.text(text),
    matching: find.byType(SelectableRegion),
  );
  tester.state<SelectableRegionState>(region).selectAll(SelectionChangedCause.toolbar);
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
    'как только пузырь отмечен — его текст сразу оборачивается в SelectionArea '
    '(значит, долгое нажатие на нём больше не "взводит" режим, а сразу уходит '
    'нативному выделению слова — без нужды в третьем нажатии, как было раньше)',
    (tester) async {
      await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

      expect(find.byType(SelectionArea), findsNothing);
      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();

      expect(find.byType(SelectionArea), findsOneWidget);
    },
  );

  testWidgets('выделение слова показывает меню "Копировать"/"Цитировать" над пузырём', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);
    await tester.longPress(find.text('тест'));
    await tester.pumpAndSettle();

    await selectAllInBubble(tester, 'тест');

    expect(find.text('Копировать'), findsOneWidget);
    expect(find.text('Цитировать'), findsOneWidget);
  });

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

      // Оба пузыря отмечены — у ОБОИХ должен быть свой SelectionArea, и
      // выделение должно уметь начаться на любом из них, не только когда
      // отмечен единственный (это и был баг №4 из отчёта).
      expect(find.byType(SelectionArea), findsNWidgets(2));
      await selectAllInBubble(tester, 'тест');
      expect(find.text('Копировать'), findsOneWidget);
    },
  );

  testWidgets('«Цитировать» переносит выделенный отрывок в композер', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'привет мир')]);
    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await selectAllInBubble(tester, 'привет мир');

    await tester.tap(find.text('Цитировать'));
    await tester.pumpAndSettle();

    expect(find.text('Цитата'), findsOneWidget);
    expect(find.text('Ответ на сообщение'), findsNothing);
  });

  testWidgets('«Копировать» кладёт в буфер обмена ровно выделенный отрывок', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'привет мир')]);
    await tester.longPress(find.text('привет мир'));
    await tester.pumpAndSettle();
    await selectAllInBubble(tester, 'привет мир');

    await tester.tap(find.text('Копировать'));
    await tester.pumpAndSettle();

    expect(lastClipboardText, 'привет мир');
  });

  testWidgets(
    'выделение на последнем сообщении подкручивает бабл, чтобы он не перекрывался полем ввода',
    (tester) async {
      await pumpChatScreen(
        tester,
        messages: [buildMessage(id: 'm1', body: 'самое нижнее сообщение в ленте')],
      );

      await tester.longPress(find.text('самое нижнее сообщение в ленте'));
      await tester.pumpAndSettle();
      await selectAllInBubble(tester, 'самое нижнее сообщение в ленте');

      final bubbleRect = tester.getRect(find.text('самое нижнее сообщение в ленте'));
      final composerRect = tester.getRect(find.byType(TextField).first);

      expect(bubbleRect.bottom, lessThanOrEqualTo(composerRect.top));
    },
  );

  testWidgets('кнопка "Назад" снимает режимы поэтапно: выделение → выбор → выход', (tester) async {
    await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

    await tester.longPress(find.text('тест'));
    await tester.pumpAndSettle();
    await selectAllInBubble(tester, 'тест');
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
  });

  testWidgets(
    'короткий тап по тексту УЖЕ отмеченного пузыря снимает галочку '
    '(SelectionArea своим тапом не должна глушить тоггл)',
    (tester) async {
      await pumpChatScreen(tester, messages: [buildMessage(id: 'm1', body: 'тест')]);

      await tester.longPress(find.text('тест'));
      await tester.pumpAndSettle();
      expect(find.text('Выбрано 1'), findsOneWidget);

      await tester.tap(find.text('тест'));
      await tester.pumpAndSettle();

      expect(find.text('Выбрано 1'), findsNothing);
    },
  );

  testWidgets(
    'выделение на сообщении, которое НЕ у самого низа ленты — лента не трогается',
    (tester) async {
      final messages = [
        for (var i = 0; i < 25; i++) buildMessage(id: 'm$i', body: 'сообщение номер $i'),
      ];
      await pumpChatScreen(tester, messages: messages);

      // Самое старое сообщение (номер 24 — см. buildMessage/порядок в
      // FakeChatRepository) оказывается НАВЕРХУ reverse-ленты — прокручиваем
      // туда штатным хелпером (ListView.builder ленивый, обычный drag может
      // не успеть построить нужный элемент).
      await tester.scrollUntilVisible(
        find.text('сообщение номер 24'),
        200.0,
        scrollable: find.byType(Scrollable).first,
      );
      final before = tester.state<ScrollableState>(find.byType(Scrollable).first).position.pixels;

      await tester.longPress(find.text('сообщение номер 24'));
      await tester.pumpAndSettle();
      await selectAllInBubble(tester, 'сообщение номер 24');

      final after = tester.state<ScrollableState>(find.byType(Scrollable).first).position.pixels;
      expect(after, before);
    },
  );
}
