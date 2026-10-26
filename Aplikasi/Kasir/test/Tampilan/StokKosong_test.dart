import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-07 + F-05, BR-05.2 di layar Jual: kasir menyimpan salinan sisa stok Toko dari server dan menolak menambah produk
/// berstok melebihi sisanya. Produk yang sisanya nol tampil "Habis"; produk di luar daftar server tidak dibatasi.
void main() {
  Future<http.Response> Function(http.Request) PenanganServer(Map<String, String> stok) => (p) async {
    final jalur = p.url.path;
    if (jalur.endsWith('/stok-tersedia')) {
      return JsonUji({
        'WaktuServer': '2026-09-24T01:00:00Z',
        'Produk': [
          for (final e in stok.entries) {'UuidProduk': e.key, 'Tersedia': e.value},
        ],
      });
    }
    if (jalur.endsWith('/produk-habis')) {
      return JsonUji({'Produk': <Object?>[]});
    }
    if (jalur.endsWith('/konfigurasi-aplikasi')) {
      return JsonUji(<String, Object?>{});
    }
    if (jalur.endsWith('/data-awal')) {
      return JsonUji(DataAwalUji());
    }
    if (jalur.endsWith('/katalog')) {
      return JsonUji(KatalogUji());
    }
    throw http.ClientException('offline');
  };

  /// Perangkat aktif, shift Rina terbuka, masuk sebagai Rina dengan server yang menjawab sisa stok [stok].
  Future<LingkunganUji> MasukJual(WidgetTester tester, Map<String, String> stok, {Size? ukuran}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = PenanganServer(stok);
    await PasangAplikasi(tester, u, ukuran: ukuran ?? const Size(1280, 900));
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  Finder TeksHabis(String nama) => find.descendant(of: Ubin(nama), matching: find.text('Habis'));

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  testWidgets('BR-05.2: produk dengan sisa stok nol tampil "Habis" dan tidak bisa ditambah. Produk lain tetap bisa', (
    tester,
  ) async {
    final u = await MasukJual(tester, {UuidUji.roti: '0.0000'});
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

    await tester.ensureVisible(Ubin('Roti Tawar Gandum'));
    await tester.pump();
    expect(TeksHabis('Roti Tawar Gandum'), findsOneWidget);
    expect(TeksHabis('Americano Panas'), findsNothing, reason: 'Produk di luar daftar server tanpa batas.');

    await Ketuk(tester, Ubin('Roti Tawar Gandum'));
    expect(find.textContaining('Stok Roti Tawar Gandum sudah habis.'), findsOneWidget);
    expect(wadah.read(penyediaKeranjang).baris, isEmpty);

    await Ketuk(tester, Ubin('Americano Panas'));
    expect(wadah.read(penyediaKeranjang).baris.single.nama, 'Americano Panas');
    await Lepas(tester, u);
  });

  testWidgets('BR-05.2: menambah sampai batas boleh, lalu produk tampil "Habis" dan penambahan berikutnya ditolak', (
    tester,
  ) async {
    final u = await MasukJual(tester, {UuidUji.roti: '2.0000'});
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));
    expect(TeksHabis('Roti Tawar Gandum'), findsNothing);

    await Ketuk(tester, Ubin('Roti Tawar Gandum'));
    await Ketuk(tester, Ubin('Roti Tawar Gandum'));
    expect(wadah.read(penyediaKeranjang).baris.single.jumlah, Kuantitas.DariBulat(2));
    expect(TeksHabis('Roti Tawar Gandum'), findsOneWidget, reason: 'Semua sisa stok sudah ada di keranjang.');

    await Ketuk(tester, Ubin('Roti Tawar Gandum'));
    expect(find.textContaining('Stok Roti Tawar Gandum hanya 2 Pcs, semuanya sudah ada di keranjang.'), findsOneWidget);
    expect(wadah.read(penyediaKeranjang).baris.single.jumlah, Kuantitas.DariBulat(2));
    await Lepas(tester, u);
  });

  testWidgets('BR-05.2: di HP 360dp ubin "Habis" tidak bisa ditambah dan pesannya tampil', (tester) async {
    final u = await MasukJual(tester, {UuidUji.roti: '0.0000'}, ukuran: const Size(360, 740));
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

    // Katalog di HP adalah grid malas (2 kolom): ubin di bawah layar belum terlihat, jadi gulir dulu.
    await tester.scrollUntilVisible(
      Ubin('Roti Tawar Gandum'),
      120,
      scrollable: find.descendant(of: find.byType(GridView), matching: find.byType(Scrollable)),
    );
    await tester.pump();
    expect(TeksHabis('Roti Tawar Gandum'), findsOneWidget);
    await Ketuk(tester, Ubin('Roti Tawar Gandum'));

    expect(find.textContaining('Stok Roti Tawar Gandum sudah habis.'), findsOneWidget);
    expect(wadah.read(penyediaKeranjang).baris, isEmpty);
    await Lepas(tester, u);
  });

  testWidgets('BR-05.2: server tanpa fitur stok-tersedia (offline/404): tidak ada batas dan kasir tetap berjualan', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    final dasar = PenanganServer(const {});
    u.server.penangan = (p) async => p.url.path.endsWith('/stok-tersedia') ? JsonUji(<String, Object?>{}) : dasar(p);
    await PasangAplikasi(tester, u);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester, const Duration(milliseconds: 600));
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));

    expect(TeksHabis('Roti Tawar Gandum'), findsNothing);
    for (var i = 0; i < 3; i++) {
      await Ketuk(tester, Ubin('Roti Tawar Gandum'));
    }

    expect(wadah.read(penyediaKeranjang).baris.single.jumlah, Kuantitas.DariBulat(3));
    await Lepas(tester, u);
  });
}
