import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Sesi/StafLokal.dart';
import 'Keranjang.dart';
import 'KonteksPenjualan.dart';
import 'LayananPenjualan.dart';

/// Bengkel bagian 2 (§9.10, K-27): menagih perintah kerja (WO) di kasir. Pola sama dengan reservasi layanan:
/// - **Ambil** (online): daftar perintah kerja outlet ini yang siap ditagih (atau semua yang berjalan); offline →
///   `PerluOnline`.
/// - **Muat ke keranjang**: baris yang disetujui pelanggan masuk keranjang dengan harga & diskon yang disepakati di
///   perintah kerja (server menerima `HargaSatuan` perangkat lalu menghitung ulang, seperti harga pre-order), mekanik
///   baris jasa menjadi staf baris (komisi F-18), dan pelanggannya terpasang. `Penjualan.Buat` membawa
///   `UuidPerintahKerja` sehingga server menandainya Ditagih di transaksi yang sama. Pembayaran tetap offline-first.
class LayananPerintahKerja {
  LayananPerintahKerja({required this.klien, required this.penjualan});

  final KlienPos klien;
  final LayananPenjualan penjualan;

  Future<List<PerintahKerjaPos>> Ambil({bool semuaAktif = false}) =>
      _Online(() => klien.AmbilPerintahKerja(semuaAktif: semuaAktif), 'Melihat perintah kerja');

  /// Baca ulang satu perintah kerja tepat sebelum ditagih (status & baris terbaru dari server).
  Future<PerintahKerjaPos> AmbilSatu(String uuid) =>
      _Online(() => klien.AmbilSatuPerintahKerja(uuid), 'Membuka perintah kerja');

  /// Kasir yang boleh menagih: yang boleh berjualan.
  static void PeriksaIzin(StafLokal kasir) {
    if (!kasir.PunyaIzin(IzinKasir.penjualanBuat)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin berjualan.');
    }
  }

  /// Keranjang berisi baris perintah kerja yang disetujui, mekaniknya, dan pelanggannya.
  Keranjang MuatKeKeranjang(PerintahKerjaPos pk, KatalogLokal katalog, KonteksPenjualan k) {
    if (!pk.siapTagih) {
      throw GalatKasir(
        'PerintahKerjaBelumSiap',
        'Perintah kerja ${pk.nomor} berstatus ${pk.labelStatus.toLowerCase()}, belum bisa ditagih.',
      );
    }
    if (pk.baris.isEmpty) {
      throw GalatKasir('PerintahKerjaKosong', 'Perintah kerja ${pk.nomor} belum punya baris yang disetujui pelanggan.');
    }
    final data = pk.pelanggan;
    final pelanggan = data == null || data['Uuid'] is! String
        ? null
        : PelangganTerpilih(
            uuid: data['Uuid']! as String,
            nama: '${data['Nama'] ?? ''}',
            noHpSamar: '${data['NoHp'] ?? ''}',
            kodeTier: data['KodeTier'] as String?,
            namaTier: data['NamaTier'] as String?,
          );
    final baris = <ItemKeranjang>[];
    for (final b in pk.baris) {
      final produk = katalog.CariProduk(b.uuidProduk);
      if (produk == null) {
        throw GalatKasir(
          'ProdukTidakDikenal',
          '"${b.namaProduk}" belum ada di katalog perangkat ini. Perbarui katalog, lalu coba lagi.',
        );
      }
      final satuan = produk.satuan.where((s) => s.uuid == b.uuidProdukSatuan).firstOrNull ?? produk.AmbilSatuanBawaan();
      final jumlah = Kuantitas.Dari(b.jumlah);
      final item = penjualan.BuatBaris(
        katalog,
        k,
        produk,
        satuan: satuan,
        jumlah: jumlah,
        tierPelanggan: pelanggan?.kodeTier,
        hargaDokumen: Uang.Dari(b.hargaSatuan),
      );
      final diskon = Uang.Dari(b.diskon);
      final mekanik = b.uuidKaryawan;
      baris.add(
        item.Salin(
          jumlah: jumlah,
          diskon: diskon.BernilaiNol() || diskon.BernilaiNegatif() ? null : () => DiskonManual.DariJumlah(diskon),
          staf: b.CekJasa && mekanik != null ? [mekanik] : null,
          // Nomor seri unit yang dicatat di perintah kerja ikut baris; jumlahnya tetap jumlah WO. Kosong = kasir mengisi
          // di panel item sebelum bayar (Bayar menolak baris bernomor seri yang belum lengkap).
          nomorSeri: produk.bernomorSeri && b.nomorSeri.isNotEmpty ? b.nomorSeri : null,
        ),
      );
    }
    return Keranjang(
      baris: baris,
      pelanggan: pelanggan,
      perintahKerja: PerintahKerjaKeranjang(
        uuid: pk.uuid,
        nomor: pk.nomor,
        nomorPolisi: pk.nomorPolisi,
        labelKendaraan: pk.labelKendaraan,
        jenisUnit: pk.jenisUnit,
      ),
    );
  }

  Future<T> _Online<T>(Future<T> Function() kerja, String tindakan) async {
    try {
      return await kerja();
    } on GalatJaringan {
      throw GalatKasir('PerluOnline', '$tindakan perlu koneksi internet. Coba lagi saat perangkat online.');
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }
}
