import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// v3.54 K-4 (§17.2.7 "ingatan kerja"): keranjang yang belum dibayar selamat bila aplikasi tertutup. Isi keranjang
/// tersimpan di SQLite, dipulihkan setelah aplikasi dibuka & kasir masuk lagi, dan draf dihapus setelah dibayar.
void main() {
  Future<void> Masuk(WidgetTester tester, LingkunganUji u) async {
    await PasangAplikasi(tester, u);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
  }

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  Future<String?> Draf(WidgetTester tester, LingkunganUji u) =>
      tester.runAsync(() => u.repositori.AmbilPengaturan(PengaturKeranjang.kunciDraf)).then((v) => v);

  testWidgets('keranjang dipulihkan setelah aplikasi dibuka ulang. Draf dihapus setelah dibayar', (tester) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      throw http.ClientException('offline');
    };

    await Masuk(tester, u);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, Ubin('Americano Panas'));
    expect(find.text('Keranjang | 2 item'), findsOneWidget);
    // Simpan berjeda 300 ms; tunggu sampai tertulis.
    await tester.pump(const Duration(milliseconds: 400));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    expect(await Draf(tester, u), contains('Americano'));

    // Aplikasi "ditutup" lalu dibuka lagi dengan basis data yang sama.
    await tester.pumpWidget(const SizedBox.shrink());
    await Masuk(tester, u);
    expect(find.text('Keranjang | 2 item'), findsOneWidget, reason: 'Keranjang dipulihkan dari draf SQLite.');

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    await Ketuk(tester, find.text('Transaksi baru'));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    expect(await Draf(tester, u), isNull, reason: 'Draf dihapus setelah transaksi selesai.');
    await Lepas(tester, u);
  });
}
