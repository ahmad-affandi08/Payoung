import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Tampilan/Jual/UmpanBalikPindai.dart';
import 'package:kasir/Tampilan/LayarJual.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Pencatat umpan balik pindai (bunyi & getar asli tidak bisa didengar di test).
class UmpanBalikTiruan extends UmpanBalikPindai {
  final List<String> catatan = [];

  @override
  Future<void> Berhasil() async => catatan.add('Berhasil');

  @override
  Future<void> Gagal() async => catatan.add('Gagal');
}

/// K-15 (§17.2.7 prinsip 4): pindai berhasil = bunyi + getar ringan + sorot baris keranjang (transisi 150 ms); gagal =
/// bunyi peringatan + getar kuat; bisa dimatikan di Pengaturan perangkat. Pemindai tanpa fokus (desktop) dan pemindai
/// yang mengetik ke kolom cari (HP) sama-sama ditangani.
void main() {
  Future<(LingkunganUji, UmpanBalikTiruan)> Masuk(WidgetTester tester, Size ukuran, {bool mati = false}) async {
    final u = LingkunganUji.Buat();
    final umpanBalik = UmpanBalikTiruan();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      if (mati) {
        await u.repositori.SimpanPengaturan(KunciPengaturan.umpanBalikPindai, '0');
      }
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
    await PasangAplikasi(tester, u, ukuran: ukuran, umpanBalikPindai: umpanBalik);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    return (u, umpanBalik);
  }

  Future<void> PindaiTanpaFokus(WidgetTester tester, String kode) async {
    for (final karakter in kode.split('')) {
      await tester.sendKeyEvent(LogicalKeyboardKey(karakter.codeUnitAt(0)));
    }
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.pump();
  }

  bool CekDisorot(WidgetTester tester) =>
      tester.widgetList<BarisKeranjang>(find.byType(BarisKeranjang)).any((b) => b.disorot);

  testWidgets('pemindai tanpa fokus (1280 dp): berhasil → bunyi + sorot sebentar. Kode tak dikenal → bunyi gagal', (
    tester,
  ) async {
    final (u, umpanBalik) = await Masuk(tester, const Size(1280, 900));
    await PindaiTanpaFokus(tester, UuidUji.barcodeAmericano);
    expect(umpanBalik.catatan, ['Berhasil']);
    expect(CekDisorot(tester), isTrue);
    await Tunggu(tester, LayarJual.lamaSorot + const Duration(milliseconds: 300));
    expect(CekDisorot(tester), isFalse);

    // Pindai lagi produk yang sama: baris yang jumlahnya bertambah ikut disorot.
    await PindaiTanpaFokus(tester, UuidUji.barcodeAmericano);
    expect(umpanBalik.catatan, ['Berhasil', 'Berhasil']);
    expect(CekDisorot(tester), isTrue);

    await PindaiTanpaFokus(tester, '1234567');
    await Tunggu(tester);
    expect(umpanBalik.catatan, ['Berhasil', 'Berhasil', 'Gagal']);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('pemindai yang mengetik ke kolom cari (360 dp) juga berbunyi', (tester) async {
    final (u, umpanBalik) = await Masuk(tester, const Size(360, 740));
    final cari = find.byType(TextField).first;
    await tester.tap(cari);
    await tester.enterText(cari, UuidUji.barcodeAmericano);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    expect(umpanBalik.catatan, ['Berhasil']);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('dimatikan di Pengaturan perangkat: tidak ada bunyi/getar (800 dp)', (tester) async {
    final (u, umpanBalik) = await Masuk(tester, const Size(800, 1280), mati: true);
    await PindaiTanpaFokus(tester, UuidUji.barcodeAmericano);
    await PindaiTanpaFokus(tester, '1234567');
    await Tunggu(tester);
    expect(umpanBalik.catatan, isEmpty);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
