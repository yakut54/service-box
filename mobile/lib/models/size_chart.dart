/// Размерная сетка товара (вложена в /widget/products/{id} как size_chart).
/// columns — заголовки, rows — строки, все значения строками. Показывается
/// в SizeChartSheet по кнопке «Таблица размеров» на карточке товара.
class SizeChart {
  final String kind; // clothing | shoes | custom
  final String name;
  final List<String> columns;
  final List<List<String>> rows;

  const SizeChart({
    required this.kind,
    required this.name,
    required this.columns,
    required this.rows,
  });

  factory SizeChart.fromJson(Map<String, dynamic> json) => SizeChart(
    kind: json['kind'] as String? ?? 'custom',
    name: (json['name'] as String?)?.trim().isNotEmpty == true
        ? (json['name'] as String).trim()
        : 'Таблица размеров',
    columns: (json['columns'] as List<dynamic>? ?? const [])
        .map((e) => e.toString())
        .toList(),
    rows: (json['rows'] as List<dynamic>? ?? const [])
        .map(
          (r) => (r as List<dynamic>? ?? const []).map((e) => e.toString()).toList(),
        )
        .toList(),
  );

  /// Есть что показывать — хотя бы заголовки и одна строка.
  bool get isUsable => columns.isNotEmpty && rows.isNotEmpty;
}
