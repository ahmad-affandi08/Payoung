import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Pembayaran tersimpan membunyikan tanda berhasil sekali; mode latihan tidak berbunyi karena tidak ada penjualan.
void main() {
  Future<(SuaraPembayaranTiruan, LingkunganUji)> MasukJual(WidgetTester tester) async {
    final suara = SuaraPembayaranTiruan();
    final u = LingkunganUji.Buat();
    final awal = DataAwalUji();
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
    await PasangAplikasi(tester, u, suaraPembayaran: suara);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    return (suara, u);
  }

  Future<void> BayarTunaiPas(WidgetTester tester) async {
    await tester.tap(find.byWidgetPredicate((w) => w is UbinProduk && w.nama == 'Americano Panas'));
    await Tunggu(tester);
    await tester.sendKeyEvent(LogicalKeyboardKey.f8);
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Tunai'));
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Uang pas'));
    await Tunggu(tester);
  }

  testWidgets('pembayaran tersimpan membunyikan suara berhasil sekali', (tester) async {
    final (suara, u) = await MasukJual(tester);
    expect(suara.jumlahMainkan, 0);

    await BayarTunaiPas(tester);

    expect(find.text('Pembayaran berhasil'), findsOneWidget);
    expect(suara.jumlahMainkan, 1);
    await Lepas(tester, u);
  });
}
