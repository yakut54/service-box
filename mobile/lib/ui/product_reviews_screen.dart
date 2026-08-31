import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/profile_repository.dart';
import '../data/review_repository.dart';
import '../models/product.dart';
import '../models/review.dart';
import '../state/auth_state.dart';
import 'phone_login_screen.dart';
import 'widgets/error_view.dart';
import 'widgets/star_rating.dart';

/// Отзывы на товар — отдельный экран (был инлайн-блоком на странице товара,
/// см. ProductRatingAskRow — теперь там только компактная строка «рейтинг +
/// Спросить», по образцу Ozon/Wildberries: полный список открывается тапом,
/// не разворачивается на месте). Логика загрузки/отправки — как раньше,
/// один осознанный отход от веб-виджета: отправка требует входа по телефону
/// (в вебе — анонимно), включает уже существующую, но на вебе не работающую
/// серверную проверку «один отзыв на товар от одного покупателя» (см.
/// ReviewController::widgetStore). Подробности — в PLAN.md.
///
/// "Уже отзывался" — не локальная догадка, а my_review из ответа сервера
/// (см. ReviewController::findMyReview), полученный по X-Phone-Session.
/// Переживает переустановку приложения и honestly отражает is_published.
enum _ReviewSort {
  newest('Сначала новые'),
  highestFirst('Сначала с высоким рейтингом'),
  lowestFirst('Сначала с низким рейтингом');

  final String label;
  const _ReviewSort(this.label);
}

class ProductReviewsScreen extends StatefulWidget {
  final Product product;

  const ProductReviewsScreen({super.key, required this.product});

  @override
  State<ProductReviewsScreen> createState() => _ProductReviewsScreenState();
}

class _ProductReviewsScreenState extends State<ProductReviewsScreen> {
  final _repository = ReviewRepository.create();

  ProductReviews? _data;
  bool _loading = true;
  AppException? _listError;

  bool _formOpen = false;
  bool _justSubmitted = false;
  _ReviewSort _sort = _ReviewSort.newest;

