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

/// K28 (F-09, PRD v4.01): retur tanpa struk di kasir. Barang dicari dari katalog lokal, nilai = harga berlaku + pajak
/// (Croissant Rp 25.000 + PBJT 10% = Rp 27.500), wajib PIN penyetuju ber-izin `penjualan.retur.tanpa-struk`, refund hanya
/// deposit pelanggan atau tukar barang (barang pengganti minimal senilai retur), outbox `ReturPenjualan.TanpaStruk`.
void main() {
  const uuidDeposit = '01K5MTD0000000000000000009';
  const uuidTukar = '01K5MTD000000000000T0KAR01';

  /// Data awal dengan Budi ber-izin retur tanpa struk (bila [izinBudi]).
  Map<String, Object?> DataAwal({bool izinBudi = true}) {
    final data = DataAwalUji(tukar: true, deposit: true);
    data['Staf'] = [
      for (final s in (data['Staf']! as List<Object?>).cast<Map<String, Object?>>())
        if (izinBudi && s['Nama'] == 'Budi Santoso')
          {
            ...s,
            'Izin': [...(s['Izin']! as List<Object?>), 'penjualan.retur.tanpa-struk'],
          }
        else
          s,
    ];
    return data;
  }

  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran, {bool izinBudi = true}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwal(izinBudi: izinBudi);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.SiapkanKatalog();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/pelanggan')) {
        return JsonUji({
          'Pelanggan': [
            {'Uuid': '01K5PELANGGAN0000000000001', 'Nama': 'Ani Rahmawati', 'NoHp': '0812****7890'},
          ],
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

  Future<void> Ketuk(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.ensureVisible(f);
    await tester.pump();
    await tester.tap(f);
    await Tunggu(tester);
  }

  /// Buka retur tanpa struk, tambah [jumlah] Croissant, isi alasan.
  Future<void> IsiRetur(WidgetTester tester, {String jumlah = '2'}) async {
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await Ketuk(tester, find.byKey(const ValueKey('BukaReturTanpaStruk')));
    await tester.enterText(find.byKey(const ValueKey('CariReturTanpaStruk')), 'Croissant');
    await Tunggu(tester);
    await Ketuk(tester, find.byKey(const ValueKey('HasilReturTanpaStruk-${UuidUji.croissant}')));
    final isian = find.descendant(
      of: find.byKey(const ValueKey('BarisTanpaStruk-${UuidUji.croissant}-${UuidUji.psCroissant}')),
      matching: find.byType(TextField),
    );
    await tester.ensureVisible(isian);
    await tester.enterText(isian, jumlah);
    await Tunggu(tester);
    await tester.ensureVisible(find.widgetWithText(TextField, 'Alasan retur'));
    await tester.enterText(find.widgetWithText(TextField, 'Alasan retur'), 'Struk hilang, kemasan masih tersegel');
    await Tunggu(tester);
  }

  Future<void> SetujuiBudi(WidgetTester tester) async {
    await PilihPenyetuju(tester, 'Budi Santoso');
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester, const Duration(seconds: 1));
  }

  Future<List<({String jenis, Map<String, Object?> data})>> Outbox(WidgetTester tester, LingkunganUji u) async => [
    for (final o in (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!)
      if (o.Jenis.startsWith('ReturPenjualan.') || o.Jenis == 'Penjualan.Buat')
        (jenis: o.Jenis, data: jsonDecode(o.Data) as Map<String, Object?>),
  ];

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets(
      'deposit pelanggan: nilai harga berlaku + pajak, PIN Budi, outbox TanpaStruk (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await Masuk(tester, ukuran);
        await IsiRetur(tester);
        // 2 × 25.000 + PBJT 10% = 55.000.
        expect(find.text('Rp 55.000'), findsWidgets);
        await Ketuk(tester, find.text('Deposit pelanggan'));
        await tester.enterText(find.widgetWithText(TextField, 'Pelanggan penerima deposit'), 'ani');
        await Tunggu(tester, const Duration(milliseconds: 600));
        await Ketuk(tester, find.text('Ani Rahmawati'));
        await Ketuk(tester, find.byKey(const ValueKey('SimpanReturTanpaStruk')));
        await SetujuiBudi(tester);
        expect(find.text('Retur tanpa struk tersimpan.'), findsOneWidget);
        expect(find.text('Masuk deposit Ani Rahmawati'), findsOneWidget);
        expect(tester.takeException(), isNull);

        final retur = (await Outbox(tester, u)).single;
        expect(retur.jenis, 'ReturPenjualan.TanpaStruk');
        expect(retur.data['UuidPelanggan'], '01K5PELANGGAN0000000000001');
        expect(retur.data['UuidPenyetuju'], '01K5STAF000000000000000002');
        expect(retur.data['Ringkasan'], {'TotalRefund': '55000.00'});
        expect(retur.data['Refund'], [containsPair('UuidMetodePembayaran', uuidDeposit)]);
        expect(retur.data['Baris'], [
          allOf(
            containsPair('UuidProduk', UuidUji.croissant),
            containsPair('UuidProdukSatuan', UuidUji.psCroissant),
            containsPair('Jumlah', '2.0000'),
            containsPair('Kondisi', 'LayakJual'),
          ),
        ]);
        expect(retur.data.containsKey('UuidPenjualanAsal'), isFalse);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('jumlah lebih dari 4 desimal ditolak sebagai isian salah, layar tidak jatuh dan bisa diperbaiki', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await IsiRetur(tester, jumlah: '1,23456');

    expect(tester.takeException(), isNull);
    expect(find.text('Isi jumlah'), findsOneWidget);
    final isian = find.descendant(
      of: find.byKey(const ValueKey('BarisTanpaStruk-${UuidUji.croissant}-${UuidUji.psCroissant}')),
      matching: find.byType(TextField),
    );
    await tester.enterText(isian, '2');
    await Tunggu(tester);
    expect(find.text('Isi jumlah'), findsNothing);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('tanpa penyetuju berizin: dialog PIN tidak menawarkan staf. Produk ber-batch tidak bisa dipilih', (
    tester,
  ) async {
    final u = await Masuk(tester, const Size(1280, 900), izinBudi: false);
    await tester.tap(find.text('Riwayat').last);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Retur dari struk'));
    await Ketuk(tester, find.byKey(const ValueKey('BukaReturTanpaStruk')));
    await tester.enterText(find.byKey(const ValueKey('CariReturTanpaStruk')), 'Susu UHT');
    await Tunggu(tester);
    expect(find.text('Hanya bisa diretur dengan struk'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('HasilReturTanpaStruk-${UuidUji.susuUht}')));
    await Tunggu(tester);
    expect(find.byKey(const ValueKey('BarisTanpaStruk-${UuidUji.susuUht}-${UuidUji.psSusu}')), findsNothing);

    await IsiReturLangsung(tester);
    await Ketuk(tester, find.text('Deposit pelanggan'));
    await tester.enterText(find.widgetWithText(TextField, 'Pelanggan penerima deposit'), 'ani');
    await Tunggu(tester, const Duration(milliseconds: 600));
    await Ketuk(tester, find.text('Ani Rahmawati'));
    await Ketuk(tester, find.byKey(const ValueKey('SimpanReturTanpaStruk')));
    expect(find.textContaining('Belum ada pemilik atau pengguna berizin'), findsOneWidget);
    expect(find.text('PIN Budi Santoso'), findsNothing);
    expect(await Outbox(tester, u), isEmpty);
    await Lepas(tester, u);
  });

  testWidgets(
    'tukar barang: pengganti lebih murah ditolak (tidak ada uang tunai), ditambah → retur & penjualan bersama',
    (tester) async {
      final u = await Masuk(tester, const Size(1280, 900));
      await IsiRetur(tester, jumlah: '1');
      await Ketuk(tester, find.text('Tukar barang'));
      await Ketuk(tester, find.byKey(const ValueKey('SimpanReturTanpaStruk')));
      // PIN tidak diminta di sini: barang pengganti dipilih dulu, PIN sekali di akhir pembayaran.
      expect(find.text('Persetujuan retur tukar barang'), findsNothing);
      expect(find.byType(PanelTugas), findsNothing, reason: 'Lembar retur tertutup, layar Jual terbuka.');
      expect(await Outbox(tester, u), isEmpty, reason: 'Retur belum disimpan sebelum pembayaran.');

      Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);
      await Ketuk(tester, Ubin('Americano Panas'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
      expect(find.textContaining('Tukar barang tanpa struk'), findsOneWidget);
      expect(find.textContaining('tanpa struk tidak dikembalikan tunai'), findsOneWidget);
      await Ketuk(tester, find.text('Selesaikan pembayaran'));
      // Transaksi yang pasti ditolak (pengganti lebih murah) tidak meminta PIN dulu.
      expect(find.text('Persetujuan retur tukar barang'), findsNothing);
      expect(find.textContaining('Tambah barang pengganti sampai minimal Rp 27.500'), findsOneWidget);
      expect(await Outbox(tester, u), isEmpty);

      // Tambah Croissant (27.500): total 44.000 = tukar 27.500 + tunai 16.500.
      await Ketuk(tester, find.byTooltip('Kembali ke keranjang'));
      await Ketuk(tester, Ubin('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      await SetujuiBudi(tester);
      expect(find.text('Pembayaran berhasil'), findsOneWidget);
      expect(tester.takeException(), isNull);
      final outbox = await Outbox(tester, u);
      final retur = outbox.firstWhere((o) => o.jenis == 'ReturPenjualan.TanpaStruk');
      final jual = outbox.firstWhere((o) => o.jenis == 'Penjualan.Buat');
      expect(outbox.indexOf(retur), lessThan(outbox.indexOf(jual)));
      final refund = (retur.data['Refund']! as List<Object?>).cast<Map<String, Object?>>();
      expect(refund.map((r) => (r['UuidMetodePembayaran'], r['Jumlah'])), [(uuidTukar, '27500.00')]);
      expect(jual.data['UuidReturTukar'], isNotNull);
      final bayar = (jual.data['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
      expect(bayar.map((b) => (b['UuidMetodePembayaran'], b['Jumlah'])), [
        (uuidTukar, '27500.00'),
        ('01K5MTD0000000000000000001', '16500.00'),
      ]);
      await Lepas(tester, u);
    },
  );
}

/// Tambah 1 Croissant & alasan tanpa membuka lembar (lembar sudah terbuka).
Future<void> IsiReturLangsung(WidgetTester tester) async {
  await tester.enterText(find.byKey(const ValueKey('CariReturTanpaStruk')), 'Croissant');
  await Tunggu(tester);
  final hasil = find.byKey(const ValueKey('HasilReturTanpaStruk-${UuidUji.croissant}'));
  await tester.ensureVisible(hasil);
  await tester.tap(hasil);
  await Tunggu(tester);
  await tester.ensureVisible(find.widgetWithText(TextField, 'Alasan retur'));
  await tester.enterText(find.widgetWithText(TextField, 'Alasan retur'), 'Struk hilang, kemasan masih tersegel');
  await Tunggu(tester);
}
