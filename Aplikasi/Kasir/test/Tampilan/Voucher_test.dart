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

/// F-16c bagian 2 di layar Jual: kode voucher dimasukkan di panel "Voucher & diskon" (wajib online), promo voucher
/// langsung mengurangi total, voucher bisa dihapus (dilepas di server); voucher habis menampilkan pesan server.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, Size ukuran) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (p.url.path.endsWith('/voucher/lepas')) {
        return http.Response('', 204);
      }
      if (p.url.path.endsWith('/voucher/pesan')) {
        final kode = ((jsonDecode(p.body) as Map<String, Object?>)['Kode']! as String).toUpperCase();
        if (kode == 'HABIS') {
          return http.Response(
            jsonEncode({
              'Galat': {'Kode': 'VoucherHabis', 'Pesan': 'Voucher HABIS sudah habis dipakai.'},
            }),
            409,
            headers: {'content-type': 'application/json'},
          );
        }
        return JsonUji({
          'Voucher': {'Kode': kode, 'UuidPromo': '01K5PROMO00000000000000009', 'SisaPakai': 0},
          'Promo': {
            'Uuid': '01K5PROMO00000000000000009',
            'Kode': 'VCR-HEMAT',
            'Nama': 'Voucher hemat',
            'Prioritas': 0,
            'Eksklusif': false,
            'Definisi': {
              'WajibVoucher': true,
              'Aksi': {'Jenis': 'DiskonTetapPesanan', 'Jumlah': '5000'},
            },
          },
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
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1000), Size(360, 740)]) {
    testWidgets(
      'pakai voucher HEMAT → potongan Rp 5.000. Voucher habis → pesan server. Hapus voucher (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = await MasukJual(tester, ukuran);
        await Ketuk(tester, find.byWidgetPredicate((w) => w is UbinProduk && w.nama.startsWith('Americano')));
        if (ukuran.width < 600) {
          await Ketuk(tester, find.textContaining('Keranjang | '));
        }
        await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Diskon'));
        expect(find.text('Voucher & diskon'), findsOneWidget);

        await tester.enterText(find.widgetWithText(TextField, 'Kode voucher'), 'habis');
        await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Pakai voucher'));
        expect(find.text('Voucher HABIS sudah habis dipakai.'), findsOneWidget);

        // Kode kedua dikirim dengan Enter (pemindai/keyboard fisik).
        await tester.enterText(find.widgetWithText(TextField, 'Kode voucher'), 'hemat');
        await tester.testTextInput.receiveAction(TextInputAction.done);
        await Tunggu(tester);
        expect(find.text('HEMAT'), findsOneWidget);
        expect(find.text('Voucher hemat | −Rp 5.000'), findsOneWidget);
        if (ukuran.width >= 600) {
          expect(find.text('Promo Voucher hemat'), findsWidgets);
        }

        await Ketuk(tester, find.widgetWithText(TextButton, 'Hapus voucher'));
        expect(find.text('Promo Voucher hemat'), findsNothing);
        expect(find.widgetWithText(TextField, 'Kode voucher'), findsOneWidget);
        expect(u.server.permintaan.last.url.path, endsWith('/api/pos/v1/voucher/lepas'));
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }
}
