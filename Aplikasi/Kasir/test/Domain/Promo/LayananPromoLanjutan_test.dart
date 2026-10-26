import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Pelanggan/LayananPelanggan.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-16c bagian 3 di perangkat: promo metode bayar hanya berlaku bila semua pembayaran memakai metodenya (dinilai saat
/// metode dipilih di layar Bayar), dan promo transaksi pertama/batas per pelanggan memakai data pelanggan di cache
/// yang ikut bertambah setelah penjualan tersimpan (tidak terpakai ulang selagi offline).
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  const uuidQris = '01K5MTD0000000000000000002';

  Map<String, Object?> Promo(String uuid, String kode, Map<String, Object?> syarat) => {
    'Uuid': uuid,
    'Kode': kode,
    'Nama': 'Promo $kode',
    'Prioritas': 1,
    'Eksklusif': false,
    'MulaiPada': null,
    'SelesaiPada': null,
    'KuotaTersisa': null,
    'Definisi': {
      'Aksi': {'Jenis': 'DiskonTetapPesanan', 'Jumlah': '5000'},
      ...syarat,
    },
  };

  Future<(KatalogLokal, KonteksPenjualan)> Siapkan(List<Map<String, Object?>> promo) async {
    await u.SiapkanKatalog();
    await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode({'ModeResolusi': 'Terbaik', 'Promo': promo}));
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    return (await u.MuatKatalog(), await u.MuatKonteks());
  }

  Keranjang SatuAmericano(KatalogLokal katalog, KonteksPenjualan k) => u.penjualan.TambahBaris(
    Keranjang.kosong,
    u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
    katalog,
    k,
  );

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  test(
    'promo QRIS: tidak di keranjang, berlaku saat QRIS dipilih, tidak bila sisa dibayar tunai. Ikut Penjualan.Buat',
    () async {
      final (katalog, k) = await Siapkan([
        Promo('01K5PROMO00000000000000011', 'QRIS5K', {
          'MetodeBayar': [uuidQris],
        }),
      ]);
      final keranjang = SatuAmericano(katalog, k);
      final qris = k.metodePembayaran.firstWhere((m) => m.Uuid == uuidQris);
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');

      final tanpaMetode = u.penjualan.Hitung(keranjang, k);
      expect(tanpaMetode.promoTerpakai, isEmpty, reason: 'Belum memilih pembayaran.');
      final denganQris = u.penjualan.Hitung(keranjang, k, metodeBayar: LayananPenjualan.AmbilMetodeBayar(k, [qris]));
      expect(denganQris.promoTerpakai.single.kode, 'QRIS5K');
      // Potongan pesanan sebelum pajak (pajak ikut turun).
      expect(denganQris.HitungDiskonPromoPesanan(), Uang.Dari('5000.00'));
      expect(denganQris.hasil.totalAkhir.Bandingkan(tanpaMetode.hasil.totalAkhir), lessThan(0));
      // Sisa dibayar tunai = tidak semua pembayaran QRIS.
      expect(u.penjualan.HitungTagihanTunai(keranjang, k, const []), tanpaMetode.hasil.totalAkhir);
      expect(LayananPenjualan.AmbilMetodeBayar(k, const []), isNull);
      expect(LayananPenjualan.AmbilMetodeBayar(k, const [], tambahanTunai: true), [tunai.Uuid]);

      await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: qris, jumlah: denganQris.hasil.totalAkhir, referensi: 'QR-1')],
        kasir: rina,
        k: k,
      );
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect((data['Promo']! as List<Object?>).single, containsPair('Kode', 'QRIS5K'));
    },
  );

  test(
    'transaksi pertama & batas 1x per hari memakai cache pelanggan. Setelah bayar cache bertambah → tidak berlaku lagi',
    () async {
      final (katalog, k) = await Siapkan([
        Promo('01K5PROMO00000000000000012', 'PERTAMA5K', {'TransaksiPertama': true}),
        Promo('01K5PROMO00000000000000013', 'HARIAN5K', {
          'BatasPerPelanggan': {'Jumlah': 1, 'Periode': 'Hari'},
        }),
      ]);
      final tanggal = u.penjualan.Hitung(Keranjang.kosong, k).tanggalBisnis;
      await u.repositoriPelanggan.Simpan(
        'PLG1',
        'Ani Rahmawati',
        '0812****7890',
        DateTime.now(),
        promo: (hariLahir: null, jumlahTransaksi: 0, pemakaianPromo: '{}', pemakaianPada: tanggal),
      );
      Future<PelangganTerpilih> Ani() async =>
          LayananPelanggan.DariCache((await u.repositoriPelanggan.AmbilTerakhir()).single);

      final keranjang = SatuAmericano(katalog, k).Salin(pelanggan: () => null);
      final pertama = keranjang.Salin(pelanggan: () => null);
      expect(u.penjualan.Hitung(pertama, k).promoTerpakai, isEmpty, reason: 'Tanpa pelanggan.');
      final denganAni = SatuAmericano(katalog, k).Salin(pelanggan: () => null);
      final ani = await Ani();
      final hitung1 = u.penjualan.Hitung(denganAni.Salin(pelanggan: () => ani), k);
      expect(hitung1.promoTerpakai.map((p) => p.kode), containsAll(['PERTAMA5K', 'HARIAN5K']));

      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      await u.penjualan.Bayar(
        keranjang: denganAni.Salin(pelanggan: () => ani),
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
        kasir: rina,
        k: k,
      );

      final setelah = await Ani();
      expect(setelah.jumlahTransaksi, 1);
      expect(setelah.pemakaianPromo['01K5PROMO00000000000000013']?.hari, 1);
      expect(setelah.pemakaianPada, tanggal);
      expect(
        u.penjualan.Hitung(SatuAmericano(katalog, k).Salin(pelanggan: () => setelah), k).promoTerpakai,
        isEmpty,
        reason: 'Bukan transaksi pertama lagi dan batas harian sudah terpakai.',
      );
      // Hari berikutnya: batas harian dihitung dari 0, transaksi pertama tetap tidak berlaku.
      expect(setelah.AmbilPemakaianPada('2099-01-01')['01K5PROMO00000000000000013']?.hari, 0);
    },
  );

  test('F-16c bagian 4a poin berlipat: tidak mengubah harga. Label "Poin 2×" hanya bila pelanggan dipilih', () async {
    final (katalog, k) = await Siapkan([
      {
        'Uuid': '01K5PROMO00000000000000021',
        'Kode': 'POIN2X',
        'Nama': 'Poin dobel akhir pekan',
        'Prioritas': 0,
        'Eksklusif': false,
        'MulaiPada': null,
        'SelesaiPada': null,
        'KuotaTersisa': null,
        'Definisi': {
          'Aksi': {'Jenis': 'PoinBerlipat', 'Pengali': '2'},
        },
      },
    ]);
    final keranjang = SatuAmericano(katalog, k);
    final tanpaPelanggan = u.penjualan.Hitung(keranjang, k);
    expect(tanpaPelanggan.promoTerpakai, isEmpty);
    expect(tanpaPelanggan.AmbilLabelPoinBerlipat(), isNull);

    await u.repositoriPelanggan.Simpan('PLG2', 'Budi Hartono', '0813****1111', DateTime.now());
    final budi = LayananPelanggan.DariCache((await u.repositoriPelanggan.AmbilTerakhir()).single);
    final denganPelanggan = u.penjualan.Hitung(keranjang.Salin(pelanggan: () => budi), k);
    expect(denganPelanggan.AmbilLabelPoinBerlipat(), 'Poin 2× | Poin dobel akhir pekan');
    expect(denganPelanggan.hasil.totalAkhir, tanpaPelanggan.hasil.totalAkhir);
    expect(denganPelanggan.promoTerpakai, isEmpty, reason: 'Promo poin tidak dikirim sebagai potongan.');

    // Struk setelah bayar mencetak label poin (diteruskan lewat hasil bayar).
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang.Salin(pelanggan: () => budi),
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
      kasir: rina,
      k: k,
    );
    expect(hasil.labelPoin, 'Poin 2× | Poin dobel akhir pekan');
  });
}
