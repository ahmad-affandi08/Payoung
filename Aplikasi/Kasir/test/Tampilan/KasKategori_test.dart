import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Audit kemudahan pakai #8: kategori kas berupa chip; bila ada lebih dari satu dan belum dipilih, formulir menolak
/// sebelum dialog PIN supervisor dibuka (sebelumnya supervisor memasukkan PIN dulu lalu ditolak "Pilih kategori").
void main() {
  testWidgets('kas keluar besar tanpa kategori: galat tampil, PIN supervisor tidak diminta. Pilih chip lalu PIN', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.db
          .into(u.db.kategoriKas)
          .insert(
            const KategoriKasCompanion(
              Uuid: Value('01K5KATEGORI00000000000003'),
              Nama: Value('Bayar parkir & kebersihan pasar'),
              Jenis: Value('Keluar'),
            ),
          );
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (_) async => throw http.ClientException('offline');
    await PasangAplikasi(tester, u);
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);

    await tester.tap(find.text('Kas').last);
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Kas keluar'));
    await Tunggu(tester);
    expect(find.byType(ChoiceChip), findsNWidgets(2));
    expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Beli es batu & galon')).selected, isFalse);

    await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '350000');
    await tester.tap(find.text('Simpan kas keluar'));
    await Tunggu(tester);
    expect(find.text('Pilih kategori kas terlebih dahulu.'), findsOneWidget);
    expect(find.text('Persetujuan supervisor'), findsNothing);

    await tester.tap(find.widgetWithText(ChoiceChip, 'Bayar parkir & kebersihan pasar'));
    await Tunggu(tester);
    expect(find.text('Pilih kategori kas terlebih dahulu.'), findsNothing);
    await tester.tap(find.text('Simpan kas keluar'));
    await Tunggu(tester);
    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester, const Duration(seconds: 1));
    expect(find.text('Bayar parkir & kebersihan pasar'), findsOneWidget, reason: 'Mutasi tersimpan di daftar kas.');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });
}
