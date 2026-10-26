import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-10 (§9.3): cek harga tanpa menambah ke keranjang. Pindaian saat panel terbuka hanya menampilkan harga; harga
/// bertingkat (mulai jumlah tertentu) dan alasan tidak bisa dijual tampil; keranjang hanya berubah lewat tombol tambah.
void main() {
  Future<LingkunganUji> Masuk(WidgetTester tester, Size ukuran) async {
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
    await tester.pump();
    await tester.tap(f);
    await Tunggu(tester);
  }

  for (final ukuran in const [Size(1280, 900), Size(800, 1280), Size(360, 740)]) {
    testWidgets('ketik SKU: harga per satuan & bertingkat, keranjang tetap kosong (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      final u = await Masuk(tester, ukuran);
      await Ketuk(tester, find.byKey(const ValueKey('TombolCekHarga')));
      expect(tester.widget<PanelTugas>(find.byType(PanelTugas)).judul, 'Cek harga');

      await tester.enterText(find.byKey(const ValueKey('CekHargaCari')), 'RTG-01');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await Tunggu(tester);
      final rincian = find.byKey(const ValueKey('CekHargaRincian'));
      expect(find.descendant(of: rincian, matching: find.text('Roti Tawar Gandum')), findsOneWidget);
      expect(find.descendant(of: rincian, matching: find.text('Per Pcs')), findsOneWidget);
      expect(find.descendant(of: rincian, matching: find.text('Rp 12.000')), findsOneWidget);
      expect(find.descendant(of: rincian, matching: find.text('Mulai 10 Pcs')), findsOneWidget);
      expect(find.descendant(of: rincian, matching: find.text('Rp 11.000')), findsOneWidget);
      expect(
        find.descendant(of: rincian, matching: find.text('Rp 130.000')),
        findsOneWidget,
        reason: 'Per Lusin.',
      );
      expect(tester.takeException(), isNull);

      // Tutup panel: keranjang tidak berubah.
      await Ketuk(tester, find.byTooltip('Tutup'));
      expect(find.textContaining('Keranjang | 0'), findsNothing);
      expect(find.byType(BarisKeranjang), findsNothing);
      await Lepas(tester, u);
    });
  }

  testWidgets('F4 lalu pindai barcode: hanya tampil harga. Induk varian tanpa varian tampil alasannya', (tester) async {
    final u = await Masuk(tester, const Size(1280, 900));
    await tester.sendKeyEvent(LogicalKeyboardKey.f4);
    await Tunggu(tester);
    expect(find.byType(PanelTugas), findsOneWidget);
    // Pemindai mengetik ke kolom cek harga yang terfokus lalu Enter.
    await tester.enterText(find.byKey(const ValueKey('CekHargaCari')), UuidUji.barcodeAmericano);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    expect(find.text('Americano Panas'), findsWidgets);
    expect(find.byType(BarisKeranjang), findsNothing);

    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cek produk lain'));
    await tester.enterText(find.byKey(const ValueKey('CekHargaCari')), 'KAOS');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    expect(find.textContaining('Pilih salah satu variannya'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Tambah ke keranjang'), findsNothing);

    // Tambah dari panel = satu-satunya jalan ke keranjang.
    await Ketuk(tester, find.widgetWithText(OutlinedButton, 'Cek produk lain'));
    await tester.enterText(find.byKey(const ValueKey('CekHargaCari')), 'RTG-01');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tambah ke keranjang'));
    expect(find.byType(PanelTugas), findsNothing);
    expect(find.byType(BarisKeranjang), findsOneWidget);
    await Lepas(tester, u);
  });
}
