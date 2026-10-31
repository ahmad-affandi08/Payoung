import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// X8 (PRD v7.86): harga pilihan tambahan (modifier) per kanal ojol. Pilihan membawa harga khusus kanal dari server,
/// kasir memakainya saat kanal keranjang GoFood/GrabFood, dan ganti kanal menghitung ulang harga pilihan di baris yang
/// sudah ada. Kanal tanpa harga khusus memakai harga biasa.
void main() {
  test('PilihanJual.HargaUntuk: harga khusus kanal bila ada, selain itu harga biasa', () {
    final pilihan = PilihanJual(
      uuid: 'P1',
      nama: 'Extra shot',
      harga: Uang.Dari('5000.00'),
      hargaKanal: {'GoFood': Uang.Dari('6500.00')},
    );

    expect(pilihan.HargaUntuk('GoFood').KeString(), '6500.00');
    expect(pilihan.HargaUntuk('GrabFood').KeString(), '5000.00');
    expect(pilihan.HargaUntuk('BawaPulang').KeString(), '5000.00');
    expect(pilihan.HargaUntuk(null).KeString(), '5000.00');
  });

  late LingkunganUji u;
  var dipakai = false;

  Future<void> Siapkan() async {
    u = LingkunganUji.Buat();
    dipakai = true;
    await u.SiapkanAktif(dataAwal: DataAwalUji(ojol: true));
    await u.SiapkanKatalog(KatalogOjolPilihanUji());
  }

  tearDown(() async {
    if (dipakai) {
      await u.Tutup();
      dipakai = false;
    }
  });

  PilihanTerpilih Ambil(KatalogLokal katalog, String uuid, KanalPenjualan kanal) {
    final p = katalog.AmbilPilihan(uuid)!;
    return PilihanTerpilih(uuid: p.uuid, nama: p.nama, harga: p.HargaUntuk(kanal.name));
  }

  test('katalog lokal membaca HargaKanal dari sinkron; pilihan tanpa harga kanal tetap harga biasa', () async {
    await Siapkan();
    final katalog = await u.MuatKatalog();

    final shot = katalog.AmbilPilihan(UuidUji.extraShot)!;
    final oat = katalog.AmbilPilihan(UuidUji.oatMilk)!;
    expect(shot.harga.KeString(), '5000.00');
    expect(shot.HargaUntuk('GoFood').KeString(), '6500.00');
    expect(shot.HargaUntuk('GrabFood').KeString(), '7000.00');
    expect(shot.HargaUntuk('ShopeeFood').KeString(), '5000.00');
    expect(oat.HargaUntuk('GoFood').KeString(), '7000.00');
    expect(katalog.AmbilPilihan('01K5TIDAKADA00000000000000'), isNull);
  });

  test(
    'ganti kanal: harga pilihan di baris ikut dihitung ulang, kembali ke bawa pulang mengembalikan harga biasa',
    () async {
      await Siapkan();
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      final kopi = katalog.CariProduk(UuidUji.kopiSusu)!;
      var keranjang = Keranjang.kosong;
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(
          katalog,
          k,
          kopi,
          pilihan: [
            Ambil(katalog, UuidUji.gulaNormal, KanalPenjualan.BawaPulang),
            Ambil(katalog, UuidUji.extraShot, KanalPenjualan.BawaPulang),
            Ambil(katalog, UuidUji.oatMilk, KanalPenjualan.BawaPulang),
          ],
        ),
        katalog,
        k,
      );
      expect(keranjang.baris.single.AmbilHargaPilihan().KeString(), '12000.00');

      final gofood = u.penjualan.GantiKanal(keranjang, KanalPenjualan.GoFood, katalog, k);
      expect(gofood.baris.single.AmbilHargaPilihan().KeString(), '13500.00');
      expect(gofood.baris.single.pilihan.map((p) => p.harga.KeString()), ['0.00', '6500.00', '7000.00']);

      final grab = u.penjualan.GantiKanal(gofood, KanalPenjualan.GrabFood, katalog, k);
      expect(grab.baris.single.AmbilHargaPilihan().KeString(), '14000.00');

      final balik = u.penjualan.GantiKanal(grab, KanalPenjualan.BawaPulang, katalog, k);
      expect(balik.baris.single.AmbilHargaPilihan().KeString(), '12000.00');
    },
  );

  test('baris dengan satu pilihan berharga kanal: hanya pilihan itu yang berubah, pilihan gratis tetap nol', () async {
    await Siapkan();
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final kopi = katalog.CariProduk(UuidUji.kopiSusu)!;
    final baris = u.penjualan.BuatBaris(
      katalog,
      k,
      kopi,
      pilihan: [
        Ambil(katalog, UuidUji.gulaNormal, KanalPenjualan.BawaPulang),
        Ambil(katalog, UuidUji.extraShot, KanalPenjualan.BawaPulang),
      ],
    );
    final keranjang = u.penjualan.TambahBaris(Keranjang.kosong, baris, katalog, k);

    final gofood = u.penjualan.GantiKanal(keranjang, KanalPenjualan.GoFood, katalog, k);

    expect(gofood.baris.single.pilihan.map((p) => p.harga.KeString()), ['0.00', '6500.00']);
  });

  test('pilihan yang sudah tidak ada di katalog dibiarkan dengan harga saat dipilih', () async {
    await Siapkan();
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final kopi = katalog.CariProduk(UuidUji.kopiSusu)!;
    final lama = PilihanTerpilih(uuid: UuidUji.extraShot, nama: 'Extra shot', harga: Uang.Dari('5000.00'));
    final hantu = PilihanTerpilih(uuid: '01K5HANTU000000000000000001', nama: 'Menu lama', harga: Uang.Dari('3000.00'));
    final gula = Ambil(katalog, UuidUji.gulaNormal, KanalPenjualan.BawaPulang);
    final baris = u.penjualan.BuatBaris(katalog, k, kopi, pilihan: [gula, lama]);
    final keranjang = Keranjang.kosong.Salin(
      baris: [
        baris.Salin(pilihan: [gula, lama, hantu]),
      ],
    );

    final gofood = u.penjualan.GantiKanal(keranjang, KanalPenjualan.GoFood, katalog, k);

    expect(gofood.baris.single.pilihan.map((p) => p.harga.KeString()), ['0.00', '6500.00', '3000.00']);
  });
}
