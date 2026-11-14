import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Domain/Diagnostik/LogLokal.dart';
import 'package:kasir/Tampilan/LayarPengaturan.dart';
import 'package:kasir/Tampilan/Pengaturan/KategoriPengaturan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-21: Pengaturan › Log & laporan galat menampilkan jumlah galat belum terkirim, isi log (sudah tersaring), dan
/// "Kirim laporan sekarang" ke server (360/1280 dp).
void main() {
  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets('lihat log & kirim laporan (${ukuran.width.toInt()} dp)', (tester) async {
      final u = LingkunganUji.Buat();
      final folder = Directory.systemTemp.createTempSync('log-kasir-');
      addTearDown(() => folder.deleteSync(recursive: true));
      late final LogLokal log;
      var laporan = 0;
      await tester.runAsync(() async {
        log = LogLokal(folder: folder, jam: () => u.jam);
        await u.SiapkanAktif();
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
        await log.Catat(TingkatLog.galat, 'Printer', 'Gagal cetak struk untuk 0812-3456-7890');
      });
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/data-awal')) {
          return JsonUji(DataAwalUji());
        }
        if (p.url.path.endsWith('/katalog')) {
          return JsonUji(KatalogUji());
        }
        if (p.url.path.endsWith('/perangkat/galat')) {
          laporan++;
          return JsonUji({'Diterima': 1});
        }
        throw http.ClientException('offline');
      };
      await PasangAplikasi(tester, u, ukuran: ukuran, logLokal: log);
      await Tunggu(tester, const Duration(milliseconds: 600));
      await PilihKasir(tester, 'Rina Wulandari');
      await tester.pump();
      await KetikPin(tester, KasusPin(0)['Pin']! as String);
      await Tunggu(tester);
      expect(find.byType(RuangKerja), findsOneWidget);

      await tester.tap(NavPengaturan().last);
      await Tunggu(tester);
      await BukaKategoriPengaturan(tester, KategoriPengaturan.bantuan);
      final kirim = find.text('Kirim laporan sekarang');
      final daftarPengaturan = find
          .descendant(of: find.byType(LayarPengaturan), matching: find.byType(Scrollable))
          .first;
      // Gulir elemen yang akan diketuk ke dalam layar (tinggi layar 900 dp memotong "Lihat log" di bawahnya).
      Future<void> Tampakkan(Finder finder) async {
        // HP: daftar dibangun malas, jadi gulir dulu sampai elemennya ada; lalu bawa ke tengah layar (layar lebar dua
        // kolom: elemen sudah ada tetapi bisa di bawah batas layar).
        await tester.scrollUntilVisible(finder, 300, scrollable: daftarPengaturan);
        await tester.runAsync(() => Scrollable.ensureVisible(tester.element(finder), alignment: 0.5));
        await tester.pump();
      }

      await Tampakkan(kirim);
      // Membaca berkas log memakai IO nyata (lebih lama saat mesin sibuk): tunggu teksnya dengan batas yang longgar.
      for (var i = 0; i < 40 && find.text('1 galat belum terkirim.').evaluate().isEmpty; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
        await Tunggu(tester);
      }
      expect(find.text('1 galat belum terkirim.'), findsOneWidget);

      await Tampakkan(find.text('Lihat log'));
      await tester.tap(find.text('Lihat log'));
      await Tunggu(tester);
      for (var i = 0; i < 40 && find.text('Gagal cetak struk untuk [disamarkan]').evaluate().isEmpty; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
        await Tunggu(tester);
      }
      expect(find.text('Gagal cetak struk untuk [disamarkan]'), findsOneWidget);
      await tester.tap(find.text('Tutup'));
      await Tunggu(tester);

      await Tampakkan(kirim);
      await tester.tap(kirim);
      // Menulis penanda terkirim ke berkas butuh waktu nyata (lebih lama saat mesin sibuk): beri batas yang longgar.
      for (var i = 0; i < 40 && find.text('Semua galat sudah terkirim.').evaluate().isEmpty; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
        await Tunggu(tester);
      }
      expect(laporan, 1);
      expect(find.text('1 laporan galat terkirim ke tim dukungan.'), findsOneWidget);
      expect(find.text('Semua galat sudah terkirim.'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }
}
