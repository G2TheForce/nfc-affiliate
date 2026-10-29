import 'package:flutter/material.dart';

/// Colours follow the web app (resources/css/app.css).
class Brand {
  static const primary = Color(0xFF4F46E5);
  static const cyan = Color(0xFF22D3EE);
  static const violet = Color(0xFFA855F7);
  static const night = Color(0xFF070A1A);
  static const success = Color(0xFF15803D);
  static const warning = Color(0xFFB45309);
  static const danger = Color(0xFFB91C1C);

  /// Tap card themes, same palettes as resources/js/cards/types.ts:
  /// background, accent, highlight.
  static const cardThemes = <String, (String, List<Color>)>{
    'aurora': (
      'Aurora',
      [Color(0xFF0B1026), Color(0xFF4F46E5), Color(0xFF22D3EE)],
    ),
    'violet': (
      'Violet',
      [Color(0xFF140A24), Color(0xFFA855F7), Color(0xFFF472B6)],
    ),
    'teal': ('Teal', [Color(0xFF03191C), Color(0xFF0D9488), Color(0xFF5EEAD4)]),
    'gold': ('Gold', [Color(0xFF1A1203), Color(0xFFB45309), Color(0xFFFCD34D)]),
    'graphite': (
      'Graphite',
      [Color(0xFF0A0A0A), Color(0xFF334155), Color(0xFFE2E8F0)],
    ),
    'ocean': (
      'Ocean',
      [Color(0xFF06121F), Color(0xFF2563EB), Color(0xFFA855F7)],
    ),
  };
}

ThemeData buildTheme(Brightness brightness) {
  // Fidelity keeps the brand indigo instead of Material's muted tone.
  final scheme = ColorScheme.fromSeed(
    seedColor: Brand.primary,
    brightness: brightness,
    dynamicSchemeVariant: DynamicSchemeVariant.fidelity,
  );
  final dark = brightness == Brightness.dark;
  return ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    scaffoldBackgroundColor: dark ? Brand.night : const Color(0xFFF6F7FB),
    appBarTheme: AppBarTheme(
      centerTitle: false,
      backgroundColor: dark ? Brand.night : const Color(0xFFF6F7FB),
      surfaceTintColor: Colors.transparent,
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: dark ? const Color(0xFF111528) : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(
          color: dark ? const Color(0xFF1F2440) : const Color(0xFFE2E8F0),
        ),
      ),
    ),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
      helperMaxLines: 3,
      errorMaxLines: 3,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
    ),
  );
}
