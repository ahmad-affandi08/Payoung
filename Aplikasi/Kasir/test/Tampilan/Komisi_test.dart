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

/// F-18 bagian 2 di layar Jual: kasir memilih staf yang melayani baris lewat panel item; penjualan membawa `Baris.Staf`.
void main() {
  final dataAwal = DataAwalUji(
    karyawan: [
      {'Uuid': '01K5KRY0000000000000000001', 'Nama': 'Maya Senior', 'Jabatan': 'Barista'},
      {'Uuid': '01K5KRY0000000000000000002', 'Nama': 'Dewi Junior', 'Jabatan': null},
    ],
  );

  for (final (nama, ukuran) in [('800', const Size(800, 1280)), ('1280', const Size(1280, 900))]) {
    testWidgets('pilih dua staf di panel item → outbox Baris.Staf ($nama dp)', (tester) async {
      final u = LingkunganUji.Buat();
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
        throw http.ClientException('offline');
      };
      await PasangAplikasi(tester, u, ukuran: ukuran);
      await Tunggu(tester, const Duration(milliseconds: 600));
      await PilihKasir(tester, 'Rina Wulandari');
      await tester.pump();
      await KetikPin(tester, KasusPin(0)['Pin']! as String);
      await Tunggu(tester);
      expect(find.byType(RuangKerja), findsOneWidget);

      Future<void> Ketuk(Finder f) async {
        await tester.ensureVisible(f);
        await tester.pump();
        await tester.tap(f);
        await Tunggu(tester);
      }

      await Ketuk(find.byWidgetPredicate((w) => w is UbinProduk && w.nama == 'Americano Panas'));
      await Ketuk(find.descendant(of: find.byType(BarisKeranjang), matching: find.textContaining('Americano')));
      expect(find.text('Dilayani oleh (opsional)'), findsOneWidget);
      await Ketuk(find.widgetWithText(FilterChip, 'Maya Senior | Barista'));
      await Ketuk(find.widgetWithText(FilterChip, 'Dewi Junior'));
      await Ketuk(find.widgetWithText(FilledButton, 'Simpan perubahan'));

      await Ketuk(find.widgetWithText(FilledButton, 'Bayar'));
      await Ketuk(find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Pembayaran berhasil'), findsOneWidget);

      final outbox = await tester.runAsync(() => u.db.select(u.db.outbox).get());
      final data = jsonDecode(outbox!.firstWhere((o) => o.Jenis == 'Penjualan.Buat').Data) as Map<String, Object?>;
      final baris = (data['Baris']! as List<Object?>).single! as Map<String, Object?>;
      expect(baris['Staf'], ['01K5KRY0000000000000000001', '01K5KRY0000000000000000002']);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
