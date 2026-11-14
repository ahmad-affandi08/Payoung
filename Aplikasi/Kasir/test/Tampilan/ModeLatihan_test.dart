import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/Pengaturan/KategoriPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-23 (POS-18): Pengaturan › Mode latihan menyalakan banner di Ruang Kerja; penjualan latihan selesai tanpa
/// penjualan tersimpan, outbox, atau cetak; menyalakannya butuh PIN supervisor dan layar Bayar memberi peringatan; "Matikan" di banner kembali ke mode biasa (360/1280 dp).
void main() {
  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('latihan: jual tanpa menyimpan, lalu matikan (${ukuran.width.toInt()} dp)', (tester) async {
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
      final outboxAwal = (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!.length;

      Future<void> Ketuk(Finder f) async {
        await tester.ensureVisible(f);
        await tester.pump();
        await tester.tap(f);
        await Tunggu(tester);
      }

      await tester.tap(NavPengaturan().last);
      await Tunggu(tester);
      await BukaKategoriPengaturan(tester, KategoriPengaturan.keamanan);
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('ModeLatihan')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      // Audit kemudahan pakai #32: Rina tidak ber-izin supervisor → menyalakan butuh PIN supervisor; batal = tetap mati.
      await Ketuk(find.byKey(const ValueKey('ModeLatihan')));
      expect(find.text('Persetujuan supervisor'), findsOneWidget);
      await tester.tapAt(const Offset(4, 4));
      await Tunggu(tester);
      expect(find.byKey(const ValueKey('BannerModeLatihan')), findsNothing);

      await Ketuk(find.byKey(const ValueKey('ModeLatihan')));
      await PilihPenyetuju(tester, 'Budi Santoso');
      await tester.pump();
      await KetikPin(tester, KasusPin(1)['Pin']! as String);
      await Tunggu(tester);
      expect(find.byKey(const ValueKey('BannerModeLatihan')), findsOneWidget);

      await tester.tap(find.text('Jual').last);
      await Tunggu(tester);
      await Ketuk(
        find.byWidgetPredicate(
          (w) => w is UbinProduk && w.nama == 'Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo',
        ),
      );
      await Ketuk(find.widgetWithText(FilledButton, 'Bayar'));
      expect(find.byKey(const ValueKey('PeringatanLatihanBayar')), findsOneWidget);
      await Ketuk(find.widgetWithText(ChoiceChip, 'Tunai'));
      await Ketuk(find.widgetWithText(FilledButton, 'Uang pas'));
      expect(find.text('Latihan selesai'), findsOneWidget);
      expect(find.textContaining('tidak disimpan, tidak dikirim, dan tidak dicetak'), findsOneWidget);
      expect(await tester.runAsync(() => u.db.select(u.db.penjualan).get()), isEmpty);
      expect((await tester.runAsync(() => u.db.select(u.db.outbox).get()))!.length, outboxAwal);
      expect(u.printer.kiriman, isEmpty, reason: 'Struk latihan tidak dicetak.');

      await Ketuk(find.widgetWithText(FilledButton, 'Transaksi baru'));
      await Ketuk(find.widgetWithText(TextButton, 'Matikan'));
      expect(find.byKey(const ValueKey('BannerModeLatihan')), findsNothing);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
