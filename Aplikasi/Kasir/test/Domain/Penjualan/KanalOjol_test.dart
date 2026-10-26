import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunStrukPenjualan.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// X8 harga per kanal ojol (PRD v2.36, BR-08.7) di perangkat: pilihan kanal hanya muncul bila ada kanal platform/harga berkanal,
/// ganti kanal menghitung ulang harga dari daftar harga kanal, metode platform hanya untuk kanalnya, kanal ikut pesanan
/// tertahan, dan `Penjualan.Buat` membawa `Kanal`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  Future<void> Siapkan({bool ojol = true, Map<String, Object?>? katalog}) async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif(dataAwal: DataAwalUji(ojol: ojol));
    await u.SiapkanKatalog(katalog ?? KatalogOjolUji());
    rina = await u.Staf('Rina Wulandari');
  }

  tearDown(() => u.Tutup());

  test('pilihan kanal: toko biasa tanpa kanal platform/harga berkanal = kosong (baris kanal tidak tampil)', () async {
    await Siapkan(ojol: false, katalog: KatalogUji());
    expect(LayananPenjualan.AmbilPilihanKanal(await u.MuatKonteks(), await u.MuatKatalog()), isEmpty);
  });

  // D-183: "Makan di tempat" milik F&B (jenis pesanan outlet); toko biasa dengan kanal platform tidak ditawari.
  test('pilihan kanal: kanal toko (tanpa Makan di tempat) + platform yang punya metode atau daftar harga', () async {
    await Siapkan();
    expect(LayananPenjualan.AmbilPilihanKanal(await u.MuatKonteks(), await u.MuatKatalog()), [
      KanalPenjualan.BawaPulang,
      KanalPenjualan.Antar,
      KanalPenjualan.GoFood,
      KanalPenjualan.GrabFood,
    ]);

    // Hanya daftar harga GoFood (tanpa metode platform) tetap menawarkan GoFood.
    await u.Tutup();
    await Siapkan(ojol: false);
    expect(
      LayananPenjualan.AmbilPilihanKanal(await u.MuatKonteks(), await u.MuatKatalog()).last,
      KanalPenjualan.GoFood,
    );
  });

  test(
    'ganti kanal GoFood: harga daftar GoFood. Produk tanpa harga GoFood tetap harga dasar. Kembali ke bawa pulang',
    () async {
      await Siapkan();
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      var keranjang = Keranjang.kosong;
      for (final uuid in [UuidUji.americano, UuidUji.croissant, UuidUji.roti]) {
        keranjang = u.penjualan.TambahBaris(
          keranjang,
          u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(uuid)!, kanal: LayananPenjualan.AmbilKanal(keranjang)),
          katalog,
          k,
        );
      }
      expect(keranjang.baris.map((b) => b.hargaSatuan.KeString()), ['15000.00', '25000.00', '12000.00']);

      final gofood = u.penjualan.GantiKanal(keranjang, KanalPenjualan.GoFood, katalog, k);
      expect(gofood.kanal, KanalPenjualan.GoFood);
      expect(LayananPenjualan.AmbilKanal(gofood), KanalPenjualan.GoFood);
      expect(gofood.baris.map((b) => b.hargaSatuan.KeString()), ['19000.00', '31000.00', '12000.00']);

      // Item baru & ubah jumlah di kanal GoFood memakai harga GoFood.
      final tambah = u.penjualan.TambahBaris(
        gofood,
        u.penjualan.BuatBaris(
          katalog,
          k,
          katalog.CariProduk(UuidUji.americano)!,
          kanal: LayananPenjualan.AmbilKanal(gofood),
        ),
        katalog,
        k,
      );
      expect(tambah.baris.first.jumlah, Kuantitas.DariBulat(2));
      expect(tambah.baris.first.hargaSatuan, Uang.Dari('19000.00'));

      // Pesanan tertahan menyimpan kanal; kanal tak dikenal versi ini = bawaan.
      expect(
        Keranjang.DariJson(jsonDecode(jsonEncode(gofood.KeJson())) as Map<String, Object?>).kanal,
        KanalPenjualan.GoFood,
      );
      expect(Keranjang.DariJson({...gofood.KeJson(), 'Kanal': 'TokoBaru'}).kanal, isNull);

      final pulang = u.penjualan.GantiKanal(gofood, KanalPenjualan.BawaPulang, katalog, k);
      expect(pulang.kanal, isNull);
      expect(pulang.baris.map((b) => b.hargaSatuan.KeString()), ['15000.00', '25000.00', '12000.00']);
    },
  );

  test(
    'metode platform hanya untuk kanalnya. Bayar GoFood → outbox Penjualan.Buat Kanal GoFood + nomor pesanan',
    () async {
      await Siapkan();
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

      List<String> Nama(KanalPenjualan kanal) =>
          LayananPenjualan.SaringMetodeKanal(k.metodePembayaran, kanal).map((m) => m.Nama).toList();
      expect(Nama(KanalPenjualan.BawaPulang), isNot(contains('GoFood')));
      expect(Nama(KanalPenjualan.BawaPulang), isNot(contains('GrabFood')));
      expect(Nama(KanalPenjualan.GoFood), allOf(contains('GoFood'), isNot(contains('GrabFood')), contains('Tunai')));

      var keranjang = u.penjualan.GantiKanal(Keranjang.kosong, KanalPenjualan.GoFood, katalog, k);
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(
          katalog,
          k,
          katalog.CariProduk(UuidUji.croissant)!,
          kanal: LayananPenjualan.AmbilKanal(keranjang),
        ),
        katalog,
        k,
      );
      final gofood = k.metodePembayaran.singleWhere(
        (m) => m.Jenis == JenisMetodeBayar.marketplace && m.Kanal == 'GoFood',
      );
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          PembayaranMasukan(
            metode: gofood,
            jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir,
            referensi: 'F-3281937',
          ),
        ],
        kasir: rina,
        k: k,
      );

      final outbox = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == hasil.uuid);
      final data = jsonDecode(outbox.Data) as Map<String, Object?>;
      expect(data['Kanal'], 'GoFood');
      final bayar = (data['Pembayaran']! as List<Object?>).single! as Map<String, Object?>;
      expect(bayar['UuidMetodePembayaran'], gofood.Uuid);
      expect(bayar['Referensi'], 'F-3281937');
      expect((await u.db.select(u.db.penjualan).get()).single.Kanal, 'GoFood');

      // Struk: pesanan platform dicetak tebal dengan nomor pesanan (untuk dicocokkan dengan pengemudi).
      final struk = TataLetakStruk.KeTeks(
        PenyusunStrukPenjualan.Susun(
          await IdentitasStruk.Muat(u.repositori),
          DataStrukPenjualan(
            penjualan: (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!,
            detail: await u.repositoriPenjualan.AmbilDetail(hasil.uuid),
            pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
          ),
        ),
        LebarKertas.Mm58,
      ).map((b) => b.trim()).toList();
      expect(struk, contains('Pesanan GoFood #F-3281937'));
      expect(struk.any((b) => b.startsWith('GoFood')), isTrue, reason: struk.join('\n'));
    },
  );
}
