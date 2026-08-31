/// Один отзыв — GET /widget/reviews/{productId} (только опубликованные,
/// см. ReviewController::widgetIndex на бэкенде).
class Review {
  final String id;
  final String customerName;
  final int rating;
  final String? text;
  final DateTime? createdAt;

  const Review({
    required this.id,
    required this.customerName,
    required this.rating,
    this.text,
    this.createdAt,
  });

  factory Review.fromJson(Map<String, dynamic> json) => Review(
    id: json['id'] as String,
    customerName: json['customer_name'] as String? ?? '',
    rating: (json['rating'] as num).toInt(),
    text: json['text'] as String?,
    createdAt: json['created_at'] != null
        ? DateTime.tryParse(json['created_at'] as String)
        : null,
  );
}

/// Сводка по товару — среднее, количество, распределение по звёздам.
///
/// `distribution` — гарантированно нормализована к `Map<int, int>` здесь же:
/// на бэкенде это PHP-массив, который при нуле отзывов сериализуется в JSON
/// как `[]` (список), а при наличии хотя бы одного — как объект вида
/// `{"1":0,"2":1,...}` (см. ReviewController::calcStats). Оба варианта нужно
/// понимать не падая, иначе виджет статистики крашится ровно на товарах без
/// отзывов — самом частом случае для нового магазина.
class ReviewStats {
  final int count;
  final double? average;
  final Map<int, int> distribution;

  const ReviewStats({
    required this.count,
    this.average,
    this.distribution = const {},
  });

  factory ReviewStats.fromJson(Map<String, dynamic> json) => ReviewStats(
    count: (json['count'] as num?)?.toInt() ?? 0,
    average: (json['average'] as num?)?.toDouble(),
    distribution: _parseDistribution(json['distribution']),
  );

  static Map<int, int> _parseDistribution(dynamic raw) {
    if (raw is! Map) return const {};
    return {
      for (final entry in raw.entries)
        int.parse(entry.key as String): (entry.value as num).toInt(),
    };
  }

  static const empty = ReviewStats(count: 0);
}

/// Свой отзыв на этот товар — приходит, только если запрос нёс валидный
/// X-Phone-Session (см. ReviewController::findMyReview на бэкенде). Заменяет
/// собой локальную догадку "уже отправлял" — сервер знает точно, включая
/// реальный статус модерации, и это переживает переустановку приложения.
class MyReview {
  final String id;
  final int rating;
  final String? text;
  final bool isPublished;
  final DateTime? createdAt;

  const MyReview({
    required this.id,
    required this.rating,
    this.text,
    required this.isPublished,
    this.createdAt,
  });

  factory MyReview.fromJson(Map<String, dynamic> json) => MyReview(
    id: json['id'] as String,
    rating: (json['rating'] as num).toInt(),
    text: json['text'] as String?,
    isPublished: json['is_published'] as bool? ?? false,
    createdAt: json['created_at'] != null
        ? DateTime.tryParse(json['created_at'] as String)
        : null,
  );
}

/// Общий ответ GET /widget/reviews/{productId} — список + сводка + свой
/// отзыв одним запросом, ProductReviewsScreen парсит их вместе.
class ProductReviews {
  final List<Review> items;
  final ReviewStats stats;
  final MyReview? myReview;

  const ProductReviews({
    required this.items,
    required this.stats,
    this.myReview,
  });

  factory ProductReviews.fromJson(Map<String, dynamic> json) {
    final list = json['data'] as List<dynamic>? ?? const [];
    return ProductReviews(
      items: list
          .map((r) => Review.fromJson(r as Map<String, dynamic>))
          .toList(),
      stats: json['stats'] != null
          ? ReviewStats.fromJson(json['stats'] as Map<String, dynamic>)
          : ReviewStats.empty,
      myReview: json['my_review'] != null
          ? MyReview.fromJson(json['my_review'] as Map<String, dynamic>)
          : null,
    );
  }

  static const empty = ProductReviews(items: [], stats: ReviewStats.empty);
}
