import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Meja/LayarMeja.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// POS-03: denah meja di layar Meja kasir. Meja yang sudah ditata di back-office tampil di kanvas pada letaknya
/// (bentuk dari data), yang belum ditata di baris sendiri; tombol Daftar mengembalikan tampilan per area; tanpa satu
/// pun meja tertata, layar tetap daftar seperti sebelumnya. Diuji di 360/800/1280dp.
void main() {
  const ukuranHp = Size(360, 740);
  const ukuranTablet = Size(800, 1280);
  const ukuranDesktop = Size(1280, 900);

  Map<String, Object?> DataDenah({required bool tertata}) {
    final data = DataMejaUji();
    final meja = [
      for (final m in data['Meja']! as List<Object?>) Map<String, Object?>.from(m! as Map<Object?, Object?>),
    ];
    data['Meja'] = meja;
    if (tertata) {
      // D-01 dan T-01 ditata; D-02 sengaja belum.
      meja[0].addAll({'PosisiX': 200, 'PosisiY': 300});
      meja[2]
        ..addAll({'PosisiX': 760, 'PosisiY': 640})
        ..['Bentuk'] = 'Bundar';
    }
    return data;
  }

  Future<LingkunganUji> MasukKasir(WidgetTester tester, Size ukuran, {required bool tertata}) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (http.Request p) async {
      final jalur = p.url.path;
      if (jalur.endsWith('/data-awal')) {
        return JsonUji(DataAwalUji());
      }
      if (jalur.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (jalur.endsWith('/meja')) {
        return JsonUji(DataDenah(tertata: tertata));
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

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('denah di $nama dp: meja tertata di kanvas, yang belum di baris sendiri. Ketuk meja membuka pesanan', (
      tester,
    ) async {
      final u = await MasukKasir(tester, ukuran, tertata: true);
      await Ketuk(tester, find.text('Meja'));
      expect(find.byType(LayarMeja), findsOneWidget);

      final kanvas = find.byKey(const ValueKey('KanvasDenahMeja'));
      expect(kanvas, findsOneWidget);
      expect(find.descendant(of: kanvas, matching: find.byKey(const ValueKey('MejaDenah-D-01'))), findsOneWidget);
      expect(find.descendant(of: kanvas, matching: find.byKey(const ValueKey('MejaDenah-T-01'))), findsOneWidget);
      expect(find.byKey(const ValueKey('MejaDenah-D-02')), findsNothing, reason: 'D-02 belum ditata.');
      await tester.dragUntilVisible(
        find.text('Belum ditata di denah'),
        find.byType(ListView).first,
        const Offset(0, -200),
      );
      expect(find.text('Belum ditata di denah'), findsOneWidget);
      expect(find.text('D-02'), findsOneWidget, reason: 'Ubin D-02 di baris belum ditata.');
      expect(find.text('Dalam'), findsNothing, reason: 'Judul area hanya di tampilan Daftar.');

      // D-01 berada di kiri atas T-01 (letak relatif 200,300 vs 760,640).
      final d1 = tester.getCenter(find.byKey(const ValueKey('MejaDenah-D-01')));
      final t1 = tester.getCenter(find.byKey(const ValueKey('MejaDenah-T-01')));
      expect(d1.dx, lessThan(t1.dx));
      expect(d1.dy, lessThan(t1.dy));
      expect(tester.takeException(), isNull);

      await tester.dragUntilVisible(
        find.byKey(const ValueKey('MejaDenah-D-01')),
        find.byType(ListView).first,
        const Offset(0, 200),
      );
      await Ketuk(tester, find.byKey(const ValueKey('MejaDenah-D-01')));
      expect(find.text('Buka D-01'), findsOneWidget);
      await Lepas(tester, u);
    });
  }

  testWidgets('tombol Daftar mengembalikan tampilan per area; Denah kembali ke kanvas (800 dp)', (tester) async {
    final u = await MasukKasir(tester, ukuranTablet, tertata: true);
    await Ketuk(tester, find.text('Meja'));

    await Ketuk(tester, find.text('Daftar'));
    expect(find.byKey(const ValueKey('KanvasDenahMeja')), findsNothing);
    for (final teks in ['Dalam', 'Teras', 'D-01', 'D-02', 'T-01']) {
      expect(find.text(teks), findsOneWidget, reason: teks);
    }

    await Ketuk(tester, find.text('Denah'));
    expect(find.byKey(const ValueKey('KanvasDenahMeja')), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('tanpa meja tertata: tetap daftar per area, tanpa pilihan Denah/Daftar (800 dp)', (tester) async {
    final u = await MasukKasir(tester, ukuranTablet, tertata: false);
    await Ketuk(tester, find.text('Meja'));

    expect(find.byKey(const ValueKey('KanvasDenahMeja')), findsNothing);
    expect(find.text('Denah'), findsNothing);
    for (final teks in ['Dalam', 'Teras', 'D-01', 'D-02', 'T-01']) {
      expect(find.text(teks), findsOneWidget, reason: teks);
    }
    await Lepas(tester, u);
  });
}
