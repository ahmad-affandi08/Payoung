import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';

/// Sesi palsu: hanya untuk menggeser kasir aktif tanpa memuat apa pun dari basis data.
class _SesiPalsu extends PengaturSesi {
  @override
  KeadaanSesi build() => const KeadaanSesi(TahapSesi.Masuk, kasir: _rina);

  void Ganti(KeadaanSesi baru) => state = baru;
}

const _rina = StafLokal(uuid: 'U-RINA', nama: 'Rina Wulandari', pemilik: false, izin: []);
const _budi = StafLokal(uuid: 'U-BUDI', nama: 'Budi Santoso', pemilik: false, izin: []);

/// Mode latihan milik satu sesi kasir (penjualan latihan tidak pernah disimpan): kasir berikutnya tidak boleh
/// mewarisinya tanpa sadar.
void main() {
  ProviderContainer Buat() {
    final wadah = ProviderContainer(overrides: [penyediaSesi.overrideWith(_SesiPalsu.new)]);
    addTearDown(wadah.dispose);

    return wadah;
  }

  test('ganti kasir mematikan mode latihan', () {
    final wadah = Buat();
    wadah.read(penyediaModeLatihan);
    wadah.read(penyediaModeLatihan.notifier).Atur(true);
    expect(wadah.read(penyediaModeLatihan), isTrue);

    (wadah.read(penyediaSesi.notifier) as _SesiPalsu).Ganti(const KeadaanSesi(TahapSesi.Masuk, kasir: _budi));

    expect(wadah.read(penyediaModeLatihan), isFalse);
  });

  test('keluar ke layar pilih kasir mematikan mode latihan', () {
    final wadah = Buat();
    wadah.read(penyediaModeLatihan);
    wadah.read(penyediaModeLatihan.notifier).Atur(true);

    (wadah.read(penyediaSesi.notifier) as _SesiPalsu).Ganti(const KeadaanSesi(TahapSesi.PilihKasir));

    expect(wadah.read(penyediaModeLatihan), isFalse);
  });

  test('kasir yang sama tetap dalam mode latihan', () {
    final wadah = Buat();
    wadah.read(penyediaModeLatihan);
    wadah.read(penyediaModeLatihan.notifier).Atur(true);

    (wadah.read(penyediaSesi.notifier) as _SesiPalsu).Ganti(
      const KeadaanSesi(TahapSesi.Masuk, kasir: _rina, kunci: KeadaanKunci.Terkunci),
    );

    expect(wadah.read(penyediaModeLatihan), isTrue);
  });
}
