import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Jual/PanelPelanggan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-16a / X8 / F-07: pelanggan, jenis pesanan, dan catatan dipilih langsung di layar Bayar sebelum uang diterima.
/// Pilihan tetap tersimpan di keranjang dan ikut ke outbox `Penjualan.Buat`.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran, {Map<String, Object?>? dataAwal}) async {
    final u = LingkunganUji.Buat();
    final awal = dataAwal ?? DataAwalUji();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: awal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(awal);
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

  Finder CariUbin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  Future<void> BukaBayar(WidgetTester tester) async {
    await Ketuk(tester, CariUbin('Americano Panas'));
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    expect(find.byKey(const ValueKey('PanelPesananBayar')), findsOneWidget);
    expect(find.byKey(const ValueKey('PanelPembayaranBayar')), findsOneWidget);
  }

  Future<void> BayarTunaiPas(WidgetTester tester) async {
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
  }

  Future<Map<String, Object?>> AmbilOutboxTerakhir(WidgetTester tester, LingkunganUji u) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .last;

  testWidgets('F-16a: pelanggan baru dipilih dari layar Bayar dan ikut ke penjualan (1280dp)', (tester) async {
    final u = await MasukJual(tester, const Size(1280, 900));
    await BukaBayar(tester);

    expect(find.text('Pelanggan umum'), findsOneWidget);
    await Ketuk(tester, find.byKey(const ValueKey('PelangganBayar')));
    expect(find.byType(PanelPelanggan), findsOneWidget);

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Pelanggan baru'));
    await tester.enterText(find.widgetWithText(TextField, 'Nama pelanggan'), 'Budi Santoso');
    await tester.enterText(find.widgetWithText(TextField, 'No. HP/WA'), '0813-1111-2222');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan & pakai'));

    expect(find.byType(PanelPelanggan), findsNothing);
    expect(find.byKey(const ValueKey('PelangganBayar')), findsOneWidget);
    expect(find.text('Budi Santoso'), findsOneWidget);

    await BayarTunaiPas(tester);
    final jual = await AmbilOutboxTerakhir(tester, u);
    expect(jual['UuidPelanggan'], isNotNull);
    await Lepas(tester, u);
  });

  testWidgets('X8: jenis pesanan diganti dari layar Bayar dan ikut ke penjualan (1280dp)', (tester) async {
    final u = await MasukJual(
      tester,
      const Size(1280, 900),
      dataAwal: DataAwalUji(jenisPesanan: const ['MakanDiTempat', 'BawaPulang'], jenisPesananBawaan: 'MakanDiTempat'),
    );
    await BukaBayar(tester);

    expect(find.text('Makan di tempat'), findsOneWidget);
    await Ketuk(tester, find.byKey(const ValueKey('JenisPesananBayar')));
    await Ketuk(tester, find.text('Bawa pulang'));

    expect(find.text('Bawa pulang'), findsOneWidget);
    await BayarTunaiPas(tester);
    expect((await AmbilOutboxTerakhir(tester, u))['Kanal'], 'BawaPulang');
    await Lepas(tester, u);
  });

  testWidgets('F-07: catatan pesanan diisi dari layar Bayar dan ikut ke penjualan (800dp)', (tester) async {
    final u = await MasukJual(tester, const Size(800, 1280));
    await BukaBayar(tester);

    await Ketuk(tester, find.byKey(const ValueKey('CatatanPesananBayar')));
    await tester.enterText(find.byKey(const ValueKey('CatatanBayar')), 'Tanpa es, saus terpisah');
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan'));

    expect(find.text('Tanpa es, saus terpisah'), findsOneWidget);
    await BayarTunaiPas(tester);
    expect((await AmbilOutboxTerakhir(tester, u))['Catatan'], 'Tanpa es, saus terpisah');
    await Lepas(tester, u);
  });

  testWidgets('F-16a: baris konteks tetap terbaca & bisa diketuk di 360dp', (tester) async {
    final u = await MasukJual(tester, const Size(360, 740));
    await BukaBayar(tester);

    expect(find.byKey(const ValueKey('PelangganBayar')), findsOneWidget);
    expect(find.byKey(const ValueKey('CatatanPesananBayar')), findsOneWidget);
    await Ketuk(tester, find.byKey(const ValueKey('PelangganBayar')));
    expect(find.byType(PanelPelanggan), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('F-07: konteks pesanan terkunci setelah tagihan dibagi', (tester) async {
    final u = await MasukJual(tester, const Size(1280, 900));
    await BukaBayar(tester);

    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Bagi tagihan'));
    await Ketuk(tester, find.byTooltip('Tambah orang'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Mulai bagi'));

    // Tagihan sudah dibagi: pelanggan & catatan tidak boleh berubah karena pembagiannya sudah dihitung.
    await Ketuk(tester, find.byKey(const ValueKey('PelangganBayar')));
    expect(find.byType(PanelPelanggan), findsNothing);
    await Ketuk(tester, find.byKey(const ValueKey('CatatanPesananBayar')));
    expect(find.byKey(const ValueKey('CatatanBayar')), findsNothing);
    await Lepas(tester, u);
  });
}
