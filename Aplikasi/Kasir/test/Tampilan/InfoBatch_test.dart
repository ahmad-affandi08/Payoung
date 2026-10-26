import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-19 (F-05g): info batch & kedaluwarsa saat jual. Cek harga produk ber-batch menampilkan batch di toko urut FEFO;
/// menambah ke keranjang memberi peringatan bila batch terdepan sudah/hampir kedaluwarsa (tidak memblokir); offline =
/// cukup keterangan butuh internet.
void main() {
  Map<String, Object?> InfoBatch() => {
    'UuidProduk': UuidUji.susuUht,
    'Pelacakan': 'Batch',
    'SimbolSatuan': 'pcs',
    'HariSegera': 30,
    'JumlahBatch': 2,
    'Batch': [
      {'NomorBatch': 'A-DEKAT', 'TanggalKedaluwarsa': '2026-09-30', 'JumlahSisa': '3.0000', 'SisaHari': -2},
      {'NomorBatch': 'B-LAMA', 'TanggalKedaluwarsa': '2026-11-11', 'JumlahSisa': '5.0000', 'SisaHari': 40},
    ],
  };

  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran, {required bool online}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (online && p.url.path.endsWith('/produk/${UuidUji.susuUht}/batch')) {
        return JsonUji(InfoBatch());
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Future<void> Ketuk(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await tester.pump();
    await tester.tap(f);
    await Tunggu(tester);
  }

  Future<void> CekHargaSusu(WidgetTester tester) async {
    await Ketuk(tester, find.byKey(const ValueKey('TombolCekHarga')));
    await tester.enterText(find.byKey(const ValueKey('CekHargaCari')), 'UHT-1L');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester, const Duration(milliseconds: 400));
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets(
      'cek harga: batch FEFO + status. Tambah ke keranjang → peringatan batch lewat (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await Masuk(tester, ukuran, online: true);
        await CekHargaSusu(tester);
        final batch = find.byKey(const ValueKey('CekHargaBatch'));
        await tester.ensureVisible(batch);
        expect(find.descendant(of: batch, matching: find.text('A-DEKAT | 3 pcs | dijual lebih dulu')), findsOneWidget);
        expect(find.descendant(of: batch, matching: find.text('lewat 2 hari')), findsOneWidget);
        expect(find.descendant(of: batch, matching: find.text('B-LAMA | 5 pcs')), findsOneWidget);
        expect(find.descendant(of: batch, matching: find.text('40 hari lagi')), findsOneWidget);
        expect(tester.takeException(), isNull);

        await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
        await Tunggu(tester, const Duration(milliseconds: 400));
        expect(find.textContaining('batch A-DEKAT sudah lewat kedaluwarsa 2 hari'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('offline: cek harga menyebut info batch butuh internet. Tambah ke keranjang tanpa peringatan', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), online: false);
    await CekHargaSusu(tester);
    expect(find.text('Info batch butuh internet.'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
    await Tunggu(tester, const Duration(milliseconds: 400));
    expect(find.textContaining('kedaluwarsa'), findsNothing);
    await Lepas(tester, u);
  });
}
