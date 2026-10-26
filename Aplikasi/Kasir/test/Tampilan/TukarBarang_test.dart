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
import '../Pendukung/StrukUji.dart';

/// K-11 bagian 2 (F-09 "tukar barang: retur + penjualan baru dalam satu layar"): retur dari struk dengan cara refund
/// "Tukar barang" belum menyimpan apa pun; kasir memilih barang pengganti di layar Jual, nilai retur menjadi
/// pembayaran pertama, dan saat pembayaran selesai retur (refund `Tukar` [+ `Tunai` selisih]) dan penjualan pengganti
/// (`UuidReturTukar`) tersimpan bersama — retur lebih dulu di outbox.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(tukar: true);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.SiapkanKatalog();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/penjualan/cari')) {
        return JsonUji(StrukUji());
      }
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

  Future<void> Ketuk(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.ensureVisible(f);
    await tester.pump();
    await tester.tap(f);
    await Tunggu(tester);
  }

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);

  /// Retur 1 Kopi Susu Literan (Rp 33.333,33) dengan cara refund Tukar barang, disetujui Budi.
  Future<void> MulaiTukar(WidgetTester tester) async {
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await tester.enterText(find.widgetWithText(TextField, 'Nomor struk'), UuidStruk.nomor);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')));
    await tester.enterText(find.byKey(const ValueKey('JumlahRetur-${UuidStruk.kopiLiter}')), '1');
    await Tunggu(tester);
    await tester.ensureVisible(find.widgetWithText(TextField, 'Alasan retur'));
    await tester.enterText(find.widgetWithText(TextField, 'Alasan retur'), 'Salah ukuran, tukar dengan yang lain');
    await Ketuk(tester, find.text('Tukar barang'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Pilih barang pengganti'));
    // PIN penyetuju tidak diminta di sini: kasir memilih barang pengganti dulu, PIN sekali di akhir pembayaran.
    expect(find.text('Persetujuan retur tukar barang'), findsNothing);
    expect(find.byType(PanelTugas), findsNothing, reason: 'Lembar retur tertutup, layar Jual terbuka.');
  }

  /// PIN penyetuju (Budi) diminta saat pembayaran diselesaikan.
  Future<void> SetujuiBudi(WidgetTester tester) async {
    expect(find.text('Persetujuan retur tukar barang'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester, const Duration(seconds: 1));
  }

  Future<List<({String jenis, Map<String, Object?> data})>> Outbox(WidgetTester tester, LingkunganUji u) async => [
    for (final o in (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!)
      if (o.Jenis == 'ReturPenjualan.Buat' || o.Jenis == 'Penjualan.Buat')
        (jenis: o.Jenis, data: jsonDecode(o.Data) as Map<String, Object?>),
  ];

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets(
      'pengganti lebih mahal: nilai retur membayar, sisa tunai. Retur & penjualan tersimpan bersama (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await Masuk(tester, ukuran);
        await MulaiTukar(tester);
        expect(await Outbox(tester, u), isEmpty, reason: 'Retur belum disimpan sebelum pembayaran.');

        await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
        await Ketuk(tester, Ubin('Americano Panas'));
        if (ukuran.width < 600) {
          expect(find.text('Tukar barang | 2 baris'), findsOneWidget);
          await Ketuk(tester, find.textContaining('Tukar barang |').first);
        } else {
          expect(find.textContaining('Tukar barang | Rp 33.333,33'), findsOneWidget);
        }
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
        expect(find.textContaining('Tukar barang dari ${UuidStruk.nomor}'), findsOneWidget);
        // 40.000 + PBJT 10% = 44.000; nilai retur 33.333,33 otomatis jadi pembayaran pertama, sisanya uang pas
        // dibulatkan ke rupiah utuh (Rp 10.667, kembalian 33 sen).
        expect(find.text('Rp 33.333,33'), findsWidgets);
        await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
        await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
        await SetujuiBudi(tester);
        expect(find.text('Pembayaran berhasil'), findsOneWidget);
        expect(find.textContaining('Retur tukar barang RJ/'), findsOneWidget);
        expect(tester.takeException(), isNull);

        final outbox = await Outbox(tester, u);
        final retur = outbox.firstWhere((o) => o.jenis == 'ReturPenjualan.Buat');
        final jual = outbox.firstWhere((o) => o.jenis == 'Penjualan.Buat');
        expect(
          outbox.indexOf(retur),
          lessThan(outbox.indexOf(jual)),
          reason: 'Retur dikirim sebelum penjualan pengganti.',
        );
        expect(retur.data['Refund'], [containsPair('Jumlah', '33333.33')]);
        expect(
          ((retur.data['Refund']! as List<Object?>).single! as Map<String, Object?>)['UuidMetodePembayaran'],
          '01K5MTD000000000000T0KAR01',
        );
        expect(jual.data['UuidReturTukar'], isNotNull);
        final bayar = (jual.data['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
        expect(bayar.map((b) => (b['UuidMetodePembayaran'], b['Jumlah'])), [
          ('01K5MTD000000000000T0KAR01', '33333.33'),
          ('01K5MTD0000000000000000001', '10667.00'),
        ]);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('pengganti lebih murah: tukar sebesar total, selisih dikembalikan tunai lewat refund retur', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await MulaiTukar(tester);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
    expect(find.textContaining('kembalikan Rp 16.833,33 tunai'), findsOneWidget);
    await Ketuk(tester, find.text('Selesaikan pembayaran'));
    await SetujuiBudi(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    expect(find.text('Kembalikan selisih tukar'), findsOneWidget);
    expect(find.text('Rp 16.833,33'), findsWidgets);

    final outbox = await Outbox(tester, u);
    final refund = (outbox.firstWhere((o) => o.jenis == 'ReturPenjualan.Buat').data['Refund']! as List<Object?>)
        .cast<Map<String, Object?>>();
    expect(refund.map((r) => (r['UuidMetodePembayaran'], r['Jumlah'])), [
      ('01K5MTD000000000000T0KAR01', '16500.00'),
      ('01K5MTD0000000000000000001', '16833.33'),
    ]);
    final bayar = (outbox.firstWhere((o) => o.jenis == 'Penjualan.Buat').data['Pembayaran']! as List<Object?>)
        .cast<Map<String, Object?>>();
    expect(bayar.map((b) => (b['UuidMetodePembayaran'], b['Jumlah'])), [('01K5MTD000000000000T0KAR01', '16500.00')]);
    await Lepas(tester, u);
  });

  testWidgets('batalkan transaksi tukar: tidak ada retur maupun penjualan tersimpan', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await MulaiTukar(tester);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.byTooltip('Batalkan transaksi'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Batalkan transaksi'));
    expect(find.textContaining('Tukar barang |'), findsNothing);
    expect(await Outbox(tester, u), isEmpty);
    await Lepas(tester, u);
  });

  testWidgets('tukar barang tidak bisa ditahan (retur & PIN penyetuju akan terbuang), pesannya jelas', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await MulaiTukar(tester);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.text('Tahan').first);

    expect(find.textContaining('Tukar barang sedang berjalan'), findsOneWidget);
    expect(find.textContaining('Tukar barang |'), findsOneWidget, reason: 'Mode tukar tetap aktif.');
    expect(await tester.runAsync(() => u.db.select(u.db.pesananTertahan).get()), isEmpty);
    await Lepas(tester, u);
  });

  testWidgets('tukar barang dengan keranjang kosong tetap bisa dibatalkan (tidak buntu)', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await MulaiTukar(tester);
    expect(find.textContaining('Tukar barang |'), findsOneWidget);

    await Ketuk(tester, find.byTooltip('Batalkan transaksi'));
    expect(find.text('Batalkan tukar barang?'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Batalkan transaksi'));

    expect(find.textContaining('Tukar barang |'), findsNothing);
    expect(await Outbox(tester, u), isEmpty);
    await Lepas(tester, u);
  });

  testWidgets('batal di dialog PIN: pembayaran tidak dilanjutkan, tukar tetap utuh, PIN hanya diminta sekali', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await MulaiTukar(tester);
    await Ketuk(tester, Ubin('Americano Panas'));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
    await Ketuk(tester, find.text('Selesaikan pembayaran'));
    expect(find.text('Persetujuan retur tukar barang'), findsOneWidget);
    await tester.tapAt(const Offset(2, 2)); // tutup dialog tanpa memilih penyetuju
    await Tunggu(tester);

    expect(find.text('Pembayaran berhasil'), findsNothing);
    expect(find.textContaining('Tukar barang dari'), findsOneWidget, reason: 'Layar Bayar tukar masih terbuka.');
    expect(await Outbox(tester, u), isEmpty);

    await Ketuk(tester, find.text('Selesaikan pembayaran'));
    await SetujuiBudi(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    await Lepas(tester, u);
  });
}
