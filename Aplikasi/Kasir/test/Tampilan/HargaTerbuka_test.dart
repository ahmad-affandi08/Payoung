import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Jual/DialogHargaTerbuka.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-25 item harga terbuka di layar Jual: mengetuk "Barang lain-lain" membuka dialog harga; harga & keterangan masuk
/// keranjang; ketuk lagi = baris baru (tidak digabung). "Jasa servis ringan" terisi harga daftar sebagai saran; Batal
/// tidak mengubah keranjang.
void main() {
  Future<LingkunganUji> MasukJual(WidgetTester tester) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalUji();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogHargaTerbukaUji());
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

  Future<void> Cari(WidgetTester tester, String kata) async {
    final cari = find.byType(TextField).first;
    await tester.tap(cari);
    await tester.enterText(cari, kata);
    await Tunggu(tester);
  }

  testWidgets('barang lain-lain: ketik harga & keterangan. Ketuk lagi = baris baru', (tester) async {
    final u = await MasukJual(tester);
    await Cari(tester, 'lain');
    await tester.tap(find.text('Barang lain-lain'));
    await Tunggu(tester);
    expect(find.byType(DialogHargaTerbuka), findsOneWidget);

    // Kosong ditolak.
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah'));
    await tester.pump();
    expect(find.text('Ketik harga lebih dari Rp0.'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('HargaTerbuka')), '27500');
    await tester.enterText(find.byKey(const ValueKey('KeteranganHargaTerbuka')), 'Kabel roll 5 m');
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah'));
    await Tunggu(tester);
    expect(find.byType(DialogHargaTerbuka), findsNothing);
    expect(find.text('Rp 27.500'), findsWidgets);
    expect(find.textContaining('Kabel roll 5 m'), findsOneWidget);

    await tester.tap(find.text('Barang lain-lain').first);
    await Tunggu(tester);
    await tester.enterText(find.byKey(const ValueKey('HargaTerbuka')), '8000');
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah'));
    await Tunggu(tester);
    expect(find.textContaining('Keranjang | 2 item'), findsOneWidget);
    expect(find.text('Rp 8.000'), findsWidgets);
    await Lepas(tester, u);
  });

  testWidgets('jasa servis: harga daftar jadi isian awal; Batal tidak menambah', (tester) async {
    final u = await MasukJual(tester);
    await Cari(tester, 'servis');
    await tester.tap(find.text('Jasa servis ringan'));
    await Tunggu(tester);
    expect(
      tester
          .widget<TextField>(
            find.descendant(of: find.byKey(const ValueKey('HargaTerbuka')), matching: find.byType(TextField)),
          )
          .controller!
          .text,
      '50.000',
    );
    await tester.tap(find.widgetWithText(TextButton, 'Batal'));
    await Tunggu(tester);
    expect(find.byType(DialogHargaTerbuka), findsNothing);
    expect(find.textContaining('Keranjang | '), findsNothing);
    await Lepas(tester, u);
  });
}
