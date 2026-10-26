import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// v3.29 (F-17 bagian 3, F-16c gratis ongkir): ongkir penjualan kasir yang diantar toko sendiri (kanal Antar, bukan
/// pesanan online). Kasir mengisi ongkir kotor; potongan gratis ongkir hanya dari promo yang dihitung mesin promo, lalu
/// `Penjualan.Buat` membawa `BiayaKirim` & `DiskonKirim` yang diperiksa ulang server.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  Map<String, Object?> PromoGratisOngkir({String minimal = '50000'}) => {
    'ModeResolusi': 'Terbaik',
    'Promo': [
      {
        'Uuid': '01K5PROMO0000000000ONGK1R1',
        'Kode': 'ONGKIR-50K',
        'Nama': 'Gratis ongkir belanja Rp 50.000',
        'Prioritas': 0,
        'Eksklusif': false,
        'MulaiPada': null,
        'SelesaiPada': null,
        'KuotaTersisa': null,
        'Definisi': {
          'MinimalSubtotal': minimal,
          'Aksi': {'Jenis': 'GratisOngkir'},
        },
      },
    ],
  };

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  Future<Keranjang> KeranjangCroissant(int jumlah) async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    var keranjang = u.penjualan.GantiKanal(Keranjang.kosong, KanalPenjualan.Antar, katalog, k);
    for (var i = 0; i < jumlah; i++) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
        katalog,
        k,
      );
    }
    return keranjang;
  }

  test(
    'ongkir hanya untuk kanal Antar, rupiah bulat, 0 sampai batas wajar. Keluar dari Antar menghapus ongkir',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      expect(
        () => u.penjualan.AturOngkir(Keranjang.kosong, Uang.DariBulat(15000)),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'OngkirHanyaAntar')),
      );

      final antar = u.penjualan.GantiKanal(Keranjang.kosong, KanalPenjualan.Antar, katalog, k);
      expect(u.penjualan.AturOngkir(antar, Uang.DariBulat(15000)).biayaKirim, Uang.DariBulat(15000));
      for (final salah in [Uang.DariBulat(-1), Uang.DariBulat(5000001), Uang.Dari('15000.50')]) {
        expect(
          () => u.penjualan.AturOngkir(antar, salah),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'OngkirTidakWajar')),
          reason: salah.KeString(),
        );
      }

      final berongkir = u.penjualan.AturOngkir(antar, Uang.DariBulat(15000));
      expect(
        Keranjang.DariJson(berongkir.KeJson()).biayaKirim,
        Uang.DariBulat(15000),
        reason: 'Ikut pesanan tertahan.',
      );
      final pulang = u.penjualan.GantiKanal(berongkir, KanalPenjualan.BawaPulang, katalog, k);
      expect(pulang.biayaKirim.BernilaiNol(), isTrue);
      expect(pulang.diskonKirim.BernilaiNol(), isTrue);
    },
  );

  test('promo gratis ongkir aktif menawarkan pilihan kanal walau tanpa platform/daftar harga berkanal', () async {
    expect(LayananPenjualan.AmbilPilihanKanal(await u.MuatKonteks(), await u.MuatKatalog()), isEmpty);
    await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode(PromoGratisOngkir()));
    expect(
      LayananPenjualan.AmbilPilihanKanal(await u.MuatKonteks(), await u.MuatKatalog()),
      contains(KanalPenjualan.Antar),
    );
  });

  test('gratis ongkir dari promo: di bawah minimal ongkir ditagih penuh. Di atas minimal ongkir nol. Outbox membawa '
      'BiayaKirim & DiskonKirim', () async {
    await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode(PromoGratisOngkir()));
    final k = await u.MuatKonteks();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

    // 1 croissant (Rp 25.000) < minimal Rp 50.000: ongkir tetap ditagih.
    final satu = u.penjualan.AturOngkir(await KeranjangCroissant(1), Uang.DariBulat(15000));
    final hasilSatu = u.penjualan.Hitung(satu, k).hasil;
    expect(hasilSatu.biayaKirim, Uang.DariBulat(15000));
    expect(hasilSatu.diskonKirim.BernilaiNol(), isTrue);

    // 2 croissant (Rp 50.000) memenuhi minimal: ongkir digratiskan penuh oleh promo.
    final dua = u.penjualan.AturOngkir(await KeranjangCroissant(2), Uang.DariBulat(15000));
    final hasilDua = u.penjualan.Hitung(dua, k).hasil;
    expect(hasilDua.biayaKirim, Uang.DariBulat(15000));
    expect(hasilDua.diskonKirim, Uang.DariBulat(15000));

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == JenisMetodeBayar.tunai);
    final simpan = await u.penjualan.Bayar(
      keranjang: dua,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
      kasir: rina,
      k: k,
    );
    final outbox = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == simpan.uuid);
    final data = jsonDecode(outbox.Data) as Map<String, Object?>;
    expect(data['Kanal'], 'Antar');
    expect(data['BiayaKirim'], '15000.00');
    expect(data['DiskonKirim'], '15000.00');
    final lokal = (await u.db.select(u.db.penjualan).get()).single;
    expect((lokal.BiayaKirim, lokal.DiskonKirim), ('15000.00', '15000.00'));
  });
}
