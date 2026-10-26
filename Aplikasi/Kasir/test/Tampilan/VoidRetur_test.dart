import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';
import '../Pendukung/StrukUji.dart';

/// Rincian F-09 fase 1 di Ruang Kerja Kasir: void dari Riwayat (PIN supervisor ber-izin `penjualan.void`, refund tunai
/// dari laci, kas berkurang) dan retur dari struk (cari online, pilih barang, PIN, nomor RJ). Diuji di 360/800/1280dp.
void main() {
  const ukuranHp = Size(360, 740);
  const ukuranTablet = Size(800, 1280);
  const ukuranDesktop = Size(1280, 900);

  /// Shift Rina (Rp 500.000) + satu penjualan tunai Rp 67.100 (bayar Rp 100.000) sudah ada; Rina masuk.
  Future<(LingkunganUji, String)> MasukRuangKerja(
    WidgetTester tester, {
    required Size ukuran,
    Future<http.Response> Function(http.Request p)? penangan,
  }) async {
    final u = LingkunganUji.Buat();
    late String nomor;
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.SiapkanKatalog();
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      final rina = await u.Staf('Rina Wulandari');
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      var keranjang = Keranjang.kosong;
      for (final uuid in [UuidUji.kopiSusu, UuidUji.kopiSusu, UuidUji.croissant]) {
        final gula = uuid == UuidUji.kopiSusu
            ? [PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol())]
            : const <PilihanTerpilih>[];
        keranjang = u.penjualan.TambahBaris(
          keranjang,
          u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(uuid)!, pilihan: gula),
          katalog,
          k,
        );
      }
      final jual = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          PembayaranMasukan(
            metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
            jumlah: Uang.DariBulat(100000),
          ),
        ],
        kasir: rina,
        k: k,
      );
      nomor = jual.nomor;
    });
    u.server.penangan = penangan ?? (_) async => throw http.ClientException('offline');
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    expect(find.byType(RuangKerja), findsOneWidget);
    return (u, nomor);
  }

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    // Dua kali: isi lembar bisa berubah tinggi (galat isian hilang) setelah gulir pertama.
    await tester.ensureVisible(finder);
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Future<void> BukaRiwayat(WidgetTester tester) async {
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    expect(find.text('Riwayat transaksi hari ini'), findsOneWidget);
  }

  Future<void> SetujuiBudi(WidgetTester tester) async {
    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Rina Wulandari'), findsNothing, reason: 'Rina tanpa penjualan.void.');
    await PilihPenyetuju(tester, 'Budi Santoso');
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester, const Duration(seconds: 1));
  }

  Future<List<Map<String, Object?>>> Outbox(WidgetTester tester, LingkunganUji u, String jenis) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == jenis)
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .toList();

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('lebar $nama dp: void dari Riwayat → alasan + PIN supervisor → refund tunai dari laci, status Void, '
        'kas berkurang', (tester) async {
      final (u, nomor) = await MasukRuangKerja(tester, ukuran: ukuran);
      await BukaRiwayat(tester);
      await Ketuk(tester, find.text(nomor));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Batalkan transaksi'));

      expect(tester.widget<PanelTugas>(find.byType(PanelTugas)).judul, 'Batalkan transaksi');
      expect(find.text('Kembalikan tunai dari laci'), findsOneWidget);
      expect(find.text('Rp 67.100'), findsWidgets);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Batalkan transaksi'));
      expect(find.text('Tulis alasan void minimal 5 huruf.'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Alasan void'), 'Salah input pesanan meja 4');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Batalkan transaksi'));
      await SetujuiBudi(tester);

      expect(find.text('Transaksi $nomor sudah di-void.'), findsOneWidget);
      expect(tester.takeException(), isNull);
      final data = (await Outbox(tester, u, 'Penjualan.Void')).single;
      expect(data['Alasan'], 'Salah input pesanan meja 4');
      expect(data['UuidPenyetuju'], '01K5STAF000000000000000002');

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesai'));
      expect(find.byType(PanelTugas), findsNothing);
      // Kotak cari riwayat (audit kemudahan pakai #10) menggeser baris ke bawah di 360 dp: gulir ke baris dulu.
      await tester.ensureVisible(find.text('Void', skipOffstage: false));
      await Tunggu(tester);
      expect(find.text('Void'), findsOneWidget, reason: 'Status dokumen berteks di riwayat.');
      expect(find.textContaining('0 transaksi | Rp 0 | 1 void'), findsOneWidget);
      await Ketuk(tester, find.text(nomor));
      expect(find.widgetWithText(OutlinedButton, 'Batalkan transaksi'), findsNothing, reason: 'Sudah di-void.');

      await tester.tap(find.text('Kas').last);
      await Tunggu(tester);
      expect(find.text('Uang kembali ke pelanggan (batal & retur)'), findsOneWidget);
      expect(find.text('−Rp 67.100'), findsOneWidget);
      expect(find.text('Rp 500.000'), findsWidgets, reason: 'Perkiraan kas kembali ke kas awal.');
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });

    testWidgets('lebar $nama dp: retur dari struk → cari online, pilih barang & kondisi, PIN supervisor → nomor RJ', (
      tester,
    ) async {
      final (u, _) = await MasukRuangKerja(
        tester,
        ukuran: ukuran,
        penangan: (p) async =>
            p.url.path.endsWith('/penjualan/cari') ? JsonUji(StrukUji()) : throw http.ClientException('offline'),
      );
      await BukaRiwayat(tester);
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Retur dari struk'));
      expect(tester.widget<PanelTugas>(find.byType(PanelTugas)).judul, 'Retur dari struk');

      await tester.enterText(find.widgetWithText(TextField, 'Nomor struk'), UuidStruk.nomor);
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await Tunggu(tester);
      expect(find.text('Kopi Susu Literan 1 L'), findsOneWidget);
      expect(find.text('Terjual 2,5 kg | bisa diretur 2,5 kg'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.ensureVisible(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')));
      await tester.enterText(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), '5');
      await Tunggu(tester);
      expect(find.text('Maksimal 3.'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), '1');
      await Tunggu(tester);
      expect(find.text('Rp 33.333,33'), findsWidgets);
      await Ketuk(tester, find.text('Rusak').first);
      await tester.ensureVisible(find.widgetWithText(TextField, 'Alasan retur'));
      await tester.enterText(find.widgetWithText(TextField, 'Alasan retur'), 'Kemasan bocor saat dibawa pulang');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan retur'));
      await SetujuiBudi(tester);

      expect(find.text('Retur tersimpan.'), findsOneWidget);
      expect(
        find.descendant(of: find.byType(PanelTugas), matching: find.text('RJ/SLB/260924/POS-001-0001')),
        findsOneWidget,
      );
      expect(find.text('Kembalikan tunai dari laci'), findsOneWidget);
      expect(tester.takeException(), isNull);
      final data = (await Outbox(tester, u, 'ReturPenjualan.Buat')).single;
      expect(data['Nomor'], 'RJ/SLB/260924/POS-001-0001');
      expect(data['Ringkasan'], {'TotalRefund': '33333.33'});
      expect((data['Baris']! as List<Object?>).single, containsPair('Kondisi', 'Rusak'));
      expect((data['Refund']! as List<Object?>).single, containsPair('Jumlah', '33333.33'));

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesai'));
      await tester.scrollUntilVisible(find.text('Retur hari ini'), 200, scrollable: find.byType(Scrollable).first);
      expect(find.text('Retur hari ini'), findsOneWidget);
      expect(find.text('RJ/SLB/260924/POS-001-0001'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('retur offline: pesan jelas bahwa retur butuh internet. Tidak ada yang tersimpan', (tester) async {
    final (u, _) = await MasukRuangKerja(tester, ukuran: ukuranTablet);
    await BukaRiwayat(tester);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await tester.enterText(find.widgetWithText(TextField, 'Nomor struk'), UuidStruk.nomor);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cari struk'));
    expect(find.text('Retur butuh koneksi internet untuk mencari struk.'), findsOneWidget);
    expect(await Outbox(tester, u, 'ReturPenjualan.Buat'), isEmpty);
    await Lepas(tester, u);
  });
}
