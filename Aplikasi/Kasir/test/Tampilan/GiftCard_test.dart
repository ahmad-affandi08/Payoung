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

/// CRM-06 (D-153) di panel Bayar (Ruang Kerja, 360/1280 dp): metode Gift card meminta kode, kartu dicek online
/// (saldo, masa berlaku, alasan tak bisa dipakai), jumlah dibatasi saldo, kode baku server dikirim sebagai `Referensi`
/// pembayaran, dan kartu yang belum dicek / sudah hangus / offline tidak bisa dibayarkan.
void main() {
  const idMetode = '01K5MTD0000000000000GIFT01';

  Future<LingkunganUji> MasukJual(
    WidgetTester tester,
    Size ukuran, {
    required Future<http.Response> Function(http.Request) cek,
  }) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(giftCard: true);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/gift-card/cek')) {
        return cek(p);
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

  Finder TombolUtamaBayar() => find.ancestor(
    of: find.textContaining(RegExp(r'^(Selesaikan pembayaran|Tambah pembayaran)')),
    matching: find.byType(FilledButton),
  );

  Future<void> BukaBayar(WidgetTester tester) async {
    await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').first);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Gift card'));
  }

  Future<void> CekKode(WidgetTester tester, String kode) async {
    await tester.enterText(find.widgetWithText(TextField, 'Kode gift card'), kode);
    await tester.pump();
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cek saldo'));
    await Tunggu(tester, const Duration(milliseconds: 200));
  }

  http.Response Kartu({String saldo = '20000.00', bool bisa = true, String? alasan}) => JsonUji({
    'Kode': 'AB23-CD45-EF67',
    'Saldo': saldo,
    'Status': bisa ? 'Aktif' : 'Hangus',
    'BerlakuSampai': '2026-12-31',
    'BisaDipakai': bisa,
    'Alasan': alasan,
  });

  Future<List<Map<String, Object?>>> Outbox(WidgetTester tester, LingkunganUji u, String jenis) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == jenis)
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .toList();

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('bayar sebagian dengan gift card di ${ukuran.width.toInt()} dp: cek online, kode baku jadi Referensi', (
      tester,
    ) async {
      final isiCek = <Map<String, Object?>>[];
      final u = await MasukJual(
        tester,
        ukuran,
        cek: (p) async {
          isiCek.add(jsonDecode(p.body) as Map<String, Object?>);
          return Kartu(saldo: '10000.00');
        },
      );
      await BukaBayar(tester);

      await CekKode(tester, 'ab23 cd45 ef67');
      expect(isiCek.single, {'Kode': 'ab23 cd45 ef67'});
      expect(find.textContaining('Saldo GC-••••-••••-EF67'), findsOneWidget);
      expect(find.text('Berlaku sampai 2026-12-31'), findsOneWidget);
      expect(find.text('Rp 10.000'), findsWidgets);

      // Jumlah bawaan = saldo (tagihan Rp 16.500 lebih besar); bayar dengan kartu lalu sisanya tunai.
      await Ketuk(tester, TombolUtamaBayar());
      expect(find.widgetWithText(ChoiceChip, 'Gift card'), findsNothing, reason: 'Satu kartu per transaksi.');
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.text('Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final jual = (await Outbox(tester, u, 'Penjualan.Buat')).single;
      final pembayaran = (jual['Pembayaran']! as List<Object?>).cast<Map<String, Object?>>();
      expect(pembayaran.first, containsPair('UuidMetodePembayaran', idMetode));
      expect(pembayaran.first, containsPair('Jumlah', '10000.00'));
      expect(pembayaran.first, containsPair('Referensi', 'AB23-CD45-EF67'));
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets(
    'tanpa cek, kode salah, kartu hangus, offline, dan jumlah melebihi saldo tidak bisa dibayarkan (1280 dp)',
    (tester) async {
      var panggilan = 0;
      final u = await MasukJual(
        tester,
        const Size(1280, 900),
        cek: (p) async {
          panggilan++;
          final kode = (jsonDecode(p.body) as Map<String, Object?>)['Kode'];
          return switch (kode) {
            'SALAH' => JsonUji({
              'Galat': {
                'Kode': 'GiftCardTidakDitemukan',
                'Pesan': 'Kode gift card tidak ditemukan. Periksa lagi kodenya.',
              },
            }, 404),
            'HANGUS' => Kartu(saldo: '50000.00', bisa: false, alasan: 'Kartu ini sudah hangus.'),
            'OFFLINE' => throw http.ClientException('offline'),
            _ => Kartu(),
          };
        },
      );
      await BukaBayar(tester);

      // Belum dicek.
      await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '10000');
      await tester.enterText(find.widgetWithText(TextField, 'Kode gift card'), 'AB23-CD45-EF67');
      await tester.pump();
      await Ketuk(tester, TombolUtamaBayar());
      expect(find.textContaining('Cek kode gift card dulu'), findsOneWidget);
      expect(panggilan, 0);

      await CekKode(tester, 'SALAH');
      expect(find.text('Kode gift card tidak ditemukan. Periksa lagi kodenya.'), findsOneWidget);

      await CekKode(tester, 'HANGUS');
      expect(find.text('Kartu ini sudah hangus.'), findsWidgets);
      await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '10000');
      await tester.pump();
      await Ketuk(tester, TombolUtamaBayar());
      expect(find.text('Kartu ini sudah hangus.'), findsWidgets);

      await CekKode(tester, 'OFFLINE');
      expect(find.textContaining('perlu dicek online'), findsOneWidget);

      // Kartu sah tetapi jumlah melebihi saldo.
      await CekKode(tester, 'AB23-CD45-EF67');
      await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '25000');
      await tester.pump();
      await Ketuk(tester, TombolUtamaBayar());
      expect(find.textContaining('Saldo gift card tinggal Rp 20.000'), findsOneWidget);

      // Mengubah kode setelah dicek membatalkan hasil cek.
      await tester.enterText(find.widgetWithText(TextField, 'Kode gift card'), 'AB23-CD45-EF68');
      await tester.pump();
      expect(find.textContaining('Saldo GC-'), findsNothing);
      expect((await Outbox(tester, u, 'Penjualan.Buat')), isEmpty);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    },
  );
}
