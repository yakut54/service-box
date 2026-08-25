import '../models/review.dart';
import 'api_client.dart';
import 'review_repository.dart';

class ApiReviewRepository implements ReviewRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<ProductReviews> fetch(String productId) async {
    final json = await _client.get('/widget/reviews/$productId');
    return ProductReviews.fromJson(json);
  }

  @override
  Future<void> submit({
    required String productId,
    required int rating,
    String? text,
    required String customerName,
    required String customerPhone,
  }) async {
    await _client.post('/widget/reviews', {
      'product_id': productId,
      'rating': rating,
      'customer_name': customerName,
      'customer_phone': customerPhone,
      if (text != null && text.isNotEmpty) 'text': text,
    });
  }
}
