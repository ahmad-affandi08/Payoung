import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:kasir/Tampilan/RuangKerja/BannerPerluTindakan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// D-340: data yang ditolak server tidak boleh baru ketahuan keesokan harinya. Ruang Kerja menampilkan banner
/// permanen sampai semuanya ditangani di layar Sinkron.
void main() {
  test('kalimat banner menyebut penjualan bila ada', () {
    expect(
      BannerPerluTindakan.SusunKalimat(3, 2),
      '3 data ditolak server, termasuk 2 penjualan yang belum masuk laporan.',
    );
    expect(BannerPerluTindakan.SusunKalimat(1, 0), '1 data ditolak server dan belum sampai ke laporan.');
  });

  testWidgets('banner tampil dan tombol Lihat memanggil saatLihat', (tester) async {
    var dibuka = 0;
    await tester.pumpWidget(
      MaterialApp(
        theme: ThemeData(extensions: [TokenWarna.bawaan]),
        home: Scaffold(body: BannerPerluTindakan(jumlah: 2, jumlahPenjualan: 2, saatLihat: () => dibuka++)),
      ),
    );
    expect(find.textContaining('2 data ditolak server'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('LihatPerluTindakan')));
    expect(dibuka, 1);
  });

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    for (final adaTolakan in const [false, true]) {
      testWidgets('Ruang Kerja: banner ${adaTolakan ? 'muncul' : 'tidak ada'} (${ukuran.width.toInt()} dp)', (
        tester,
      ) async {
        final u = LingkunganUji.Buat();
        await tester.runAsync(() async {
          await u.SiapkanAktif();
          await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
          if (adaTolakan) {
            await u.repositori.TambahOutbox(
              const ItemOutbox(jenis: 'Penjualan.Buat', uuid: '01K5JUAL00000000000T1NJAU1', data: {'Nomor': 'INV/1'}),
              u.jam,
            );
            await u.repositori.TambahOutbox(
              const ItemOutbox(jenis: 'Shift.Buka', uuid: '01K5SHIFT0000000000T1NJAU1', data: {}),
              u.jam,
            );
            await u.repositori.TandaiPerluTindakan(
              '01K5JUAL00000000000T1NJAU1',
              'ShiftTidakDitemukan',
              'Shift tidak ada.',
            );
            await u.repositori.TandaiPerluTindakan('01K5SHIFT0000000000T1NJAU1', 'ShiftSudahTerbuka', 'Masih terbuka.');
          }
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

        if (!adaTolakan) {
          expect(find.byKey(const ValueKey('BannerPerluTindakan')), findsNothing);
          await Lepas(tester, u);
          return;
        }

        expect(find.byKey(const ValueKey('BannerPerluTindakan')), findsOneWidget);
        expect(
          find.textContaining('2 data ditolak server, termasuk 1 penjualan yang belum masuk laporan.'),
          findsOneWidget,
        );

        await tester.tap(find.byKey(const ValueKey('LihatPerluTindakan')));
        await Tunggu(tester);
        expect(find.text('Status sinkron'), findsOneWidget);
        expect(find.byKey(const ValueKey('BannerPerluTindakan')), findsNothing);
        await Lepas(tester, u);
      });
    }
  }
}
