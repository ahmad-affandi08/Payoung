import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Penjualan/BagianTagihan.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-14 (BR-08.2): bagi tagihan rata per orang atau per nominal di layar Bayar. Satu penjualan dengan beberapa
/// pembayaran; bagian tunai digabung menjadi satu pembayaran tunai.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran) async {
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

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  Future<void> IsiDanBayar(WidgetTester tester, Size ukuran) async {
    await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
    await Ketuk(tester, Ubin('Americano Panas'));
    if (ukuran.width < 600) {
      await Ketuk(tester, find.textContaining('Keranjang |').first);
    }
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
  }

  Future<Map<String, Object?>> AmbilPenjualan(WidgetTester tester, LingkunganUji u) async {
    final baris = await tester.runAsync(() => u.db.select(u.db.outbox).get());
    return jsonDecode(baris!.firstWhere((o) => o.Jenis == 'Penjualan.Buat').Data) as Map<String, Object?>;
  }

  /// K-14 sisa: bagian tiap tamu yang tersimpan lokal bersama penjualan (untuk struk per tamu).
  Future<BagianTagihan?> AmbilBagian(WidgetTester tester, LingkunganUji u) async {
    final baris = (await tester.runAsync(() => u.db.select(u.db.penjualan).get()))!.single;
    return baris.BagianTagihan == null
        ? null
        : BagianTagihan.DariJson((jsonDecode(baris.BagianTagihan!) as Map).cast<String, Object?>());
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets('bagi rata 3 orang: tunai berkembalian, transfer, tamu terakhir sisa (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran);
      await IsiDanBayar(tester, ukuran);

      // 40.000 + PBJT 10% = 44.000 → 2 × 14.666 + 14.668.
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Bagi tagihan'));
      await Ketuk(tester, find.byTooltip('Tambah orang'));
      expect(find.text('2 × Rp 14.666 + tamu terakhir Rp 14.668'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Mulai bagi'));
      expect(find.text('Bagi rata 3 orang | tamu 1 dari 3'), findsOneWidget);
      expect(find.text('Porsi per orang Rp 14.666'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      expect(find.text('Porsi tamu 1'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Uang diterima'), '20000');
      await Tunggu(tester);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar porsi tamu 1'));
      expect(find.text('Tamu 1 lunas. Kembalian Rp 5.334.'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Transfer BCA'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar porsi tamu 2'));
      expect(find.text('Tamu 2 lunas (Transfer BCA).'), findsOneWidget);
      expect(find.text('Tamu terakhir membayar sisa Rp 14.668'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final pembayaran = ((await AmbilPenjualan(tester, u))['Pembayaran']! as List<Object?>)
          .cast<Map<String, Object?>>();
      expect(pembayaran, hasLength(2), reason: 'Bagian tunai digabung menjadi satu pembayaran tunai.');
      expect(pembayaran.map((p) => p['Jumlah']), unorderedEquals(['29334.00', '14666.00']));

      // K-14 sisa: tiap tamu tercatat sendiri (walau bagian tunai digabung di server) dan struk per tamu tersedia.
      final bagian = (await AmbilBagian(tester, u))!;
      expect(bagian.rata, isTrue);
      expect(bagian.tamu.map((t) => t.namaMetode), ['Tunai', 'Transfer BCA', 'Tunai']);
      expect(bagian.tamu.map((t) => t.porsi.KeString()), ['14666.00', '14666.00', '14668.00']);
      expect(bagian.tamu.first.diterima.KeString(), '20000.00');
      expect(bagian.tamu.first.kembalian.KeString(), '5334.00');
      expect(find.byKey(const ValueKey('TombolStrukTamu')), findsOneWidget);
      expect(find.text('Struk per tamu'), findsOneWidget);
      await Lepas(tester, u);
    });
  }

  testWidgets('bagi per nominal: tiap tamu membayar jumlah yang diketik sampai lunas (1280 dp)', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await IsiDanBayar(tester, const Size(1280, 900));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Bagi tagihan'));
    await Ketuk(tester, find.text('Per nominal'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Mulai bagi'));
    expect(find.text('Bagi per nominal | tamu 1'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await tester.enterText(find.widgetWithText(TextField, 'Uang diterima'), '30000');
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah pembayaran Tunai'));
    expect(find.text('Tamu 1 membayar Rp 30.000 (Tunai).'), findsOneWidget);
    expect(find.text('Bagi per nominal | tamu 2'), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    final pembayaran = ((await AmbilPenjualan(tester, u))['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
    expect(pembayaran.single['Jumlah'], '44000.00');
    final bagian = (await AmbilBagian(tester, u))!;
    expect(bagian.rata, isFalse);
    expect(bagian.tamu.map((t) => t.porsi.KeString()), ['30000.00', '14000.00']);
    expect(find.byKey(const ValueKey('TombolStrukTamu')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('tanpa bagi tagihan: tidak ada tombol struk per tamu dan tidak ada bagian tersimpan (1280 dp)', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await IsiDanBayar(tester, const Size(1280, 900));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    expect(await AmbilBagian(tester, u), isNull);
    expect(find.byKey(const ValueKey('TombolStrukTamu')), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('tombol struk per tamu: pilih satu tamu mencetak struk tamu itu saja (1280 dp)', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await tester.runAsync(() => const ProfilPrinter(alamat: '192.168.1.50', cetakOtomatis: false).Simpan(u.repositori));
    await IsiDanBayar(tester, const Size(1280, 900));
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Bagi tagihan'));
    await Ketuk(tester, find.text('Per nominal'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Mulai bagi'));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await tester.enterText(find.widgetWithText(TextField, 'Uang diterima'), '30000');
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah pembayaran Tunai'));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Transfer BCA'));
    await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '14000');
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);

    await Ketuk(tester, find.byKey(const ValueKey('TombolStrukTamu')));
    expect(find.text('Cetak struk per tamu'), findsOneWidget);
    expect(find.text('Semua tamu (2 struk)'), findsOneWidget);
    expect(find.text('Tamu 1 | Rp 30.000 | Tunai'), findsOneWidget);
    expect(find.text('Tamu 2 | Rp 14.000 | Transfer BCA'), findsOneWidget);
    await Ketuk(tester, find.byKey(const ValueKey('StrukTamu2')));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(u.printer.kiriman, hasLength(1), reason: 'Hanya struk tamu 2; cetak otomatis struk utama dimatikan.');
    final teks = u.printer.AmbilTeks();
    expect(teks, contains('Tamu 2 dari 2'));
    expect(teks, contains('Tagihan dibagi per nominal'));
    expect(find.text('Struk tamu 2 dicetak.'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
