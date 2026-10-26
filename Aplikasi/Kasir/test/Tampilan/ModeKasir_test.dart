import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Penjualan/PengaliJumlah.dart';
import 'package:kasir/Domain/Perangkat/PengaturanPerangkat.dart';
import 'package:kasir/Tampilan/LayarJual.dart';
import 'package:kasir/Tampilan/Meja/LayarMeja.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-8 (§5.1): mode kasir template sektor sampai ke kasir. Retail & Grosir memakai katalog daftar ringkas, mode Meja
/// membuka denah meja sebagai beranda, mode lain tetap ubin di layar Jual. Pengali jumlah `12*kode` di kolom cari
/// menambah 12 unit sekaligus (harga bertingkat ikut berlaku).
void main() {
  group('PengaliJumlah', () {
    test('mengurai 12*kode, 2,5x nama, 12* menunggu, tanpa pengali atau nol = masukan apa adanya', () {
      final a = PengaliJumlah.Urai('12*RTG-01');
      expect(a.jumlah, Kuantitas.DariBulat(12));
      expect(a.sisa, 'RTG-01');
      final b = PengaliJumlah.Urai(' 2,5 x Gula Aren ');
      expect(b.jumlah, Kuantitas.Dari('2.5'));
      expect(b.sisa, 'Gula Aren');
      expect(PengaliJumlah.Urai('12*').CekMenunggu, isTrue);
      expect(PengaliJumlah.Urai('Kopi Susu').jumlah, isNull);
      expect(PengaliJumlah.Urai('0*RTG-01').jumlah, isNull);
      expect(PengaliJumlah.Urai('8991000000028').jumlah, isNull);
    });
  });

  test('TampilanKatalog otomatis: Retail & Grosir daftar, lainnya ubin. Pilihan perangkat menang', () {
    expect(TampilanKatalog.Otomatis.Tentukan('Retail'), TampilanKatalog.Daftar);
    expect(TampilanKatalog.Otomatis.Tentukan('Grosir'), TampilanKatalog.Daftar);
    expect(TampilanKatalog.Otomatis.Tentukan('Cepat'), TampilanKatalog.Ubin);
    expect(TampilanKatalog.Otomatis.Tentukan(null), TampilanKatalog.Ubin);
    expect(TampilanKatalog.Ubin.Tentukan('Retail'), TampilanKatalog.Ubin);
    expect(TampilanKatalog.Daftar.Tentukan('Cepat'), TampilanKatalog.Daftar);
  });

  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran, {String? modeKasir, bool meja = false}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(modeKasir: modeKasir);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      if (meja) {
        await u.SiapkanMeja();
      }
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/meja')) {
        return JsonUji(DataMejaUji());
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

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Future<Map<String, Object?>> OutboxPenjualan(WidgetTester tester, LingkunganUji u) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .last;

  Future<void> Bayar(WidgetTester tester) async {
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets('Retail: katalog daftar ringkas ber-SKU, ketuk baris menambah (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran, modeKasir: 'Retail');
      expect(find.byType(LayarJual), findsOneWidget);
      expect(find.byKey(const ValueKey('KatalogDaftar')), findsOneWidget);
      expect(find.byType(UbinProduk), findsNothing);
      final roti = find.byWidgetPredicate((w) => w is BarisProduk && w.nama == 'Roti Tawar Gandum');
      await tester.scrollUntilVisible(
        roti,
        120,
        scrollable: find.descendant(of: find.byKey(const ValueKey('KatalogDaftar')), matching: find.byType(Scrollable)),
      );
      expect(roti, findsOneWidget);
      expect(find.descendant(of: roti, matching: find.text('RTG-01')), findsOneWidget);
      await Ketuk(tester, roti);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('pengali 12*SKU di kolom cari: 12 unit masuk, harga minimum 10 berlaku', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900), modeKasir: 'Grosir');
    await tester.enterText(find.byType(TextField).first, '12*RTG-01');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    await Bayar(tester);
    final outbox = await OutboxPenjualan(tester, u);
    final baris = (outbox['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
    expect(baris['Jumlah'], '12.0000');
    expect(baris['HargaSatuan'], '11000.00');
    await Lepas(tester, u);
  });

  testWidgets('pengali menunggu: 3* lalu pindai barcode = 3 unit', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900), modeKasir: 'Retail');
    await tester.enterText(find.byType(TextField).first, '3*');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    // Pemindai mengetik kode lalu Enter tanpa fokus di kolom cari (PengenalPemindai).
    expect(find.text('3*'), findsOneWidget, reason: 'Pengali menunggu tetap di kolom cari.');
    for (final c in UuidUji.barcodeAmericano.split('')) {
      await tester.sendKeyEvent(LogicalKeyboardKey(c.codeUnitAt(0)));
    }
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await Tunggu(tester);
    await Bayar(tester);
    final baris = ((await OutboxPenjualan(tester, u))['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
    expect(baris['Jumlah'], '3.0000');
    await Lepas(tester, u);
  });

  testWidgets('mode Meja: beranda ruang kerja = denah meja, tanpa mode kasir tetap layar Jual ubin', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900), modeKasir: 'Meja', meja: true);
    expect(find.byType(LayarMeja), findsOneWidget);
    await Lepas(tester, u);

    final v = await Masuk(tester, const Size(1280, 900), meja: true);
    expect(find.byType(LayarJual), findsOneWidget);
    expect(find.byType(UbinProduk), findsWidgets);
    await Lepas(tester, v);
  });
}
