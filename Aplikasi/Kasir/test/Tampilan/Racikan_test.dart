import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Jual/PanelRacikan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Apotek bagian 4 di layar Jual (360/800/1280 dp): "Buat racikan obat" di keranjang membuka penyusun racikan (jasa
/// racik, nama, kemasan, aturan pakai, cari & tambah obat, jumlah per racikan, harga saran), lalu racikan masuk keranjang
/// sebagai satu baris dan dikirim sebagai `Baris[].Racikan`.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalApotekUji();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogBengkelApotekUji());
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

  Future<void> TambahObat(WidgetTester tester, String kata, String nama) async {
    final cari = find.widgetWithText(TextField, 'Cari obat atau bahan');
    await tester.ensureVisible(cari);
    await tester.enterText(cari, kata);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(ListTile, nama));
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    testWidgets('susun racikan puyer lalu bayar (${ukuran.width.toInt()} dp)', (tester) async {
      final u = await MasukJual(tester, ukuran);
      // Satu obat dijual satuan lebih dulu (di 360 dp bilah keranjang baru muncul setelah ada barang).
      final cariKatalog = find.byType(TextField).first;
      await tester.tap(cariKatalog);
      await tester.enterText(cariKatalog, 'Para');
      await Tunggu(tester);
      await tester.tap(find.text('Paracetamol 500 mg Tablet Strip 10').first);
      await Tunggu(tester);
      if (ukuran.width < 600) {
        await Ketuk(tester, find.textContaining('Keranjang |').first);
      }
      await BukaOpsiKeranjang(tester);
      await Ketuk(tester, find.text('Buat racikan obat | ketuk untuk menyusun'));
      expect(find.byType(PanelRacikan), findsOneWidget);

      // Belum lengkap: pesan jelas, keranjang tetap kosong.
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah racikan ke keranjang'));
      expect(find.text('Pilih jasa racik.'), findsOneWidget);

      await Ketuk(tester, find.byType(DropdownButtonFormField<String>));
      await Ketuk(tester, find.text('Servis rutin motor matic').last);
      await tester.enterText(find.widgetWithText(TextField, 'Nama racikan'), 'Puyer demam anak');
      await tester.enterText(find.widgetWithText(TextField, 'Aturan pakai'), '3 x 1 bungkus');
      await TambahObat(tester, 'Para', 'Paracetamol 500 mg Tablet Strip 10');
      await TambahObat(tester, 'CTM', 'CTM Chlorpheniramine 4 mg Tablet Strip 10');
      await tester.enterText(find.byKey(const ValueKey('JumlahRacikan-$paracetamol')), '2');
      await Tunggu(tester);
      expect(find.text('Racikan berisi obat bebas terbatas.'), findsOneWidget);
      // Harga saran: jasa harga terbuka (0) + paracetamol 2 × 5.000 + CTM 1 × 3.500.
      expect(find.widgetWithText(TextField, '13.500'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah racikan ke keranjang'));
      expect(find.byType(PanelRacikan), findsNothing);
      if (ukuran.width < 600 && find.textContaining('Racikan Puyer demam anak').evaluate().isEmpty) {
        await Ketuk(tester, find.textContaining('Keranjang |').first);
      }
      expect(find.textContaining('Racikan Puyer demam anak | 10 kemasan | 3 x 1 bungkus'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
      expect(find.text('Penyerahan obat'), findsNothing, reason: 'Racikan obat bebas tidak butuh resep.');
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final jual = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .single;
      final semua = (jual['Baris']! as List<Object?>).cast<Map<String, Object?>>();
      expect(semua, hasLength(2));
      final baris = semua.singleWhere((b) => b.containsKey('Racikan'));
      expect(baris['HargaSatuan'], '13500.00');
      expect((baris['Racikan']! as Map<String, Object?>)['Komponen'], [
        {'UuidProduk': paracetamol, 'UuidProdukSatuan': psParacetamol, 'Jumlah': '2.0000'},
        {'UuidProduk': ctm, 'UuidProdukSatuan': psCtm, 'Jumlah': '1.0000'},
      ]);
      await tester.runAsync(u.Tutup);
    });
  }
}
