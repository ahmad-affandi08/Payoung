import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:mesin_kasir/MesinKasir.dart' show KanalPenjualan;
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// v3.51 jenis pesanan FnB di layar Jual (§9.1–§9.2), di 360/800/1280 dp: outlet yang menawarkan Makan di tempat &
/// Bawa pulang menampilkan tombol segmen di keranjang, transaksi baru memakai bawaan outlet (Makan di tempat), kasir
/// bisa mengganti ke Bawa pulang, dan outbox `Penjualan.Buat` membawa kanal yang dipilih. Outlet retail (tanpa jenis
/// pesanan) tidak melihat tombol itu dan tetap tercatat Bawa pulang.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran, {bool fnb = true}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(
      jenisPesanan: fnb ? const ['MakanDiTempat', 'BawaPulang'] : null,
      jenisPesananBawaan: fnb ? 'MakanDiTempat' : null,
    );
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

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  Future<void> BukaKeranjangHp(WidgetTester tester, Size ukuran) async {
    if (ukuran.width < 600) {
      await Ketuk(tester, find.textContaining('Keranjang |').first);
    }
  }

  Future<Map<String, Object?>> OutboxTerakhir(WidgetTester tester, LingkunganUji u) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .last;

  Future<String?> KanalOutbox(WidgetTester tester, LingkunganUji u) async =>
      (await OutboxTerakhir(tester, u))['Kanal'] as String?;

  Future<void> Bayar(WidgetTester tester) async {
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
  }

  SegmentedButton<KanalPenjualan> Segmen(WidgetTester tester) =>
      tester.widget<SegmentedButton<KanalPenjualan>>(find.byKey(const ValueKey('JenisPesanan')));

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets('FnB: bawaan Makan di tempat, ganti Bawa pulang, outbox membawa kanal (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await MasukJual(tester, ukuran);
      await Ketuk(tester, Ubin('Americano Panas'));
      await BukaKeranjangHp(tester, ukuran);
      await BukaOpsiKeranjang(tester);

      expect(find.byKey(const ValueKey('JenisPesanan')), findsOneWidget);
      expect(Segmen(tester).selected, {KanalPenjualan.MakanDiTempat}, reason: 'Transaksi baru ikut bawaan outlet.');
      expect(
        find.byKey(const ValueKey('PilihKanal')),
        findsNothing,
        reason: 'Tanpa ojol/antar tidak ada baris kanal tambahan.',
      );
      await Bayar(tester);
      expect(await KanalOutbox(tester, u), 'MakanDiTempat');

      // Transaksi berikutnya kembali ke bawaan; kasir memilih Bawa pulang.
      await Ketuk(tester, find.text('Transaksi baru'));
      await Ketuk(tester, Ubin('Americano Panas'));
      await BukaKeranjangHp(tester, ukuran);
      await BukaOpsiKeranjang(tester);
      expect(Segmen(tester).selected, {KanalPenjualan.MakanDiTempat});
      await Ketuk(tester, find.text('Bawa pulang'));
      expect(Segmen(tester).selected, {KanalPenjualan.BawaPulang});
      expect(tester.takeException(), isNull);
      await Bayar(tester);
      expect(await KanalOutbox(tester, u), 'BawaPulang');
      await Lepas(tester, u);
    });
  }

  testWidgets('retail tanpa jenis pesanan: tidak ada tombol segmen, nama pemesan, maupun nomor antrian', (
    tester,
  ) async {
    final u = await MasukJual(tester, const Size(1280, 900), fnb: false);
    await Ketuk(tester, Ubin('Americano Panas'));
    await BukaOpsiKeranjang(tester);
    expect(find.byKey(const ValueKey('JenisPesanan')), findsNothing);
    expect(find.byKey(const ValueKey('IsiNamaPemesan')), findsNothing);
    await Bayar(tester);
    expect(find.text('Nomor antrian'), findsNothing);
    final outbox = await OutboxTerakhir(tester, u);
    expect(outbox['Kanal'], 'BawaPulang');
    expect(outbox.containsKey('NomorAntrian'), isFalse);
    expect(outbox.containsKey('NamaPemesan'), isFalse);
    await Lepas(tester, u);
  });

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('v3.52 FnB bayar-dulu: nama pemesan & nomor antrian dari urut harian (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await MasukJual(tester, ukuran);
      await Ketuk(tester, Ubin('Americano Panas'));
      await BukaKeranjangHp(tester, ukuran);
      await BukaOpsiKeranjang(tester);
      await Ketuk(tester, find.byKey(const ValueKey('IsiNamaPemesan')));
      await tester.enterText(find.byKey(const ValueKey('NamaPemesan')), '  Budi Santoso ');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan'));
      expect(find.text('Pemesan: Budi Santoso | ketuk untuk mengubah'), findsOneWidget);
      await Bayar(tester);

      expect(find.text('Nomor antrian'), findsOneWidget);
      expect(find.text('001'), findsOneWidget);
      expect(find.text('Budi Santoso'), findsOneWidget);
      final outbox = await OutboxTerakhir(tester, u);
      expect(outbox['NomorAntrian'], '001');
      expect(outbox['NamaPemesan'], 'Budi Santoso');
      expect((outbox['Nomor']! as String).endsWith('-0001'), isTrue);

      // Transaksi berikutnya: nomor naik, nama pemesan tidak terbawa.
      await Ketuk(tester, find.text('Transaksi baru'));
      await Ketuk(tester, Ubin('Americano Panas'));
      await BukaKeranjangHp(tester, ukuran);
      await BukaOpsiKeranjang(tester);
      expect(find.text('Nama pemesan | ketuk untuk mengisi'), findsOneWidget);
      await Bayar(tester);
      expect(find.text('002'), findsOneWidget);
      expect((await OutboxTerakhir(tester, u)).containsKey('NamaPemesan'), isFalse);
      await Lepas(tester, u);
    });
  }
}
