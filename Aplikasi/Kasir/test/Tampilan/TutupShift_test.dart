import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Shift/LayananShift.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Rincian F-11 di Ruang Kerja Kasir: laporan X, tutup shift buta, selisih di atas toleransi dengan PIN supervisor,
/// laporan Z, lalu kembali ke buka shift. Diuji di 360/800/1280dp (§17.2.7).
void main() {
  const ukuranHp = Size(360, 740);
  const ukuranTablet = Size(800, 1280);
  const ukuranDesktop = Size(1280, 900);

  /// Shift Rina terbuka (Rp 500.000) + kas keluar Rp 45.000 → kas seharusnya Rp 455.000; server offline.
  Future<LingkunganUji> MasukRuangKerja(
    WidgetTester tester, {
    Size ukuran = ukuranDesktop,
    Map<String, Object?>? dataAwal,
    String kasir = 'Rina Wulandari',
    int indeksPin = 0,
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      final rina = await u.Staf('Rina Wulandari');
      final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      await u.shift.CatatMutasi(
        shift: shift,
        jenis: JenisMutasi.keluar,
        jumlah: Uang.DariBulat(45000),
        pencatat: rina,
        uuidKategori: '01K5KATEGORI00000000000001',
      );
    });
    u.server.penangan = (_) async => throw http.ClientException('offline');
    await PasangAplikasi(tester, u, ukuran: ukuran);
    await PilihKasir(tester, kasir);
    await tester.pump();
    await KetikPin(tester, KasusPin(indeksPin)['Pin']! as String);
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await Tunggu(tester);
  }

  Future<void> BukaTutupShift(WidgetTester tester) async {
    await tester.tap(find.text('Shift').last);
    await Tunggu(tester);
    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tutup shift'));
  }

  Future<List<Map<String, Object?>>> OutboxTutup(WidgetTester tester, LingkunganUji u) async =>
      (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!
          .where((o) => o.Jenis == 'Shift.Tutup')
          .map((o) => jsonDecode(o.Data) as Map<String, Object?>)
          .toList();

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('lebar $nama dp: tutup buta menyembunyikan kas seharusnya sampai hitungan disimpan. Selisih pas → '
        'laporan Z → buka shift', (tester) async {
      final u = await MasukRuangKerja(tester, ukuran: ukuran);
      await BukaTutupShift(tester);

      expect(find.textContaining('Kas seharusnya ditampilkan setelah hitungan disimpan'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Kas aktual di laci'), '455000');
      await Tunggu(tester);
      expect(find.text('Kas seharusnya'), findsNothing, reason: 'Tutup buta: kas seharusnya tersembunyi.');
      expect(find.text('Rp 455.000'), findsNothing);

      await Ketuk(tester, find.text('Simpan hitungan'));
      expect(find.text('Kas seharusnya'), findsOneWidget);
      expect(find.text('Rp 0 (pas)'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'Kas aktual di laci'), findsNothing, reason: 'Hitungan terkunci.');
      expect(tester.takeException(), isNull);

      await Ketuk(tester, find.text('Tutup shift sekarang'));
      await Tunggu(tester, const Duration(seconds: 1));

      expect(find.text('Laporan tutup shift (Z)'), findsOneWidget);
      expect(find.text('Kas seharusnya'), findsOneWidget);
      expect(find.text('Rp 455.000'), findsWidgets);
      expect(tester.takeException(), isNull);
      final outbox = await OutboxTutup(tester, u);
      expect(outbox.single['KasAktual'], '455000.00');
      expect(outbox.single['Ringkasan'], {'KasSeharusnya': '455000.00', 'Selisih': '0.00'});

      await Ketuk(tester, find.text('Selesai'));
      await Tunggu(tester, const Duration(seconds: 1));
      expect(find.text('Buka shift | Rina Wulandari'), findsOneWidget);
      await Lepas(tester, u);
    });
  }

  testWidgets('selisih di atas toleransi: wajib alasan lalu PIN supervisor ber-izin shift.selisih.setujui', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester, ukuran: ukuranTablet);
    await BukaTutupShift(tester);
    await tester.enterText(find.widgetWithText(TextField, 'Kas aktual di laci'), '430000');
    await Ketuk(tester, find.text('Simpan hitungan'));

    expect(find.text('−Rp 25.000 (kurang)'), findsOneWidget);
    expect(find.textContaining('melebihi toleransi Rp 10.000'), findsOneWidget);

    await Ketuk(tester, find.text('Tutup shift sekarang'));
    expect(find.text('Tulis alasan selisih minimal 5 huruf.'), findsOneWidget);

    // Audit kemudahan pakai #27: alasan siap pakai cukup diketuk.
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Salah hitung kembalian'));
    expect(find.widgetWithText(TextField, 'Salah hitung kembalian'), findsOneWidget);
    await Ketuk(tester, find.text('Tutup shift sekarang'));
    expect(find.text('Persetujuan supervisor'), findsOneWidget);
    await PilihPenyetuju(tester, 'Budi Santoso');
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    await Tunggu(tester, const Duration(seconds: 1));

    expect(find.text('Laporan tutup shift (Z)'), findsOneWidget);
    expect(find.text('−Rp 25.000 (kurang)'), findsOneWidget);
    final data = (await OutboxTutup(tester, u)).single;
    expect(data['Alasan'], 'Salah hitung kembalian');
    expect(data['UuidPenyetuju'], '01K5STAF000000000000000002');
    expect(data['Ringkasan'], {'KasSeharusnya': '455000.00', 'Selisih': '-25000.00'});
    await Lepas(tester, u);
  });

  testWidgets('tidak buta & di bawah toleransi: selisih tampil sambil mengetik, tutup tanpa PIN. Pecahan mengisi kas', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester, dataAwal: DataAwalUji(tutupShiftButa: false));
    await BukaTutupShift(tester);
    expect(find.text('Simpan hitungan'), findsNothing);

    await tester.tap(find.text('Hitung per pecahan'));
    await Tunggu(tester);
    for (var i = 0; i < 4; i++) {
      await tester.tap(find.byTooltip('Tambah Rp 100.000'));
      await Tunggu(tester, const Duration(milliseconds: 60));
    }
    await tester.tap(find.byTooltip('Tambah Rp 50.000'));
    await Tunggu(tester);
    expect(find.widgetWithText(TextField, '450.000'), findsOneWidget);
    expect(find.text('−Rp 5.000 (kurang)'), findsOneWidget);
    expect(find.text('Alasan selisih'), findsNothing);

    await Ketuk(tester, find.text('Tutup shift sekarang'));
    await Tunggu(tester, const Duration(seconds: 1));
    expect(find.text('Laporan tutup shift (Z)'), findsOneWidget);
    final data = (await OutboxTutup(tester, u)).single;
    expect(data['PecahanKasAkhir'], [
      {'Nominal': '100000', 'Jumlah': 4},
      {'Nominal': '50000', 'Jumlah': 1},
    ]);
    expect(data['UuidPenyetuju'], isNull);
    await Lepas(tester, u);
  });

  testWidgets('pesanan tertahan menolak tutup shift. Laporan X bisa dibuka kapan saja dari layar Shift', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester);
    await tester.runAsync(
      () => u.db.customStatement(
        "INSERT INTO PesananTertahan (Uuid, Label, Data, Total, JumlahItem, UuidPengguna, DibuatPada) VALUES "
        "('TAHAN1', 'Meja 4', '{}', '67100.00', 3, '01K5STAF000000000000000001', '2026-09-24T01:30:00.000Z')",
      ),
    );

    await tester.tap(find.text('Shift').last);
    await Tunggu(tester);
    await Ketuk(tester, find.text('Laporan sementara (X)'));
    expect(find.text('Laporan sementara (X)'), findsNWidgets(2));
    expect(find.text('Kas seharusnya'), findsOneWidget);
    expect(find.text('Rp 455.000'), findsOneWidget);
    await tester.tap(find.byTooltip('Tutup'));
    await Tunggu(tester);

    await Ketuk(tester, find.widgetWithText(FilledButton, 'Tutup shift'));
    expect(find.textContaining('Masih ada 1 pesanan tertahan'), findsOneWidget);
    expect(find.text('Simpan hitungan'), findsNothing);
    expect(await tester.runAsync(() => u.repositori.AmbilPengaturan(KunciPengaturan.laporanZTertunda)), isNull);
    await Lepas(tester, u);
  });

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('lebar $nama dp: K-18 salah tutup → buka ulang dengan alasan + PIN supervisor → kembali berjualan', (
      tester,
    ) async {
      final u = await MasukRuangKerja(tester, ukuran: ukuran);
      await BukaTutupShift(tester);
      await tester.enterText(find.widgetWithText(TextField, 'Kas aktual di laci'), '455000');
      await Tunggu(tester);
      await Ketuk(tester, find.text('Simpan hitungan'));
      await Ketuk(tester, find.text('Tutup shift sekarang'));
      await Tunggu(tester, const Duration(seconds: 1));
      await Ketuk(tester, find.text('Selesai'));
      await Tunggu(tester, const Duration(seconds: 1));
      expect(find.text('Buka shift | Rina Wulandari'), findsOneWidget);

      await Ketuk(tester, find.byKey(const ValueKey('BukaUlangShift')));
      await tester.enterText(find.byKey(const ValueKey('AlasanBukaUlang')), 'ok');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Lanjut'));
      expect(find.text('Tulis alasan minimal 5 huruf.'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('AlasanBukaUlang')), 'Salah tekan, pelanggan masih antre');
      await Ketuk(tester, find.widgetWithText(FilledButton, 'Lanjut'));
      expect(find.text('Persetujuan supervisor'), findsOneWidget);
      await PilihPenyetuju(tester, 'Budi Santoso');
      await tester.pump();
      await KetikPin(tester, KasusPin(1)['Pin']! as String);
      await Tunggu(tester, const Duration(seconds: 1));

      expect(find.byType(RuangKerja), findsOneWidget);
      expect(tester.takeException(), isNull);
      final outbox = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!;
      final data = jsonDecode(outbox.singleWhere((o) => o.Jenis == 'Shift.BukaUlang').Data) as Map<String, Object?>;
      expect(data['Alasan'], 'Salah tekan, pelanggan masih antre');
      expect(data['UuidPenyetuju'], '01K5STAF000000000000000002');
      expect((await tester.runAsync(() => u.repositori.AmbilShiftAktif()))?.Uuid, data['UuidShift']);
      await Lepas(tester, u);
    });
  }
}
