import 'package:intl/intl.dart';

final _grouped = NumberFormat('#,##,##0.00', 'en_IN');
final _groupedWhole = NumberFormat('#,##,##0', 'en_IN');

/// Money arrives as integer paisa (`*_minor`). Never use doubles for sums.
String money(int? minor, [String currency = 'NPR']) {
  if (minor == null) return '-';
  final negative = minor < 0;
  final abs = minor.abs();
  final text = abs % 100 == 0
      ? _groupedWhole.format(abs ~/ 100)
      : _grouped.format(abs / 100);
  final symbol = currency == 'NPR' ? 'Rs' : currency;
  return '${negative ? '-' : ''}$symbol $text';
}

/// Basis points as a percent: 1000 -> "10%", 1250 -> "12.5%".
String percentBp(int? bp) {
  if (bp == null) return '-';
  final value = bp / 100;
  return '${value == value.roundToDouble() ? value.toStringAsFixed(0) : value.toStringAsFixed(2).replaceAll(RegExp(r'0+$'), '')}%';
}

/// API timestamps are UTC; show them in the phone's local time.
DateTime? parseTime(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toLocal() : null;

String date(DateTime? value) =>
    value == null ? '-' : DateFormat('d MMM yyyy').format(value);

String dateTime(DateTime? value) =>
    value == null ? '-' : DateFormat('d MMM yyyy, h:mm a').format(value);
