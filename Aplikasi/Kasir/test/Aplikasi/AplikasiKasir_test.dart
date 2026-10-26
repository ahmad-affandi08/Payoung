import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Aplikasi/Lingkungan.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

void main() {
  testWidgets('menampilkan penanda lingkungan selain produksi. Perangkat baru membuka layar aktivasi', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u, lingkungan: Lingkungan.Staging);
    expect(find.text('Aktifkan perangkat kasir'), findsOneWidget);
    expect(find.byType(Banner), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('tanpa penanda lingkungan di produksi', (tester) async {
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u);
    expect(find.byType(Banner), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('hanya memakai tema terang walau sistem operasi dalam mode gelap (D-14)', (tester) async {
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
    final u = LingkunganUji.Buat();
    await PasangAplikasi(tester, u);

    final aplikasi = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(aplikasi.darkTheme, isNull);
    expect(aplikasi.highContrastDarkTheme, isNull);
    expect(aplikasi.themeMode, ThemeMode.light);

    final tema = Theme.of(tester.element(find.text('Aktifkan perangkat kasir')));
    expect(tema.brightness, Brightness.light);
    expect(tema.extension<TokenWarna>(), TokenWarna.bawaan);
    await Lepas(tester, u);
  });
}
