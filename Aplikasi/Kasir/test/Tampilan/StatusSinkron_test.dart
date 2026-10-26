import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// K-17 (§18.3 butir 7 & 10): layar Status sinkron menampilkan sinkron terakhir, peringatan data tertunda > 2 jam,
/// peringatan jam perangkat berbeda > 10 menit dari server, dan penjualan yang ditandai tinjauan back-office.
void main() {
  const uuidJual = '01K5JUAL00000000000T1NJAU1';

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets(
      'tertunda lama → peringatan. Setelah terkirim: sinkron terakhir, jam salah, ditinjau (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = LingkunganUji.Buat();
        var online = false;
        await tester.runAsync(() async {
          await u.SiapkanAktif();
          await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
          await u.repositori.TambahOutbox(
            const ItemOutbox(jenis: 'Penjualan.Buat', uuid: uuidJual, data: {'Nomor': 'INV/LAMA/1'}),
            u.jam.subtract(const Duration(hours: 3)),
          );
        });
        u.server.penangan = (p) async {
          if (p.url.path.endsWith('/data-awal')) {
            return JsonUji(DataAwalUji());
          }
          if (p.url.path.endsWith('/katalog')) {
            return JsonUji(KatalogUji());
          }
          if (online && p.url.path.endsWith('/sinkron/kirim')) {
            final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
                .cast<Map<String, Object?>>();
            return JsonUji({
              'Hasil': [
                for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima'},
              ],
              // Jam server 20 menit lebih lambat dari jam perangkat.
              'WaktuServer': u.jam.subtract(const Duration(minutes: 20)).toUtc().toIso8601String(),
              'PerangkatDicabut': false,
              'PerluTinjauan': [uuidJual],
            });
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

        // Menu Sinkron → Status sinkron (bilah status ringkas tidak lagi bisa diketuk, D-66).
        await tester.tap(find.text('Sinkron'));
        await Tunggu(tester);
        expect(find.text('Status sinkron'), findsOneWidget);
        expect(tester.widget<Text>(find.byKey(const ValueKey('SinkronTerakhir'))).data, 'Belum pernah');
        expect(find.textContaining('lebih dari 2 jam'), findsOneWidget);
        final daftar = find.byType(Scrollable).last;
        await tester.scrollUntilVisible(
          find.text('Tidak ada transaksi yang ditandai untuk diperiksa.'),
          200,
          scrollable: daftar,
        );
        expect(find.text('Tidak ada transaksi yang ditandai untuk diperiksa.'), findsOneWidget);

        online = true;
        // Tombol kirim ada di kepala halaman (sebaris judul): gulir kembali ke atas.
        await tester.scrollUntilVisible(find.widgetWithText(FilledButton, 'Kirim sekarang'), -200, scrollable: daftar);
        await tester.tap(find.widgetWithText(FilledButton, 'Kirim sekarang'));
        await Tunggu(tester, const Duration(milliseconds: 900));
        await tester.scrollUntilVisible(find.byKey(const ValueKey('SinkronTerakhir')), -200, scrollable: daftar);
        expect(find.text('Sinkron terakhir'), findsOneWidget);
        expect(tester.widget<Text>(find.byKey(const ValueKey('SinkronTerakhir'))).data, isNot('Belum pernah'));
        expect(find.textContaining('lebih dari 2 jam'), findsNothing);
        expect(find.textContaining('Jam perangkat lebih cepat 20 menit'), findsOneWidget);
        await tester.scrollUntilVisible(find.text(uuidJual), 200, scrollable: daftar);
        expect(
          find.text(uuidJual),
          findsOneWidget,
          reason: 'Penjualan tidak ada di perangkat ini, jadi Uuid-nya tampil.',
        );
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }

  testWidgets(
    'data toko yang lebih dari sehari tidak diperbarui → peringatan dengan waktunya. Segar → tanpa peringatan',
    (tester) async {
      final u = LingkunganUji.Buat();
      await tester.runAsync(() async {
        await u.SiapkanAktif();
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
        await u.repositori.SimpanPengaturan(
          KunciPengaturan.dataAwalPada,
          u.jam.subtract(const Duration(days: 3)).toUtc().toIso8601String(),
        );
      });
      u.server.penangan = (p) async => throw http.ClientException('offline');
      await PasangAplikasi(tester, u, ukuran: const Size(1280, 900));
      await Tunggu(tester, const Duration(milliseconds: 600));
      await PilihKasir(tester, 'Rina Wulandari');
      await tester.pump();
      await KetikPin(tester, KasusPin(0)['Pin']! as String);
      await Tunggu(tester);

      await tester.tap(find.text('Sinkron'));
      await Tunggu(tester);
      expect(find.text('Status sinkron'), findsOneWidget);
      expect(find.textContaining('terakhir diperbarui'), findsOneWidget);
      expect(find.textContaining('lebih dari 1 hari'), findsOneWidget);

      await tester.runAsync(
        () => u.repositori.SimpanPengaturan(KunciPengaturan.dataAwalPada, u.jam.toUtc().toIso8601String()),
      );
      await Tunggu(tester);
      expect(find.textContaining('lebih dari 1 hari'), findsNothing);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    },
  );
}
