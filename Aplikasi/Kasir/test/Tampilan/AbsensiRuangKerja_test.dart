import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Tampilan/RuangKerja/LayarKunci.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-18: absen masuk/pulang dari dalam Ruang Kerja (Pengaturan › Absensi) dan tawaran absen pulang saat staf mengetuk
/// Kunci / Ganti kasir. Staf tanpa shift (pelayan, salesman) tidak pernah melihat tombol di Laporan Z, jadi ini satu-satunya
/// jalur mereka selain layar pilih kasir.
void main() {
  final foto = Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, 9]);

  /// Rina masuk Ruang Kerja (shift terbuka, server offline kecuali data awal). Tawaran absen masuk setelah PIN dijawab
  /// [absenMasuk]. [berkaryawan] false = toko tanpa data karyawan.
  Future<(LingkunganUji, KameraSwafotoTiruan)> Masuk(
    WidgetTester tester, {
    bool absenMasuk = true,
    bool berkaryawan = true,
    Size ukuran = const Size(1280, 900),
  }) async {
    final u = LingkunganUji.Buat();
    final kamera = KameraSwafotoTiruan(foto: foto);
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(
          DataAwalUji(
            karyawan: berkaryawan
                ? [
                    {'Uuid': '01K5KRY0000000000000000001', 'Nama': 'Rina Wulandari', 'Jabatan': 'Kasir'},
                  ]
                : null,
          ),
        );
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: ukuran, kamera: kamera);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    if (berkaryawan) {
      expect(find.text('Absen masuk sekarang?'), findsOneWidget);
      await tester.tap(find.text(absenMasuk ? 'Absen masuk' : 'Nanti'));
      await Tunggu(tester, const Duration(milliseconds: 600));
    }
    expect(find.byType(RuangKerja), findsOneWidget);
    return (u, kamera);
  }

  Future<List<String>> JenisOutbox(WidgetTester tester, LingkunganUji u) async => [
    for (final o in (await tester.runAsync(() => u.db.select(u.db.outbox).get()))!)
      if (o.Jenis.startsWith('Absensi')) o.Jenis,
  ];

  Future<void> BukaPengaturan(WidgetTester tester) async {
    await tester.tap(find.text('Pengaturan'));
    await Tunggu(tester);
  }

  testWidgets('Pengaturan › Absensi: absen masuk lalu pulang tanpa PIN kedua, tercatat di outbox', (tester) async {
    final (u, kamera) = await Masuk(tester, absenMasuk: false);
    await BukaPengaturan(tester);

    expect(find.byKey(const ValueKey('StatusAbsensiSaya')), findsOneWidget);
    expect(find.text('Anda belum absen masuk.'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('TombolAbsensiSaya')));
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.textContaining('Rina Wulandari absen masuk pukul'), findsOneWidget);
    expect(find.textContaining('Tercatat bekerja sejak'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Absen pulang'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('TombolAbsensiSaya')));
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.textContaining('Rina Wulandari absen pulang pukul'), findsOneWidget);
    expect(find.text('Anda belum absen masuk.'), findsOneWidget);

    expect(await JenisOutbox(tester, u), ['Absensi.Masuk', 'Absensi.Keluar']);
    expect(kamera.dipanggil, 2, reason: 'Hanya swafoto; PIN tidak diminta lagi.');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('Pengaturan tidak menampilkan Absensi bila toko tidak memakai data karyawan', (tester) async {
    final (u, _) = await Masuk(tester, berkaryawan: false);
    await BukaPengaturan(tester);

    expect(find.byKey(const ValueKey('TombolAbsensiSaya')), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets('Kunci saat masih bekerja: ditawari absen pulang, "Absen pulang" mencatat lalu layar terkunci', (
    tester,
  ) async {
    final (u, _) = await Masuk(tester);
    expect(await JenisOutbox(tester, u), ['Absensi.Masuk']);

    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    expect(find.text('Absen pulang sekarang?'), findsOneWidget);
    expect(find.textContaining('Rina Wulandari masih tercatat bekerja sejak'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Absen pulang'));
    await Tunggu(tester, const Duration(milliseconds: 600));
    expect(find.text('Absen pulang tercatat'), findsOneWidget);
    expect(find.textContaining('Rina Wulandari absen pulang pukul'), findsOneWidget);
    await tester.tap(find.text('Oke'));
    await Tunggu(tester);

    expect(await JenisOutbox(tester, u), ['Absensi.Masuk', 'Absensi.Keluar']);
    expect(find.byType(LayarKunci), findsOneWidget, reason: 'Layar tetap terkunci setelah absen.');
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('"Nanti" tetap mengunci dan tidak bertanya lagi hari itu; tanpa absensi terbuka tidak ada tawaran', (
    tester,
  ) async {
    final (u, _) = await Masuk(tester);

    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    await tester.tap(find.text('Nanti'));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    expect(await JenisOutbox(tester, u), ['Absensi.Masuk'], reason: 'Tidak ada absen pulang yang tercatat.');

    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    expect(find.text('Absen pulang sekarang?'), findsNothing, reason: 'Sudah dijawab "Nanti" hari ini.');
    expect(find.byType(LayarKunci), findsOneWidget);
    await Lepas(tester, u);

    final (v, _) = await Masuk(tester, absenMasuk: false);
    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    expect(find.text('Absen pulang sekarang?'), findsNothing, reason: 'Belum absen masuk.');
    expect(find.byType(LayarKunci), findsOneWidget);
    await Lepas(tester, v);
  });

  testWidgets('Ganti kasir juga menawarkan absen pulang; swafoto dibatalkan → pesan, kunci tetap berjalan', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    final kamera = KameraSwafotoTiruan(foto: foto);
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji(
          DataAwalUji(
            karyawan: [
              {'Uuid': '01K5KRY0000000000000000001', 'Nama': 'Rina Wulandari', 'Jabatan': 'Kasir'},
            ],
          ),
        );
      }
      if (p.url.path.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, kamera: kamera);
    await Tunggu(tester, const Duration(milliseconds: 600));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await tester.tap(find.text('Absen masuk'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    kamera.foto = null; // kasir menutup kamera tanpa mengambil foto
    await tester.tap(find.byTooltip('Ganti kasir'));
    await Tunggu(tester);
    expect(find.text('Absen pulang sekarang?'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Absen pulang'));
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(find.text('Absen pulang belum tercatat'), findsOneWidget);
    expect(find.text('Swafoto wajib untuk absen. Coba lagi dan ambil foto wajah.'), findsOneWidget);
    await tester.tap(find.text('Mengerti'));
    await Tunggu(tester);
    expect(await JenisOutbox(tester, u), ['Absensi.Masuk']);
    expect(find.byType(LayarKunci), findsOneWidget);
    await Lepas(tester, u);
  });
}
