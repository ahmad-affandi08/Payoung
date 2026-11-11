import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../test/Pendukung/PasangPemilik.dart';
import 'DataDemoPemilik.dart';

/// Penghasil foto produk untuk situs pemasaran `payoung.id` (bukan test regresi; di luar `test/` agar tidak ikut CI):
/// Beranda aplikasi Pemilik asli di ukuran HP dengan data contoh dua outlet kafe.
///
/// ```
/// cd Aplikasi/Pemilik && flutter test AlatSitus/FotoSitus_test.dart --update-goldens
/// ```
Future<void> MuatFont() async {
  const berkas = {
    'Lato': ['Lato-Regular.ttf', 'Lato-Bold.ttf', 'Lato-Italic.ttf'],
  };
  for (final keluarga in berkas.entries) {
    final pemuat = FontLoader('packages/sistem_desain/${keluarga.key}');
    for (final nama in keluarga.value) {
      pemuat.addFont(rootBundle.load('packages/sistem_desain/assets/fonts/$nama').then((b) => b.buffer.asByteData()));
    }
    await pemuat.load();
  }
  final ikon = FontLoader('MaterialIcons')
    ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf').then((b) => b.buffer.asByteData()));
  await ikon.load();
}

void main() {
  testWidgets('foto Beranda aplikasi Pemilik untuk situs (390 × 844)', (tester) async {
    await MuatFont();
    final server = ServerTiruan();
    final sesi = PenyimpanSesiMemori();
    server.penangan = (http.Request p) async {
      final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
      return switch (jalur) {
        'masuk' => JsonUji({
          'Token': '5|contoh',
          'Pengguna': {'Uuid': 'U1', 'Nama': 'Bu Sari', 'Email': 'sari@contoh.id'},
          'Tenant': [
            {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
          ],
        }),
        'profil' => JsonUji({
          'Pengguna': {'Nama': 'Bu Sari'},
          'Tenant': [
            {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
          ],
        }),
        'dasbor' => JsonUji(DasborDemo()),
        _ => JsonUji({}, 404),
      };
    };
    await PasangPemilik(tester, server: server, sesi: sesi, ukuran: const Size(390, 844));
    await tester.enterText(find.widgetWithText(TextField, 'Email'), 'sari@contoh.id');
    await tester.enterText(find.widgetWithText(TextField, 'Kata sandi'), 'rahasia123');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pumpAndSettle();
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('Hasil/PemilikBeranda.png'));
  });
}
