import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Pengaturan/KategoriPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:mesin_kasir/MesinKasir.dart' show Uang;

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// D-310: Pengaturan › Printer & struk › "Stasiun dapur & bar": kasir mengatur ke stasiun mana tiap kategori dan menu
/// dikirim langsung dari aplikasi (online, izin `dapur.rute.atur` yang otomatis dimiliki Kasir).
void main() {
  const dapur = '01K5STAS1VN000000000DAPUR1';

  Future<(LingkunganUji, List<http.Request>)> Masuk(WidgetTester tester) async {
    final u = LingkunganUji.Buat();
    final atur = <http.Request>[];
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.SiapkanMeja();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.contains('/dapur/rute/')) {
        atur.add(p);
        final badan = jsonDecode(p.body) as Map<String, Object?>;
        return JsonUji({'Uuid': p.url.pathSegments.last, 'UuidStasiun': badan['UuidStasiun']});
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: const Size(1280, 900));
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    await tester.tap(NavPengaturan().last);
    await Tunggu(tester);
    await BukaKategoriPengaturan(tester, KategoriPengaturan.printer);
    final bagian = find.byKey(const ValueKey('CakupanRuteDapur'));
    await tester.scrollUntilVisible(bagian, 300, scrollable: find.byType(Scrollable).first);
    await tester.pump();

    return (u, atur);
  }

  testWidgets('per kategori: kopi masuk Bar dari data meja, diubah ke Dapur lewat server dan langsung tampil', (
    tester,
  ) async {
    final (u, atur) = await Masuk(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('RuteKategori-${UuidUji.kategoriKopi}')),
        matching: find.text('Dikirim ke Bar'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('RuteKategori-${UuidUji.kategoriMakanan}')),
        matching: find.text('Ikut aturan (Dapur)'),
      ),
      findsOneWidget,
      reason: 'kategori tanpa aturan memakai stasiun bawaan',
    );

    await tester.tap(find.byKey(const ValueKey('PilihRuteKategori-${UuidUji.kategoriKopi}')));
    await Tunggu(tester);
    await tester.tap(find.text('Dapur').last);
    await Tunggu(tester);

    expect(atur.single.url.path, endsWith('/dapur/rute/kategori/${UuidUji.kategoriKopi}'));
    expect(jsonDecode(atur.single.body), {'UuidPengguna': '01K5STAF000000000000000001', 'UuidStasiun': dapur});
    expect(find.text('Dikirim ke Dapur'), findsWidgets);
    expect(find.byKey(const ValueKey('PesanRuteDapur')), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('per menu: cari menu, atur ke stasiun lain, lalu kembalikan ke ikut kategori', (tester) async {
    final (u, atur) = await Masuk(tester);

    await tester.tap(find.text('Per menu'));
    await Tunggu(tester);
    await tester.enterText(find.byKey(const ValueKey('CariMenuRute')), 'Croissant');
    await Tunggu(tester);
    final baris = find.byKey(const ValueKey('RuteProduk-${UuidUji.croissant}'));
    expect(find.descendant(of: baris, matching: find.text('Ikut kategori (Dapur)')), findsOneWidget);
    expect(find.byKey(const ValueKey('RuteProduk-${UuidUji.kopiSusu}')), findsNothing, reason: 'tersaring pencarian');

    await tester.tap(find.byKey(const ValueKey('PilihRuteProduk-${UuidUji.croissant}')));
    await Tunggu(tester);
    await tester.tap(find.text('Bar').last);
    await Tunggu(tester);
    expect(atur.last.url.path, endsWith('/dapur/rute/produk/${UuidUji.croissant}'));
    expect(find.descendant(of: baris, matching: find.text('Dikirim ke Bar')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('PilihRuteProduk-${UuidUji.croissant}')));
    await Tunggu(tester);
    await tester.tap(find.text('Ikut kategori').last);
    await Tunggu(tester);
    expect(jsonDecode(atur.last.body), containsPair('UuidStasiun', null));
    expect(find.descendant(of: baris, matching: find.text('Ikut kategori (Dapur)')), findsOneWidget);
    await Lepas(tester, u);
  });
}