  final _nameController = TextEditingController();
  final _textController = TextEditingController();
  int _rating = 0;
  bool _submitting = false;
  AppException? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _textController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _listError = null;
    });
    final session = context.read<AuthState>().session;
    try {
      final data = await _repository.fetch(
        widget.product.id,
        sessionToken: session?.sessionToken,
      );
      if (mounted) setState(() => _data = data);
    } on AppException catch (e) {
      if (mounted) setState(() => _listError = e);
    } catch (_) {
      if (mounted) setState(() => _listError = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openForm() async {
    final auth = context.read<AuthState>();
    if (!auth.isLoggedIn) {
      // Тот же приём, что ChatButton — вход по телефону, если ещё не
      // входили. После OTP покупатель попадает на корень каталога, а не
      // обратно сюда — принятое для v1 ограничение, не переделываем флоу
      // входа ради этого.
      await Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const PhoneLoginScreen()),
      );
      return;
    }
    setState(() => _formOpen = true);
    _prefillName();
  }

  Future<void> _prefillName() async {
    if (_nameController.text.isNotEmpty) return;
    final session = context.read<AuthState>().session;
    if (session == null) return;
    try {
      final profile = await ProfileRepository.create().fetch(
        session.sessionToken,
      );
      if (mounted && _nameController.text.isEmpty) {
        _nameController.text = profile.name;
      }
    } catch (_) {
      // Не удалось — просто пустое поле, не критично.
    }
  }

  void _cancelForm() {
    setState(() {
      _formOpen = false;
      _formError = null;
    });
  }

  bool get _canSubmit => _nameController.text.trim().isNotEmpty && _rating >= 1;

  /// Индекс в исходном списке — тай-брейкер, чтобы сортировка по рейтингу
  /// не перемешивала отзывы с одинаковой оценкой (List.sort в Dart не
  /// гарантирует стабильность). Список от сервера уже отсортирован по дате
  /// (новые сверху), поэтому индекс исходного списка одновременно и есть
  /// "по дате" — второй сортировки не нужно.
  List<Review> _sortedReviews(List<Review> items) {
    final indexed = items.asMap().entries.toList();
    indexed.sort((a, b) {
      switch (_sort) {
        case _ReviewSort.newest:
          return a.key.compareTo(b.key);
        case _ReviewSort.highestFirst:
          final byRating = b.value.rating.compareTo(a.value.rating);
          return byRating != 0 ? byRating : a.key.compareTo(b.key);
        case _ReviewSort.lowestFirst:
          final byRating = a.value.rating.compareTo(b.value.rating);
          return byRating != 0 ? byRating : a.key.compareTo(b.key);
      }
    });
    return indexed.map((e) => e.value).toList();
  }

  Future<void> _pickSort() async {
    final selected = await showModalBottomSheet<_ReviewSort>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final sort in _ReviewSort.values)
              ListTile(
                title: Text(sort.label),
                trailing: sort == _sort ? const Icon(Icons.check_rounded) : null,
                onTap: () => Navigator.of(ctx).pop(sort),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (selected != null && mounted) setState(() => _sort = selected);
  }

  Future<void> _submit() async {
    final session = context.read<AuthState>().session;
    if (session == null || !_canSubmit) return;

    setState(() {
      _submitting = true;
      _formError = null;
    });
    try {
      await _repository.submit(
        productId: widget.product.id,
        rating: _rating,
        text: _textController.text.trim().isEmpty
            ? null
            : _textController.text.trim(),
        customerName: _nameController.text.trim(),
        customerPhone: session.phone,
        sessionToken: session.sessionToken,
      );
      if (!mounted) return;
      setState(() {
        _justSubmitted = true;
        _formOpen = false;
        _nameController.clear();
        _textController.clear();
        _rating = 0;
      });
      // Перезапрос — my_review в ответе теперь отразит только что созданный
      // отзыв, это и есть источник правды "уже отзывался", а не отдельный
      // локальный флаг.
      unawaited(_load());
    } on AppException catch (e) {
      if (mounted) setState(() => _formError = e);
    } catch (_) {
      if (mounted) setState(() => _formError = AppException.unknown());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Отзывы')),
      body: SafeArea(child: _buildBody(context)),
    );
  }

  Widget _buildBody(BuildContext context) {
    final data = _data;

    if (_loading && data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_listError != null && data == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _listError!, onRetry: _load),
        ),
      );
    }
    if (data == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final myReview = data.myReview;
    final showButton = !_formOpen && !_justSubmitted && myReview == null;
    final sorted = _sortedReviews(data.items);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (data.stats.average != null) ...[
            Row(
              children: [
                Text(
                  data.stats.average!.toStringAsFixed(1).replaceAll('.', ','),
                  style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(width: 10),
                StarRating(value: data.stats.average!, size: 20),
                const SizedBox(width: 10),
                Text(
                  '${formatCount(data.stats.count)} ${pluralRu(data.stats.count, 'отзыв', 'отзыва', 'отзывов')}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
          ],
          if (showButton)
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(onPressed: _openForm, child: const Text('Оставить отзыв')),
            ),
          if (_justSubmitted) ...[
            const SizedBox(height: 12),
            _InfoBanner(
              icon: Icons.check_circle_outline_rounded,
              text: 'Спасибо! Отзыв отправлен на модерацию.',
              color: theme.colorScheme.primary,
            ),
          ] else if (myReview != null && !myReview.isPublished) ...[
            const SizedBox(height: 12),
            _InfoBanner(
              icon: Icons.hourglass_top_rounded,
              text: 'Вы уже оставили отзыв. Он появится после проверки.',
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
          if (_formOpen) ...[
            const SizedBox(height: 12),
            _ReviewForm(
              nameController: _nameController,
              textController: _textController,
              rating: _rating,
              onRatingChanged: (r) => setState(() => _rating = r),
              canSubmit: _canSubmit,
              submitting: _submitting,
              error: _formError,
              onCancel: _cancelForm,
              onSubmit: _submit,
              // Форма зависит от текста в контроллерах для активации кнопки —
              // простой способ перерисоваться без отдельного стейта на каждый
              // символ.
              onFieldChanged: () => setState(() {}),
            ),
          ],
          const SizedBox(height: 16),
          if (data.items.isEmpty && !_formOpen)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                'Отзывов пока нет. Будьте первым!',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            )
          else if (data.items.isNotEmpty) ...[
            if (sorted.length > 1)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: _pickSort,
                  icon: const Icon(Icons.sort_rounded, size: 18),
                  label: Text(_sort.label),
                ),
              ),
            for (final review in sorted) ...[
              _ReviewCard(review: review),
              const Divider(height: 1),
            ],
          ],
        ],
      ),
    );
  }
}

