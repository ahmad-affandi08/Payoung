import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../test/Pendukung/LingkunganUji.dart';
import '../test/Pendukung/MuatFont.dart';
import '../test/Pendukung/PasangAplikasi.dart';
import 'DataDemoSektor.dart';

/// Penghasil foto kasir per sektor usaha untuk hero situs pemasaran `payoung.id` (D-212; bukan test regresi, sengaja di
/// luar `test/` agar tidak ikut CI). Layar Jual asli dirender dengan katalog contoh tiap sektor lengkap dengan gambar
/// produk (`GambarProduk/`, dibuat `Spesifikasi/Merek/BuatGambarProdukDemo.py`), lalu dipotret dari layer:
///
/// ```
/// cd Aplikasi/Kasir && flutter test AlatSitus/FotoSitusSektor_test.dart
/// ```
///
/// Hasil mentah di `AlatSitus/Hasil/Sektor/`; `Spesifikasi/Merek/BuatFotoSitus.py` mengubahnya ke WebP untuk situs.
void main() {
  Future<void> SimpanFoto(WidgetTester tester, String nama, double skala) async {
    await tester.pump();
    var objek = tester.renderObject(find.byType(MaterialApp));
    while (!objek.isRepaintBoundary) {
      objek = objek.parent!;
    }
    final layer = objek.debugLayer! as OffsetLayer;
    final batas = objek.paintBounds;
    await tester.runAsync(() async {
      final gambar = await layer.toImage(batas, pixelRatio: skala);
      final data = await gambar.toByteData(format: ui.ImageByteFormat.png);
      final berkas = File('AlatSitus/Hasil/Sektor/$nama.png');
      await berkas.parent.create(recursive: true);
      await berkas.writeAsBytes(data!.buffer.asUint8List());
    });
  }

  for (final sektor in sektorDemo) {
    testWidgets('foto kasir sektor ${sektor.kode}', (tester) async {
      await MuatFontMerek();
      final gambar = <String, Uint8List>{};
      final u = LingkunganUji.Buat();
      await tester.runAsync(() async {
        for (final nama in sektor.gambarDipakai) {
          gambar[nama] = await File('AlatSitus/GambarProduk/$nama.png').readAsBytes();
        }
        await u.SiapkanAktif();
        await u.repositori.SimpanPengaturan(KunciPengaturan.namaOutlet, sektor.namaOutlet);
        await u.repositori.SimpanPengaturan(KunciPengaturan.kodePerangkat, 'POS-001');
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
      });
      u.server.penangan = (p) async {
        final jalur = p.url.path;
        if (jalur.endsWith('/data-awal')) {
          final data = DataAwalUji();
          (data['Outlet']! as Map<String, Object?>)['Nama'] = sektor.namaOutlet;
          return JsonUji(data);
        }
        if (jalur.endsWith('/sinkron/kirim')) {
          final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
              .cast<Map<String, Object?>>();
          return JsonUji({
            'Hasil': [
              for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima'},
            ],
            'WaktuServer': u.jam.toUtc().toIso8601String(),
            'PerangkatDicabut': false,
            'PerluTinjauan': <String>[],
          });
        }
        if (jalur.endsWith('/katalog')) {
          return JsonUji(KatalogSektor(sektor));
        }
        if (jalur.endsWith('/produk-habis')) {
          return JsonUji({'Produk': <String>[]});
        }
        if (jalur.contains('/gambar-demo/')) {
          final nama = jalur.split('/').last.replaceAll('.png', '');
          return http.Response.bytes(gambar[nama]!, 200, headers: {'content-type': 'image/png'});
        }
        return http.Response(jsonEncode({}), 200, headers: {'content-type': 'application/json'});
      };
      await PasangAplikasi(tester, u, ukuran: const Size(1280, 800));
      await Tunggu(tester, const Duration(milliseconds: 600));
      await PilihKasir(tester, 'Rina Wulandari');
      await KetikPin(tester, KasusPin(0)['Pin']! as String);
      await Tunggu(tester, const Duration(seconds: 2));

      Finder Ubin(String nama) => find.byWidgetPredicate((w) => w is UbinProduk && w.nama == nama);
      for (final nama in sektor.keranjang) {
        await tester.ensureVisible(Ubin(nama).first);
        await tester.pump();
        await tester.tap(Ubin(nama).first);
        await Tunggu(tester);
      }
      // Gambar produk didekode di luar zona waktu palsu; beri waktu nyata sampai semuanya tampil.
      for (var i = 0; i < 10; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 150)));
        await tester.pump(const Duration(milliseconds: 50));
      }
      await tester.drag(
        find.descendant(of: find.byType(GridView), matching: find.byType(Scrollable)).first,
        const Offset(0, 2000),
      );
      await Tunggu(tester);
      await SimpanFoto(tester, sektor.kode, 2);
      await Lepas(tester, u);
    }, timeout: const Timeout(Duration(minutes: 10)));
  }
}
