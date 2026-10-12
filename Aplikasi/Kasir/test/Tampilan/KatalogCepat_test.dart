import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-16 (§17.2.7): kategori "Terlaris" dari penjualan perangkat ini, kategori terakhir diingat per perangkat, dan
/// daftar pintasan keyboard lewat `?`.
void main() {
  /// Katalog uji dengan produk [favorit] ditandai pilihan pemilik (K-16).
  Map<String, Object?> KatalogDenganFavorit(Set<String> favorit) {
    final katalog = KatalogUji();
    katalog['Produk'] = [
      for (final p in (katalog['Produk']! as List<Object?>).cast<Map<String, Object?>>())
        {...p, 'Favorit': favorit.contains(p['Uuid'])},
    ];
    return katalog;
  }

  Future<LingkunganUji> Masuk(
    WidgetTester tester,
    Size ukuran, {
    String? kategoriTersimpan,
    Set<String> favorit = const {},
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      if (kategoriTersimpan != null) {
        await u.repositori.SimpanPengaturan(KunciPengaturan.kategoriTerakhirJual, kategoriTersimpan);
      }
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(favorit.isEmpty ? KatalogUji() : KatalogDenganFavorit(favorit));
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
  Finder Chip(String label) => find.widgetWithText(ChoiceChip, label);

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets(
      'Terlaris muncul setelah ada penjualan, menampilkan produk terjual, pilihan diingat (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await Masuk(tester, ukuran);
        expect(Chip('Terlaris'), findsNothing, reason: 'Belum ada penjualan di perangkat ini.');

        await Ketuk(tester, Ubin('Americano Panas'));
        if (ukuran.width < 600) {
          await Ketuk(tester, find.textContaining('Keranjang |').first);
        }
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
        await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));

        expect(Chip('Terlaris'), findsOneWidget);
        await Ketuk(tester, Chip('Terlaris'));
        expect(find.byType(UbinProduk), findsOneWidget);
        expect(Ubin('Americano Panas'), findsOneWidget);
        final tersimpan = await tester.runAsync(
          () => u.repositori.AmbilPengaturan(KunciPengaturan.kategoriTerakhirJual),
        );
        expect(tersimpan, 'Terlaris');
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('kategori terakhir dipulihkan saat layar Jual dibuka lagi; kategori yang hilang kembali ke Semua', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), kategoriTersimpan: UuidUji.kategoriMakanan);
    expect(tester.widget<ChoiceChip>(Chip('Makanan')).selected, isTrue);
    expect(Ubin('Americano Panas'), findsNothing);
    await Lepas(tester, u);

    final lain = await Masuk(tester, const Size(1280, 900), kategoriTersimpan: '01K5KATEGORIHILANG000000001');
    expect(tester.widget<ChoiceChip>(Chip('Semua')).selected, isTrue);
    await Lepas(tester, lain);
  });

  testWidgets('`?` di luar kolom isian membuka daftar pintasan; di kolom cari tetap jadi teks', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.slash, character: '?');
    await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
    await Tunggu(tester);
    expect(find.text('Pintasan keyboard'), findsOneWidget);
    expect(find.text('Bayar tunai uang pas'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(TextButton, 'Tutup'));
    expect(find.text('Pintasan keyboard'), findsNothing);

    await tester.sendKeyEvent(LogicalKeyboardKey.f1);
    await tester.pump();
    await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.slash, character: '?');
    await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
    await Tunggu(tester);
    expect(find.text('Pintasan keyboard'), findsNothing);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('Favorit pilihan pemilik: chip hanya bila ada, isinya produk bertanda yang tampil, pilihan diingat '
        '(${ukuran.width.toInt()} dp)', (tester) async {
      // Gula Aren Cair tidak tampil di kasir (bahan baku): tanda favoritnya tidak boleh memunculkannya.
      final u = await Masuk(tester, ukuran, favorit: {UuidUji.americano, UuidUji.croissant, UuidUji.gulaAren});
      expect(Chip('Favorit'), findsOneWidget);
      expect(
        tester.getTopLeft(Chip('Favorit')).dx > tester.getTopLeft(Chip('Semua')).dx,
        isTrue,
        reason: 'Favorit tepat setelah Semua.',
      );

      await Ketuk(tester, Chip('Favorit'));
      expect(find.byType(UbinProduk), findsNWidgets(2));
      expect(Ubin('Americano Panas'), findsOneWidget);
      expect(Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'), findsOneWidget);
      expect(Ubin('Es Kopi Susu Aren'), findsNothing);
      final tersimpan = await tester.runAsync(() => u.repositori.AmbilPengaturan(KunciPengaturan.kategoriTerakhirJual));
      expect(tersimpan, 'Favorit');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('tanpa produk favorit tidak ada chip Favorit; Favorit tersimpan yang sudah dicabut kembali ke Semua', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), kategoriTersimpan: 'Favorit');
    expect(Chip('Favorit'), findsNothing);
    expect(tester.widget<ChoiceChip>(Chip('Semua')).selected, isTrue);
    expect(Ubin('Americano Panas'), findsOneWidget, reason: 'Semua produk tampil, bukan daftar kosong.');
    await Lepas(tester, u);
  });

  testWidgets('Favorit tersimpan dipulihkan bila masih ada favorit', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900), kategoriTersimpan: 'Favorit', favorit: {UuidUji.americano});
    expect(tester.widget<ChoiceChip>(Chip('Favorit')).selected, isTrue);
    expect(find.byType(UbinProduk), findsOneWidget);
    expect(Ubin('Americano Panas'), findsOneWidget);
    await Lepas(tester, u);
  });
}
