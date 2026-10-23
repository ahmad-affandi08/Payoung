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

/// F-16a di layar Jual (PRD §17.2.3 F2, §17.2.7): panel pelanggan dari baris pelanggan di keranjang atau F2, pelanggan
/// baru saat offline, dan penjualan membawa `UuidPelanggan` setelah `Pelanggan.Buat` di outbox.
void main() {
  Future<LingkunganUji> MasukJual(
    WidgetTester tester,
    Size ukuran, {
    Map<String, Object?>? katalog,
    Map<String, Object?>? hasilCari,
    Map<String, Object?>? saldoPoin,
    Map<String, Object?>? dataAwal,
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal ?? DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(katalog ?? KatalogUji());
      }
      if (saldoPoin != null && p.url.path.endsWith('/poin')) {
        return JsonUji(saldoPoin);
      }
      if (hasilCari != null && p.url.path.endsWith('/pelanggan')) {
        return JsonUji(hasilCari);
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

  for (final (nama, ukuran, pakaiF2) in [
    ('1280', const Size(1280, 900), false),
    ('800', const Size(800, 1280), true),
  ]) {
    testWidgets('pelanggan baru offline di $nama dp → dipakai di transaksi → UuidPelanggan di outbox', (tester) async {
      final u = await MasukJual(tester, ukuran);
      await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama == 'Americano Panas'));

      if (pakaiF2) {
        await tester.sendKeyEvent(LogicalKeyboardKey.f2);
        await Tunggu(tester);
      } else {
        await KetukOpsiKeranjang(tester, find.byKey(const ValueKey('PilihPelanggan')));
      }
      expect(find.byType(PanelPelanggan), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Cari nama atau nomor HP (min. 3 huruf)'), 'budi');
      await Tunggu(tester, const Duration(milliseconds: 600));
      expect(find.textContaining('Offline: hanya pelanggan'), findsOneWidget);
      expect(find.text('Pelanggan tidak ditemukan. Tambahkan sebagai pelanggan baru.'), findsOneWidget);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Pelanggan baru'));
      expect(find.widgetWithText(TextField, 'Nama pelanggan'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Nama pelanggan'), 'Budi Santoso');
      await tester.enterText(find.widgetWithText(TextField, 'No. HP/WA'), '0813');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan & pakai'));
      expect(find.text('Nomor HP tidak valid. Contoh: 0812-3456-7890.'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'No. HP/WA'), '0813-1111-2222');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan & pakai'));

      expect(find.byType(PanelPelanggan), findsNothing);
      await BukaOpsiKeranjang(tester);
      expect(find.text('Budi Santoso | 0813****2222'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Uang pas'));
      await Tunggu(tester);
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final outbox = await tester.runAsync(() => u.db.select(u.db.outbox).get());
      final pelanggan = outbox!.firstWhere((o) => o.Jenis == 'Pelanggan.Buat');
      final jual = outbox.firstWhere((o) => o.Jenis == 'Penjualan.Buat');
      expect(pelanggan.Id, lessThan(jual.Id));
      expect((jsonDecode(jual.Data) as Map<String, Object?>)['UuidPelanggan'], pelanggan.Uuid);

      // Transaksi baru kembali ke pelanggan umum.
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Transaksi baru'));
      expect(RingkasanOpsiKeranjang(tester), contains('Pelanggan umum'));
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('F-16b: pelanggan Gold dari pencarian online → harga tier di keranjang, tier & poin tampil (1280 dp)', (
    tester,
  ) async {
    final katalog = KatalogUji();
    katalog['DaftarHarga'] = [
      {
        'Uuid': '01K5DH0000000000000000G0LD',
        'Nama': 'Harga member Gold',
        'UuidOutlet': null,
        'Kanal': null,
        'TierPelanggan': 'GOLD',
        'MulaiPada': null,
        'SelesaiPada': null,
        'Prioritas': 10,
        'Aktif': true,
      },
    ];
    (katalog['ProdukHarga']! as List<Object?>).add({
      ...HargaUji('01K5HRG000000000000CR0G0LD', UuidUji.croissant, UuidUji.psCroissant, '22000.00'),
      'UuidDaftarHarga': '01K5DH0000000000000000G0LD',
    });
    final u = await MasukJual(
      tester,
      const Size(1280, 900),
      katalog: katalog,
      hasilCari: {
        'Pelanggan': [
          {
            'Uuid': '01K5PELANGGAN0000000000001',
            'Nama': 'Ani Rahmawati',
            'NoHp': '0812****7890',
            'KodeTier': 'GOLD',
            'NamaTier': 'Gold',
            'SaldoPoin': 120,
          },
        ],
      },
    );
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Croissant')));
    // 25.000 + PBJT 10% = 27.500.
    expect(find.text('Rp 27.500'), findsWidgets);

    await KetukOpsiKeranjang(tester, find.byKey(const ValueKey('PilihPelanggan')));
    await tester.enterText(find.widgetWithText(TextField, 'Cari nama atau nomor HP (min. 3 huruf)'), 'ani');
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.text('0812****7890 | Gold | 120 poin'), findsOneWidget);
    await Ketuk(tester, find.text('Ani Rahmawati'));

    await BukaOpsiKeranjang(tester);
    expect(find.text('Ani Rahmawati | 0812****7890 | Gold'), findsOneWidget);
    // Harga Gold 22.000 + PBJT 10% = 24.200.
    expect(find.text('Rp 24.200'), findsWidgets);
    expect(find.text('Rp 27.500'), findsNothing);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('F-16b: tukar 50 poin (online) → potongan sebelum pajak di keranjang; offline ditolak (1280 dp)', (
    tester,
  ) async {
    final u = await MasukJual(
      tester,
      const Size(1280, 900),
      hasilCari: {
        'Pelanggan': [
          {'Uuid': '01K5PELANGGAN0000000000001', 'Nama': 'Ani Rahmawati', 'NoHp': '0812****7890', 'SaldoPoin': 120},
        ],
      },
      saldoPoin: {
        'Pelanggan': {'Uuid': '01K5PELANGGAN0000000000001', 'SaldoPoin': 120},
        'TukarPoin': {'Berlaku': true, 'NilaiTukarPoin': '100.00', 'MinimalTukarPoin': 10},
      },
    );
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Croissant')));
    await KetukOpsiKeranjang(tester, find.byKey(const ValueKey('PilihPelanggan')));
    await tester.enterText(find.widgetWithText(TextField, 'Cari nama atau nomor HP (min. 3 huruf)'), 'ani');
    await Tunggu(tester, const Duration(milliseconds: 600));
    await Ketuk(tester, find.text('Ani Rahmawati'));

    await KetukOpsiKeranjang(tester, find.textContaining('Ani Rahmawati | 0812****7890'));
    await Ketuk(tester, find.text('Tukar poin'));
    expect(find.text('Saldo 120 poin | 1 poin = Rp 100 | minimal 10 poin'), findsOneWidget);
    // Sisa belanja 25.000 → maksimal min(120, 250) = 120 poin.
    expect(find.text('Bisa ditukar sampai 120 poin untuk belanja ini.'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Poin yang ditukar'), '5');
    await tester.pump();
    await Ketuk(tester, find.text('Pakai 5 poin'));
    expect(find.text('Minimal tukar 10 poin.'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextField, 'Poin yang ditukar'), '50');
    await tester.pump();
    expect(find.text('−Rp 5.000'), findsOneWidget);
    await Ketuk(tester, find.text('Pakai 50 poin'));

    // (25.000 − 5.000) + PBJT 10% = 22.000.
    expect(find.text('Tukar 50 poin'), findsOneWidget);
    expect(find.text('Rp 22.000'), findsWidgets);

    u.server.penangan = (p) async => throw http.ClientException('offline');
    await KetukOpsiKeranjang(tester, find.textContaining('Ani Rahmawati | 0812****7890'));
    await Ketuk(tester, find.text('Tukar poin'));
    expect(find.text('Tukar poin perlu koneksi internet. Coba lagi saat perangkat online.'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('F-12: Tempo hanya untuk pelanggan terpilih; di atas limit → PIN penyetuju; outbox UuidPenyetujuTempo', (
    tester,
  ) async {
    final u = await MasukJual(
      tester,
      const Size(1280, 900),
      dataAwal: DataAwalUji(tempo: true),
      hasilCari: {
        'Pelanggan': [
          {
            'Uuid': '01K5PELANGGAN0000000000009',
            'Nama': 'Toko Makmur Jaya',
            'NoHp': '0813****0001',
            'LimitKredit': '20000.00',
            'SisaPiutang': '0.00',
            'HariLewatJatuhTempo': 0,
          },
        ],
      },
    );
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Croissant')));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
    expect(find.widgetWithText(ChoiceChip, 'Tempo'), findsNothing, reason: 'Tanpa pelanggan, Tempo disembunyikan.');
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await Tunggu(tester);

    await KetukOpsiKeranjang(tester, find.byKey(const ValueKey('PilihPelanggan')));
    await tester.enterText(find.widgetWithText(TextField, 'Cari nama atau nomor HP (min. 3 huruf)'), 'makmur');
    await Tunggu(tester, const Duration(milliseconds: 600));
    await Ketuk(tester, find.text('Toko Makmur Jaya'));

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar'));
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tempo'));
    expect(find.text('Toko Makmur Jaya: limit Rp 20.000, piutang Rp 0.'), findsOneWidget);
    // 25.000 + PBJT 10% = 27.500 > limit 20.000.
    expect(find.text('Perlu PIN penyetuju: piutang Rp 27.500 melebihi limit Rp 20.000.'), findsOneWidget);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester);
    expect(find.text('Pembayaran berhasil'), findsOneWidget);

    final outbox = await tester.runAsync(() => u.db.select(u.db.outbox).get());
    final data = jsonDecode(outbox!.firstWhere((o) => o.Jenis == 'Penjualan.Buat').Data) as Map<String, Object?>;
    expect(data['UuidPelanggan'], '01K5PELANGGAN0000000000009');
    expect(data['UuidPenyetujuTempo'], '01K5STAF000000000000000002');
    expect(
      ((data['Pembayaran']! as List<Object?>).single! as Map<String, Object?>)['UuidMetodePembayaran'],
      '01K5MTD0000000000000000007',
    );
    final cache = await tester.runAsync(() => u.db.select(u.db.pelangganLokal).get());
    expect(cache!.single.SisaPiutang, '27500.00');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
