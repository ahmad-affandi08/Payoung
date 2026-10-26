import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Katalog/LayananKetersediaan.dart';

import '../Pendukung/LingkunganUji.dart';

/// F-17 BR-17.2: tanda "Habis" (86) milik server, tetapi salinan terakhirnya harus bertahan di perangkat. Sebelumnya
/// daftar dimulai dari kosong setiap aplikasi dibuka, sehingga setelah restart dalam keadaan offline semua produk yang
/// sudah ditandai habis tampil tersedia lagi dan bisa terjual.
void main() {
  late LingkunganUji u;
  var daftarServer = <String>[];
  var online = true;

  ProviderContainer BuatWadah() => ProviderContainer(
    overrides: [
      penyediaRepositori.overrideWithValue(u.repositori),
      penyediaLayananKetersediaan.overrideWithValue(LayananKetersediaan(klien: u.klien)),
    ],
  );

  setUp(() async {
    u = LingkunganUji.Buat();
    daftarServer = ['01HABIS000000000000000001', '01HABIS000000000000000002'];
    online = true;
    u.server.penangan = (p) async {
      if (online && p.url.path.endsWith('/produk-habis')) {
        return JsonUji({'Produk': daftarServer});
      }
      throw http.ClientException('offline');
    };
    await u.SiapkanAktif();
  });
  tearDown(() => u.Tutup());

  test('muat online menyalin daftar ke perangkat. Setelah restart offline daftar yang tersimpan dipakai', () async {
    final sebelum = BuatWadah();
    await sebelum.read(penyediaProdukHabis.notifier).Muat();
    expect(sebelum.read(penyediaProdukHabis), unorderedEquals(daftarServer));
    expect(
      jsonDecode((await u.repositori.AmbilPengaturan(KunciPengaturan.produkHabis))!) as List<Object?>,
      unorderedEquals(daftarServer),
    );

    online = false;
    final sesudahRestart = BuatWadah();
    expect(sesudahRestart.read(penyediaProdukHabis), isEmpty, reason: 'Aplikasi baru dimulai: belum dimuat.');
    await sesudahRestart.read(penyediaProdukHabis.notifier).Muat();
    expect(sesudahRestart.read(penyediaProdukHabis), unorderedEquals(daftarServer));
  });

  test('server menjawab daftar kosong (semua tersedia lagi): salinan perangkat ikut dikosongkan', () async {
    final wadah = BuatWadah();
    await wadah.read(penyediaProdukHabis.notifier).Muat();
    daftarServer = [];
    await wadah.read(penyediaProdukHabis.notifier).Muat();
    expect(wadah.read(penyediaProdukHabis), isEmpty);

    online = false;
    final sesudahRestart = BuatWadah();
    await sesudahRestart.read(penyediaProdukHabis.notifier).Muat();
    expect(sesudahRestart.read(penyediaProdukHabis), isEmpty);
  });

  test('belum pernah dimuat dan offline: tetap kosong tanpa galat. Salinan rusak diabaikan', () async {
    online = false;
    final wadah = BuatWadah();
    await wadah.read(penyediaProdukHabis.notifier).Muat();
    expect(wadah.read(penyediaProdukHabis), isEmpty);

    await u.repositori.SimpanPengaturan(KunciPengaturan.produkHabis, '{bukan-json');
    final rusak = BuatWadah();
    await rusak.read(penyediaProdukHabis.notifier).Muat();
    expect(rusak.read(penyediaProdukHabis), isEmpty);
  });
}
