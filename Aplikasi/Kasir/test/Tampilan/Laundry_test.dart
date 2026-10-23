import 'dart:convert';

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

/// Laundry bagian 2 (§9.9) di aplikasi kasir: isian tiket laundry dari keranjang (offline) ikut `Penjualan.Buat`, dan
/// Riwayat › Cucian menampilkan cucian siap diambil lalu menandainya diambil (online), di 360/800/1280 dp.
void main() {
  final tiket = {
    'Uuid': '01K5PENJUALAN0000000000001',
    'Nomor': 'INV/SLB/260926/POS-001-0001',
    'EstimasiSelesaiPada': '2026-09-27T02:25:00Z',
    'NamaPelanggan': 'Ratna Sari',
    'JenisLayanan': 'Express',
    'Berat': '3.50',
    'Item': <Object?>[],
    'Status': 'Siap',
    'LabelStatus': 'Siap diambil',
    'StatusBerikutnya': ['Diambil'],
  };

  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: DataAwalUji(laundry: true));
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji(laundry: true));
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/laundry')) {
        return JsonUji({
          'Tiket': [tiket],
        });
      }
      if (p.url.path.endsWith('/status')) {
        return JsonUji({
          'Tiket': {...tiket, 'Status': 'Diambil', 'LabelStatus': 'Sudah diambil', 'StatusBerikutnya': <String>[]},
        });
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
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000)]) {
    testWidgets('isi tiket laundry dari keranjang lalu bayar (${ukuran.width.toInt()} dp)', (tester) async {
      final u = await Masuk(tester, ukuran);
      await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
      await BukaOpsiKeranjang(tester);
      await Ketuk(tester, find.text('Tanpa tiket laundry | ketuk untuk mengisi'));

      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Express | 24 jam'));
      expect(find.textContaining('Perkiraan selesai'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan tiket laundry'));
      expect(find.text('Isi berat cucian atau tambahkan item satuan (jas, bed cover, ...).'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Berat (kg)'), '3,5');
      await tester.enterText(find.widgetWithText(TextField, 'Item satuan (jas, bed cover, ...)'), 'Bed cover');
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Tambah item'));
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Lavender'));
      await tester.enterText(find.widgetWithText(TextField, 'Nama pemilik cucian'), 'Ratna Sari');
      await tester.enterText(
        find.widgetWithText(TextField, 'Nomor WhatsApp (untuk kabar cucian siap)'),
        '081234567890',
      );
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan tiket laundry'));

      final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));
      expect(wadah.read(penyediaKeranjang).laundry?.RingkasIsi(), '3,5 kg | Bed cover ×1');
      await BukaOpsiKeranjang(tester);
      expect(find.text('Laundry Express | 3,5 kg | Bed cover ×1'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
      expect(find.text('Tiket laundry Express | 3,5 kg | Bed cover ×1'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final jual = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .single;
      expect(jual['Laundry'], allOf(containsPair('Berat', '3.50'), containsPair('Parfum', 'Lavender')));
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    testWidgets('Riwayat › Cucian: siap diambil lalu ditandai diambil (${ukuran.width.toInt()} dp)', (tester) async {
      final u = await Masuk(tester, ukuran);
      await tester.tap(find.text('Riwayat').last);
      await Tunggu(tester);
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cucian'));
      expect(find.text('Ratna Sari'), findsOneWidget);
      expect(find.text('Express | 3,5 kg'), findsOneWidget);
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Sudah diambil'));
      expect(find.text('Cucian INV/SLB/260926/POS-001-0001: Sudah diambil.'), findsOneWidget);
      expect(find.text('Ratna Sari'), findsNothing);
      expect(jsonDecode(u.server.permintaan.last.body), containsPair('Status', 'Diambil'));
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
