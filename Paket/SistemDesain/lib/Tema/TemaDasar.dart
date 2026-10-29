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

/// Tema modern Aplikasi Owner (D-231): turunan [BuatTema] dengan kartu besar membulat tanpa garis tepi, tombol 52dp
/// berradius 16, chip kapsul, kolom isian terisi, dan lembar bawah membulat. Warna tetap dari token, tanpa bayangan
/// dekoratif. Aplikasi Kasir tetap memakai [BuatTema] (mode padat untuk kerja berjam-jam).
ThemeData BuatTemaModern({KepadatanTipografi kepadatan = KepadatanTipografi.Nyaman}) {
  const warna = TokenWarna.bawaan;
  final dasar = BuatTema(kepadatan: kepadatan);
  final bentukTombol = RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusTombol));
  const tinggiTombol = Size(64, 52);
  return dasar.copyWith(
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: warna.permukaan,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartu)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        shape: bentukTombol,
        minimumSize: tinggiTombol,
        textStyle: dasar.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        shape: bentukTombol,
        minimumSize: tinggiTombol,
        foregroundColor: warna.brand,
        backgroundColor: warna.brandTint,
        side: BorderSide.none,
        textStyle: dasar.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        shape: bentukTombol,
        foregroundColor: warna.brand,
        textStyle: dasar.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
      ),
    ),
    chipTheme: ChipThemeData(
      shape: const StadiumBorder(),
      side: BorderSide.none,
      backgroundColor: warna.permukaan,
      selectedColor: warna.brand,
      checkmarkColor: warna.permukaan,
      labelStyle: dasar.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w700, color: warna.teksSekunder),
      secondaryLabelStyle: dasar.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w700, color: warna.permukaan),
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8, vertical: TokenJarak.jarak8),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: warna.permukaan,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(TokenJarak.radiusTombol),
        borderSide: BorderSide.none,
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(TokenJarak.radiusTombol),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(TokenJarak.radiusTombol),
        borderSide: BorderSide(color: warna.brand, width: 2),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: warna.permukaan,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartuUtama)),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: warna.permukaan,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(TokenJarak.radiusKartuUtama + 2)),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: warna.brandGelap,
      contentTextStyle: dasar.textTheme.bodyMedium?.copyWith(color: warna.permukaan),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusTombol)),
    ),
    appBarTheme: AppBarTheme(
      backgroundColor: warna.latar,
      foregroundColor: warna.teksUtama,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: dasar.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, color: warna.teksUtama),
    ),
    listTileTheme: ListTileThemeData(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusTombol)),
    ),
  );
}
