import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// v3.29 ongkir penjualan kasir kanal Antar di layar Jual (360/1280 dp): pilih kanal Antar → baris ongkir muncul,
/// isi Rp 15.000 → ringkasan menampilkan Ongkir dan Gratis ongkir dari promo, outbox membawa BiayaKirim & DiskonKirim.
void main() {
  final promo = {
    'ModeResolusi': 'Terbaik',
    'Promo': [
      {
        'Uuid': '01K5PROMO0000000000ONGK1R1',
        'Kode': 'ONGKIR-50K',
        'Nama': 'Gratis ongkir belanja Rp 50.000',
        'Prioritas': 0,
        'Eksklusif': false,
        'MulaiPada': null,
        'SelesaiPada': null,
        'KuotaTersisa': null,
        'Definisi': {
          'MinimalSubtotal': '50000',
          'Aksi': {'Jenis': 'GratisOngkir'},
        },
      },
    ],
  };

  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode(promo));
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/promo')) {
        return JsonUji(promo);
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

  Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith(nama));

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('kanal Antar: isi ongkir, gratis ongkir dari promo, outbox berongkir (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await MasukJual(tester, ukuran);
      await Ketuk(tester, Ubin('Croissant'));
      await Ketuk(tester, Ubin('Croissant'));
      if (ukuran.width < 600) {
        await Ketuk(tester, find.textContaining('Keranjang |').first);
      }
      await BukaOpsiKeranjang(tester);
      expect(find.byKey(const ValueKey('IsiOngkir')), findsNothing, reason: 'Belum kanal Antar.');

      await Ketuk(tester, find.byKey(const ValueKey('PilihKanal')));
      await Ketuk(tester, find.byKey(const ValueKey('Kanal-Antar')));
      expect(find.text('Tanpa ongkir | ketuk untuk mengisi'), findsOneWidget);

      await Ketuk(tester, find.byKey(const ValueKey('IsiOngkir')));
      await tester.enterText(find.byKey(const ValueKey('NilaiOngkir')), '15000');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Simpan'));
      expect(find.text('Ongkir Rp 15.000 | ketuk untuk mengubah'), findsOneWidget);
      await tester.scrollUntilVisible(find.text('Gratis ongkir'), 100, scrollable: find.byType(Scrollable).last);
      expect(find.text('Gratis ongkir'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await Ketuk(tester, find.widgetWithText(FilledButton, 'Bayar').last);
      await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Rp 100.000'));
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Selesaikan pembayaran'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final data = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Penjualan.Buat')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .single;
      expect((data['Kanal'], data['BiayaKirim'], data['DiskonKirim']), ('Antar', '15000.00', '15000.00'));
      await Lepas(tester, u);
    });
  }
}
