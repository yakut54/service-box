import 'package:flutter/material.dart';

import '../../models/size_chart.dart';

/// Таблица размеров в нижней шторке — открывается кнопкой «Таблица размеров»
/// на карточке товара (Baymard: ссылка рядом с селектором размера).
class SizeChartSheet extends StatelessWidget {
  final SizeChart chart;

  const SizeChartSheet({super.key, required this.chart});

  static Future<void> show(BuildContext context, SizeChart chart) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => SizeChartSheet(chart: chart),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      top: false,
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.8,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Text(chart.name, style: theme.textTheme.titleMedium),
            ),
            Flexible(
              child: SingleChildScrollView(
                scrollDirection: Axis.vertical,
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
                  child: DataTable(
                    headingRowHeight: 44,
                    dataRowMinHeight: 40,
                    dataRowMaxHeight: 52,
                    columnSpacing: 24,
                    columns: [
                      for (final c in chart.columns)
                        DataColumn(
                          label: Text(
                            c,
                            style: theme.textTheme.labelMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                    ],
                    rows: [
                      for (final row in chart.rows)
                        DataRow(
                          cells: [
                            for (var i = 0; i < chart.columns.length; i++)
                              DataCell(
                                Text(
                                  i < row.length ? row[i] : '',
                                  style: theme.textTheme.bodyMedium,
                                ),
                              ),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
