import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/models/chat_message.dart';

Map<String, dynamic> _baseJson({Object? replyQuote = #absent}) {
  final json = <String, dynamic>{
    'id': 'm1',
    'thread_id': 't1',
    'sender_type': 'customer',
    'body': 'ответ с цитатой',
    'status': 'sent',
    'created_at': '2026-10-18T12:00:00Z',
    'client_message_id': 'c1',
  };
  if (replyQuote != #absent) json['reply_quote'] = replyQuote;
  return json;
}

void main() {
  group('ChatMessage.fromJson — reply_quote', () {
    test('отрывок цитаты разбирается, когда он есть', () {
      final m = ChatMessage.fromJson(_baseJson(replyQuote: 'когда будет готов заказ'));
      expect(m.replyQuote, 'когда будет готов заказ');
    });

    test('null, когда сервер прислал null (обычный полный ответ)', () {
      final m = ChatMessage.fromJson(_baseJson(replyQuote: null));
      expect(m.replyQuote, isNull);
    });

    test('null, когда поля нет вовсе (сообщения до фичи цитат)', () {
      final m = ChatMessage.fromJson(_baseJson());
      expect(m.replyQuote, isNull);
    });
  });
}
