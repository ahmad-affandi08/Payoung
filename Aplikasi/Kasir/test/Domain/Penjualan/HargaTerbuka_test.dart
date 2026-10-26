import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// K-25 item harga terbuka: harga ketikan kasir dipakai apa adanya (tidak ditentukan ulang saat jumlah/pelanggan
/// berubah), baris tidak digabung, harga harus > 0 & rupiah bulat, produk biasa menolak harga ketikan, dan harga ikut
/// `Penjualan.Buat` beserta keterangannya.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog(KatalogHargaTerbukaUji());
    rina = await u.Staf('Rina Wulandari');
  });

  tearDown(() => u.Tutup());

  test('katalog lokal membawa tanda harga terbuka dari server', () async {
    final katalog = await u.MuatKatalog();
    expect(katalog.CariProduk(barangLainLain)!.hargaTerbuka, isTrue);
    expect(katalog.CariProduk(UuidUji.americano)!.hargaTerbuka, isFalse);
  });

  test('harga ketikan dipakai, tetap saat jumlah diubah, dan baris sama tidak digabung', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final lain = katalog.CariProduk(barangLainLain)!;
    var keranjang = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(katalog, k, lain, hargaManual: Uang.DariBulat(27500), catatan: 'Kabel roll 5 m'),
      katalog,
      k,
    );
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, lain, hargaManual: Uang.DariBulat(8000)),
      katalog,
      k,
    );
    expect(keranjang.baris.map((b) => (b.hargaSatuan.KeString(), b.catatan, b.hargaTerbuka)), [
      ('27500.00', 'Kabel roll 5 m', true),
      ('8000.00', null, true),
    ]);

    keranjang = u.penjualan.UbahJumlah(keranjang, keranjang.baris.first.uuid, Kuantitas.DariBulat(3), katalog, k);
    keranjang = u.penjualan.HitungUlangHarga(keranjang, katalog, k);
    expect(keranjang.baris.first.hargaSatuan.KeString(), '27500.00');
    expect(u.penjualan.Hitung(keranjang, k).hasil.totalAkhir.Bandingkan(Uang.DariBulat(90500)) >= 0, isTrue);

    // Keranjang tersimpan (K-4) memulihkan tanda harga terbuka.
    final pulih = Keranjang.DariJson(jsonDecode(jsonEncode(keranjang.KeJson())) as Map<String, Object?>);
    expect(pulih.baris.map((b) => b.hargaTerbuka), [true, true]);
  });

  test('tanpa harga ketikan: harga daftar jadi bawaan, tanpa harga daftar ditolak', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    expect(u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(jasaServis)!).hargaSatuan.KeString(), '50000.00');
    expect(
      () => u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(barangLainLain)!),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'HargaTerbukaWajib')),
    );
  });

  test('harga ketikan ditolak untuk produk biasa, nol, atau berpecahan rupiah', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    Matcher Kode(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));
    expect(
      () => u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!, hargaManual: Uang.DariBulat(1)),
      Kode('HargaBukanTerbuka'),
    );
    final lain = katalog.CariProduk(barangLainLain)!;
    expect(() => u.penjualan.BuatBaris(katalog, k, lain, hargaManual: Uang.Nol()), Kode('HargaTidakValid'));
    expect(() => u.penjualan.BuatBaris(katalog, k, lain, hargaManual: Uang.Dari('1500.50')), Kode('HargaTidakValid'));
  });

  test('bayar: Penjualan.Buat membawa harga ketikan & keterangan baris', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    final keranjang = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(
        katalog,
        k,
        katalog.CariProduk(barangLainLain)!,
        hargaManual: Uang.DariBulat(27500),
        catatan: 'Kabel roll 5 m',
      ),
      katalog,
      k,
    );
    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(
          metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
          jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir,
        ),
      ],
      kasir: rina,
      k: k,
    );
    final outbox = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == hasil.uuid);
    final baris = ((jsonDecode(outbox.Data) as Map<String, Object?>)['Baris']! as List<Object?>).single! as Map;
    expect(
      (baris['UuidProduk'], baris['HargaSatuan'], baris['Catatan']),
      (barangLainLain, '27500.00', 'Kabel roll 5 m'),
    );
  });
}
