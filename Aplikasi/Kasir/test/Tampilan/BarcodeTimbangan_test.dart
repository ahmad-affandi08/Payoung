import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// v3.55 (§9.3): barcode timbangan di layar Jual. Label berat `27 12345 01250 8` menambah Jeruk Medan 1,25 kg; label
/// harga `27 12345 40000 8` (Rp 40.000 ÷ Rp 32.000/kg) juga 1,25 kg; awalan yang tidak diatur tidak menambah apa pun.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester, {required String nilai}) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji(
      barcodeTimbangan: {
        'Aktif': true,
        'Awalan': ['27'],
        'Nilai': nilai,
      },
    );
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogTimbanganUji());
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester);
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Future<void> Pindai(WidgetTester tester, String kode) async {
    final cari = find.byType(TextField).first;
    await tester.tap(cari);
    await tester.enterText(cari, kode);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
  }

  testWidgets('label berat: Jeruk Medan 1,25 kg Rp 40.000. Awalan lain tidak dikenal', (tester) async {
    final u = await MasukJual(tester, nilai: 'Berat');
    await Pindai(tester, '2712345012508');
    expect(find.text('Jeruk Medan'), findsNWidgets(2), reason: 'Ubin katalog + baris keranjang.');
    expect(find.textContaining('1,25'), findsWidgets);
    expect(find.text('Rp 40.000'), findsWidgets);

    await Pindai(tester, '2912345012502');
    // Awalan 29 tidak diatur: bukan barcode timbangan, keranjang tidak berubah (katalog tersaring kata cari).
    expect(find.text('Jeruk Medan'), findsOneWidget);
    expect(find.textContaining('Keranjang | 1,25 item'), findsOneWidget);
    expect(find.text('Rp 40.000'), findsWidgets);
    await Lepas(tester, u);
  });

  testWidgets('label harga: Rp 40.000 ÷ Rp 32.000/kg = 1,25 kg', (tester) async {
    final u = await MasukJual(tester, nilai: 'Harga');
    await Pindai(tester, '2712345400008');
    expect(find.text('Jeruk Medan'), findsNWidgets(2), reason: 'Ubin katalog + baris keranjang.');
    expect(find.textContaining('1,25'), findsWidgets);
    expect(find.text('Rp 40.000'), findsWidgets);
    await Lepas(tester, u);
  });
}
