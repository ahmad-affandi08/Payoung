import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// X8 harga per kanal ojol di layar Jual (Ruang Kerja, 360/800/1280 dp): baris "Kanal" di keranjang, pilih GoFood →
/// harga GoFood, panel Bayar langsung memilih metode GoFood (GrabFood disembunyikan), nomor pesanan sebagai referensi,
/// outbox `Penjualan.Buat` berkanal GoFood. Toko tanpa kanal platform tidak melihat baris kanal.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran, {bool ojol = true}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(ojol: ojol);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(ojol ? KatalogOjolUji() : KatalogUji());
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

  /// Di HP keranjang ada di lembar bawah: buka dengan mengetuk bilah ringkasan.
  Future<void> BukaKeranjangHp(WidgetTester tester, Size ukuran) async {
    if (ukuran.width < 600) {
      await Ketuk(tester, find.textContaining('Keranjang |').first);
    }
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets(
      'pesanan GoFood: pilih kanal, harga GoFood, bayar GoFood + nomor pesanan (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await MasukJual(tester, ukuran);
        await Ketuk(tester, Ubin('Americano Panas'));
        await BukaKeranjangHp(tester, ukuran);
        await BukaOpsiKeranjang(tester);

        expect(find.text('Kanal: Bawa pulang | ketuk untuk mengganti'), findsOneWidget);
        // Americano 15.000 + PBJT 10% = 16.500.
        expect(find.text('Rp 16.500'), findsWidgets);

        await Ketuk(tester, find.byKey(const ValueKey('PilihKanal')));
        expect(find.text('Kanal penjualan'), findsOneWidget);
        for (final kanal in ['BawaPulang', 'Antar', 'GoFood', 'GrabFood']) {
          expect(find.byKey(ValueKey('Kanal-$kanal')), findsOneWidget, reason: kanal);
        }
        // D-183: toko ini bukan F&B, jadi "Makan di tempat" tidak ditawarkan.
        expect(find.byKey(const ValueKey('Kanal-MakanDiTempat')), findsNothing);
        expect(
          find.byKey(const ValueKey('Kanal-ShopeeFood')),
          findsNothing,
          reason: 'Tanpa metode/daftar harga ShopeeFood.',
        );
        await Ketuk(tester, find.byKey(const ValueKey('Kanal-GoFood')));

        expect(find.text('Kanal: GoFood | ketuk untuk mengganti'), findsOneWidget);
        // Harga GoFood 19.000 + PBJT 10% = 20.900.
        expect(find.text('Rp 20.900'), findsWidgets);
        expect(tester.takeException(), isNull);

        await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
        await Tunggu(tester);
        expect(find.widgetWithText(ChoiceChip, 'GoFood'), findsOneWidget);
        expect(
          find.widgetWithText(ChoiceChip, 'GrabFood'),
          findsNothing,
          reason: 'Metode platform lain disembunyikan.',
        );
        expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'GoFood')).selected, isTrue);
        await tester.enterText(find.widgetWithText(TextField, 'Nomor pesanan GoFood (opsional)'), 'F-3281937');
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
        await Tunggu(tester);
        expect(find.text('Pembayaran berhasil'), findsOneWidget);

        final outbox = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
            .where((o) => o.Jenis == 'Penjualan.Buat')
            .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
            .single;
        expect(outbox['Kanal'], 'GoFood');
        expect((outbox['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], '20900.00');
        final bayar = (outbox['Pembayaran']! as List<Object?>).single! as Map<String, Object?>;
        expect(bayar['UuidMetodePembayaran'], '01K5MTD000000000000G0F00D1');
        expect(bayar['Referensi'], 'F-3281937');
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('toko tanpa kanal platform: baris kanal tidak tampil, penjualan tetap bawa pulang (1280 dp)', (
    tester,
  ) async {
    final u = await MasukJual(tester, const Size(1280, 900), ojol: false);
    await Ketuk(tester, Ubin('Americano Panas'));
    await BukaOpsiKeranjang(tester);
    expect(find.byKey(const ValueKey('PilihKanal')), findsNothing);
    expect(find.textContaining('Kanal:'), findsNothing);
    await Lepas(tester, u);
  });
}
