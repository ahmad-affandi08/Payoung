import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Meja/KonteksPesananMeja.dart';
import 'package:kasir/Domain/Meja/MinimumChargePos.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// FnB minimum charge (§9.1, D-144): minimum per meja disimpan sebagai pengaturan (offline), dan selisih ke minimum
/// ditagih sebagai SATU baris produk penampung (harga terbuka) yang dihitung ulang tiap kali — idempoten, Uuid baris
/// tetap, hilang bila belanja sudah cukup. Nilai belanja = subtotal setelah diskon (sama dengan pemeriksaan server).
void main() {
  late LingkunganUji u;
  const meja = KonteksPesananMeja(uuid: 'PSN1', nomor: 'PSN-1', uuidMeja: 'MJ-VIP', namaMeja: 'VIP 1', label: null);

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog(KatalogHargaTerbukaUji());
    await u.repositori.SimpanPengaturan(
      KunciPengaturan.minimumChargeMeja,
      jsonEncode({
        'UuidProduk': barangLainLain,
        'Meja': {'MJ-VIP': '150000.00'},
      }),
    );
  });

  tearDown(() => u.Tutup());

  test('pengaturan tersimpan diurai. Bentuk rusak atau kosong = tanpa minimum', () {
    final pos = MinimumChargePos.DariJson({
      'UuidProduk': 'P1',
      'Meja': {'M1': '150000.00', 'M2': 'bukan-uang', 'M3': 5},
    });
    expect(pos.uuidProduk, 'P1');
    expect(pos.perMeja.keys, ['M1']);
    expect(pos.Ambil('M1')?.KeString(), '150000.00');
    expect(pos.Ambil('M2'), isNull);
    expect(pos.Ambil(null), isNull);
    expect(MinimumChargePos.DariJson(null).Ambil('M1'), isNull);
    expect(MinimumChargePos.DariJson('rusak').perMeja, isEmpty);
    // Tanpa produk penampung, minimum tidak bisa ditagih.
    expect(const MinimumChargePos(perMeja: {}).Ambil('M1'), isNull);
  });

  test('konteks memuat minimum dari pengaturan', () async {
    final k = await u.MuatKonteks();
    expect(k.minimumCharge.Ambil('MJ-VIP')?.KeString(), '150000.00');
    expect(k.minimumCharge.Ambil('MJ-LAIN'), isNull);
  });

  test('selisih ditagih satu baris sampai belanja = minimum; belanja cukup menghilangkannya; idempoten', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    var keranjang = Keranjang.kosong.Salin(pesananMeja: () => meja);
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
      katalog,
      k,
    );

    final dengan = u.penjualan.TerapkanMinimumCharge(keranjang, katalog, k);
    final selisih = dengan.baris.where((b) => b.uuidProduk == barangLainLain).toList();
    expect(selisih, hasLength(1));
    final hasil = u.penjualan.Hitung(dengan, k).hasil;
    expect(hasil.subtotal.Kurangi(hasil.totalDiskon).KeString(), '150000.00');

    // Idempoten: dihitung ulang dari keranjang yang sudah berisi baris selisih memberi hasil sama, Uuid baris tetap.
    final lagi = u.penjualan.TerapkanMinimumCharge(dengan, katalog, k);
    expect(lagi.baris.where((b) => b.uuidProduk == barangLainLain).single.uuid, selisih.single.uuid);
    expect(
      u.penjualan.Hitung(lagi, k).hasil.totalAkhir.KeString(),
      u.penjualan.Hitung(dengan, k).hasil.totalAkhir.KeString(),
    );

    // Belanja bertambah banyak: selisih hilang, tak ada baris minimum charge.
    final banyak = u.penjualan.UbahJumlah(dengan, dengan.baris.first.uuid, Kuantitas.DariBulat(500), katalog, k);
    final cukup = u.penjualan.TerapkanMinimumCharge(banyak, katalog, k);
    expect(cukup.baris.any((b) => b.uuidProduk == barangLainLain), isFalse);
  });

  test(
    'tanpa minimum: meja lain, bukan pesanan meja, keranjang kosong, atau produk penampung tak ada di katalog',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      final isi = u.penjualan.TambahBaris(
        Keranjang.kosong,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
        katalog,
        k,
      );

      // Bukan pesanan meja.
      expect(u.penjualan.TerapkanMinimumCharge(isi, katalog, k).baris, hasLength(1));
      // Meja yang tidak punya minimum.
      final mejaBiasa = isi.Salin(
        pesananMeja: () =>
            const KonteksPesananMeja(uuid: 'P2', nomor: 'P-2', uuidMeja: 'MJ-BIASA', namaMeja: '3', label: null),
      );
      expect(u.penjualan.TerapkanMinimumCharge(mejaBiasa, katalog, k).baris, hasLength(1));
      // Keranjang kosong di meja ber-minimum tidak menagih apa pun.
      expect(
        u.penjualan.TerapkanMinimumCharge(Keranjang.kosong.Salin(pesananMeja: () => meja), katalog, k).baris,
        isEmpty,
      );
      // Produk penampung tidak ada di katalog perangkat (belum tersinkron): keranjang apa adanya.
      final tanpaProduk = Keranjang.kosong.Salin(pesananMeja: () => meja, baris: isi.baris);
      await u.repositori.SimpanPengaturan(
        KunciPengaturan.minimumChargeMeja,
        jsonEncode({
          'UuidProduk': 'TIDAK-ADA',
          'Meja': {'MJ-VIP': '150000.00'},
        }),
      );
      final kTanpa = await u.MuatKonteks();
      expect(u.penjualan.TerapkanMinimumCharge(tanpaProduk, katalog, kTanpa).baris, hasLength(1));
    },
  );
}