class _InfoBanner extends StatelessWidget {
  final IconData icon;
  final String text;
  final Color color;

  const _InfoBanner({required this.icon, required this.text, required this.color});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 18, color: color),
        const SizedBox(width: 8),
        Expanded(
          child: Text(text, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color)),
        ),
      ],
    );
  }
}

class _ReviewForm extends StatelessWidget {
  final TextEditingController nameController;
  final TextEditingController textController;
  final int rating;
  final ValueChanged<int> onRatingChanged;
  final bool canSubmit;
  final bool submitting;
  final AppException? error;
  final VoidCallback onCancel;
  final VoidCallback onSubmit;
  final VoidCallback onFieldChanged;

  const _ReviewForm({
    required this.nameController,
    required this.textController,
    required this.rating,
    required this.onRatingChanged,
    required this.canSubmit,
    required this.submitting,
    required this.error,
    required this.onCancel,
    required this.onSubmit,
    required this.onFieldChanged,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Ваша оценка *', style: theme.textTheme.labelMedium),
          const SizedBox(height: 4),
          StarRating(value: rating.toDouble(), size: 28, onRated: onRatingChanged),
          const SizedBox(height: 8),
          TextFormField(
            controller: nameController,
            maxLength: 100,
            decoration: const InputDecoration(labelText: 'Ваше имя *'),
            onChanged: (_) => onFieldChanged(),
          ),
          TextFormField(
            controller: textController,
            maxLength: 2000,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Отзыв',
              hintText: 'Поделитесь впечатлениями...',
            ),
          ),
          const SizedBox(height: 24),
          if (error != null) ...[
            Text(
              error!.message,
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
            ),
            const SizedBox(height: 4),
          ],
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(onPressed: submitting ? null : onCancel, child: const Text('Отмена')),
              const SizedBox(width: 8),
              // IntrinsicWidth — тема задаёт FilledButton.minimumSize через
              // Size.fromHeight(52), а это значит "ширина = infinity" (для
              // кнопок на всю ширину экрана вроде оформления заказа). Внутри
              // Row это ломает рендер кнопки насмерть без ошибки в release
              // (баг найден 2026-08-25 живым тестом — кнопка отправки отзыва
              // просто не рисовалась). Тот же приём уже есть в
              // promo_code_field.dart для этой же ситуации.
              IntrinsicWidth(
                child: FilledButton(
                  onPressed: (canSubmit && !submitting) ? onSubmit : null,
                  child: submitting
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Отправить'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ReviewCard extends StatelessWidget {
  final Review review;

  const _ReviewCard({required this.review});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initial = review.customerName.isNotEmpty
        ? review.customerName.substring(0, 1).toUpperCase()
        : '?';

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 16,
            backgroundColor: theme.colorScheme.primaryContainer,
            child: Text(
              initial,
              style: TextStyle(color: theme.colorScheme.onPrimaryContainer),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      review.customerName,
                      style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                    ),
                    if (review.createdAt != null)
                      Text(
                        formatFullDate(review.createdAt!),
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 2),
                StarRating(value: review.rating.toDouble(), size: 14),
                if (review.text != null && review.text!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(review.text!, style: theme.textTheme.bodyMedium),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
