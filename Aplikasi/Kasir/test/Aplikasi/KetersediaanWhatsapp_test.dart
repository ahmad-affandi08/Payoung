import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';

import '../Pendukung/LingkunganUji.dart';

/// D-299: kunci `Whatsapp.Tersedia` tersimpan di perangkat dan dipakai layar. Tanpa data (belum pernah dimuat) = false.
/// Pemeriksaan konfigurasi aplikasi (tiap 15 menit) menyegarkannya tanpa menunggu "Perbarui data kasir", dan server
/// lama yang tidak mengirim kunci tidak mengubah keadaan lokal.
void main() {
  late LingkunganUji u;
  Map<String, Object?> konfigurasiServer = {};

  ProviderContainer BuatWadah() => ProviderContainer(
    overrides: [
      penyediaBasisData.overrideWithValue(u.db),
      penyediaKlienPos.overrideWithValue(u.klien),
      penyediaRepositori.overrideWithValue(u.repositori),
      penyediaRepositoriPenjualan.overrideWithValue(u.repositoriPenjualan),
    ],
  );

  Future<void> Periksa(ProviderContainer wadah) =>
      wadah.read(penyediaKonfigurasiAplikasi.notifier).Periksa(paksa: true);

  setUp(() {
    u = LingkunganUji.Buat();
    konfigurasiServer = {};
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/konfigurasi-aplikasi')) {
        return JsonUji(konfigurasiServer);
      }
      throw http.ClientException('offline');
    };
  });
  tearDown(() => u.Tutup());

  test('data awal menyimpan flag. Belum pernah dimuat = false', () async {
    await u.SiapkanAktif();
    var konteks = await KonteksPenjualan.Muat(u.repositori, u.repositoriKatalog);
    expect(konteks.whatsappTersedia, isFalse);

    await u.SiapkanAktif(dataAwal: DataAwalUji(whatsapp: true));
    konteks = await KonteksPenjualan.Muat(u.repositori, u.repositoriKatalog);
    expect(konteks.whatsappTersedia, isTrue);
  });

  test('pemeriksaan konfigurasi menyegarkan flag naik dan turun. Server lama tanpa kunci tidak mengubahnya', () async {
    await u.SiapkanAktif();
    final wadah = BuatWadah();
    addTearDown(wadah.dispose);

    konfigurasiServer = {
      'Whatsapp': {'Tersedia': true},
    };
    await Periksa(wadah);
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.whatsappTersedia), '1');

    konfigurasiServer = {'Aplikasi': <String, Object?>{}};
    await Periksa(wadah);
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.whatsappTersedia), '1', reason: 'Server lama.');

    konfigurasiServer = {
      'Whatsapp': {'Tersedia': false},
    };
    await Periksa(wadah);
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.whatsappTersedia), '0');
  });
}
