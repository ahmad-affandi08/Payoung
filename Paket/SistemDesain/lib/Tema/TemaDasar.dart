import 'package:flutter/material.dart';

import '../Token/TokenJarak.dart';
import '../Token/TokenTipografi.dart';
import '../Token/TokenWarna.dart';

/// Membuat [ThemeData] terang dari token (PRD §17.6). Aplikasi tidak menyusun tema sendiri.
///
/// Selalu terang: tidak ada mode gelap (D-14).
ThemeData BuatTema({KepadatanTipografi kepadatan = KepadatanTipografi.Nyaman}) {
  const warna = TokenWarna.bawaan;
  final skala = SkalaTipografi.AmbilUntuk(kepadatan);
  final skemaWarna = ColorScheme(
    brightness: Brightness.light,
    primary: warna.brand,
    onPrimary: warna.permukaan,
    secondary: warna.brand,
    onSecondary: warna.permukaan,
    error: warna.bahaya,
    onError: warna.permukaan,
    surface: warna.permukaan,
    onSurface: warna.teksUtama,
    onSurfaceVariant: warna.teksSekunder,
    outline: warna.garisInput,
    outlineVariant: warna.garis,
    // Tanpa rona brand di permukaan terangkat (dialog, menu): permukaan tetap netral (PRD §17.6.3 aturan 90/10).
    surfaceTint: Colors.transparent,
  );
  // Radius 6 untuk kontrol, 8 untuk panel/dialog; tanpa bayangan dekoratif (PRD §17.6.4).
  final bentukKontrol = RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol));
  final bentukPanel = RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusPanel));
  // Penanda posisi aktif navigasi: nada brand tetap di belakang ikon brand (PRD §17.6.3). Opasitas 12% terlalu
  // pucat di atas permukaan putih sehingga posisi aktif sulit ditemukan.
  final penandaAktif = warna.brandTint;
  final gayaLabel = skala.label.KeGaya();
  TextStyle GayaLabelNavigasi(bool aktif) => gayaLabel.copyWith(color: aktif ? warna.brand : warna.teksSekunder);
  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    colorScheme: skemaWarna,
    scaffoldBackgroundColor: warna.latar,
    fontFamily: fontUtama,
    package: paketFont,
    textTheme: skala.KeTemaTeks(warna.teksUtama, warna.teksSekunder),
    dividerColor: warna.garis,
    dividerTheme: DividerThemeData(color: warna.garis, thickness: TokenJarak.tebalGaris),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(shape: bentukKontrol)),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        shape: bentukKontrol,
        side: BorderSide(color: warna.garisInput),
      ),
    ),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(shape: bentukKontrol)),
    segmentedButtonTheme: SegmentedButtonThemeData(style: SegmentedButton.styleFrom(shape: bentukKontrol)),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol)),
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      color: warna.permukaan,
      shape: bentukPanel.copyWith(side: BorderSide(color: warna.garis)),
    ),
    dialogTheme: DialogThemeData(backgroundColor: warna.permukaan, shape: bentukPanel),
    navigationRailTheme: NavigationRailThemeData(
      backgroundColor: warna.permukaan,
      indicatorColor: penandaAktif,
      indicatorShape: bentukKontrol,
      selectedIconTheme: IconThemeData(color: warna.brand),
      unselectedIconTheme: IconThemeData(color: warna.teksSekunder),
      selectedLabelTextStyle: GayaLabelNavigasi(true),
      unselectedLabelTextStyle: GayaLabelNavigasi(false),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: warna.permukaan,
      elevation: 0,
      indicatorColor: penandaAktif,
      indicatorShape: bentukKontrol,
      iconTheme: WidgetStateProperty.resolveWith(
        (keadaan) => IconThemeData(color: keadaan.contains(WidgetState.selected) ? warna.brand : warna.teksSekunder),
      ),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (keadaan) => GayaLabelNavigasi(keadaan.contains(WidgetState.selected)),
      ),
    ),
    // Target sentuh minimal 48dp (PRD §17.6, .claude/rules/Flutter.md).
    materialTapTargetSize: MaterialTapTargetSize.padded,
    visualDensity: VisualDensity.standard,
    extensions: [warna],
  );
}
