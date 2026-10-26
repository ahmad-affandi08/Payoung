import 'package:mesin_kasir/MesinKasir.dart';

import '../GalatKasir.dart';
import 'AturanApotek.dart';

/// Satu obat/bahan di racikan: jumlah untuk **satu racikan** dalam satuan yang dipilih (null = satuan dasar).
class KomponenRacikan {
  const KomponenRacikan({
    required this.uuidProduk,
    required this.nama,
    required this.jumlah,
    this.uuidProdukSatuan,
    this.namaSatuan,
    this.golonganObat,
  });

  final String uuidProduk;
  final String nama;
  final String? uuidProdukSatuan;
  final String? namaSatuan;
  final Kuantitas jumlah;

  /// Snapshot golongan obat dari katalog lokal (null = bukan obat).
  final String? golonganObat;

  Map<String, Object?> KeJson() => {
    'UuidProduk': uuidProduk,
    'Nama': nama,
    'UuidProdukSatuan': uuidProdukSatuan,
    'NamaSatuan': namaSatuan,
    'Jumlah': jumlah.KeString(),
    'GolonganObat': golonganObat,
  };

  static KomponenRacikan DariJson(Map<String, Object?> json) => KomponenRacikan(
    uuidProduk: json['UuidProduk']! as String,
    nama: '${json['Nama'] ?? ''}',
    uuidProdukSatuan: json['UuidProdukSatuan'] as String?,
    namaSatuan: json['NamaSatuan'] as String?,
    jumlah: Kuantitas.Dari('${json['Jumlah'] ?? '0'}'),
    golonganObat: json['GolonganObat'] as String?,
  );
}

/// Apotek bagian 4 (§9.5 "racikan: resep racik sebagai produk `recipe` sementara"): racikan yang disusun apoteker di
/// kasir dan dibawa satu baris **jasa racik**. Server mengurangi stok komponennya (FEFO untuk obat ber-batch) di
/// transaksi penjualan yang sama, memakai golongan terkuat komponennya untuk aturan resep & apoteker, dan menolak
/// returnya. Bentuk kontrak `Baris[].Racikan {Nama, JumlahKemasan, AturanPakai?, Komponen[{UuidProduk,
/// UuidProdukSatuan?, Jumlah}]}`.
class RacikanBaris {
  const RacikanBaris({required this.nama, required this.jumlahKemasan, required this.komponen, this.aturanPakai});

  static const int panjangNama = 100;
  static const int panjangAturanPakai = 100;
  static const int maksKemasan = 999;
  static const int maksKomponen = 20;

  final String nama;

  /// Jumlah bungkus/kapsul/pot hasil racikan.
  final int jumlahKemasan;

  /// Signa, misal "3 x 1 bungkus sesudah makan".
  final String? aturanPakai;
  final List<KomponenRacikan> komponen;

  /// Golongan terkuat komponen; racikan tidak pernah dianggap Obat Wajib Apotek (berasal dari resep dokter), sama
  /// dengan server. Null = semua komponen bukan obat.
  InfoObat? get Obat {
    GolonganObat? terkuat;
    for (final k in komponen) {
      final g = GolonganObat.Dari(k.golonganObat);
      if (g != null && (terkuat == null || g.index > terkuat.index)) {
        terkuat = g;
      }
    }
    return terkuat == null ? null : InfoObat(golongan: terkuat);
  }

  /// "10 kemasan | 3 x 1 bungkus sesudah makan" (keranjang & struk).
  String get Ringkasan => ['$jumlahKemasan kemasan', ?aturanPakai].join(' | ');

  Map<String, Object?> KeJson() => {
    'Nama': nama,
    'JumlahKemasan': jumlahKemasan,
    'AturanPakai': aturanPakai,
    'Komponen': [for (final k in komponen) k.KeJson()],
  };

  static RacikanBaris? DariJson(Object? json) {
    if (json is! Map<String, Object?>) {
      return null;
    }
    return RacikanBaris(
      nama: '${json['Nama'] ?? ''}',
      jumlahKemasan: (json['JumlahKemasan'] as num?)?.toInt() ?? 1,
      aturanPakai: json['AturanPakai'] as String?,
      komponen: [
        for (final k in (json['Komponen'] as List<Object?>? ?? const []).whereType<Map<String, Object?>>())
          KomponenRacikan.DariJson(k),
      ],
    );
  }

  /// Muatan `Baris[].Racikan` untuk `Penjualan.Buat` (nama & golongan komponen tidak dikirim; server membacanya dari
  /// katalog).
  Map<String, Object?> KeMuatan() => {
    'Nama': nama,
    'JumlahKemasan': jumlahKemasan,
    'AturanPakai': ?aturanPakai,
    'Komponen': [
      for (final k in komponen)
        {'UuidProduk': k.uuidProduk, 'UuidProdukSatuan': ?k.uuidProdukSatuan, 'Jumlah': k.jumlah.KeString()},
    ],
  };

  /// Rapikan & periksa isian racikan dengan batas yang sama dengan server; melempar [GalatKasir] bila tidak valid.
  static RacikanBaris Susun({
    required String nama,
    required int jumlahKemasan,
    required List<KomponenRacikan> komponen,
    String aturanPakai = '',
  }) {
    final rapi = nama.trim();
    final signa = aturanPakai.trim();
    final galat = switch (true) {
      _ when rapi.isEmpty => 'Isi nama racikan.',
      _ when rapi.length > panjangNama => 'Nama racikan paling panjang $panjangNama karakter.',
      _ when jumlahKemasan < 1 || jumlahKemasan > maksKemasan => 'Jumlah kemasan 1 sampai $maksKemasan.',
      _ when signa.length > panjangAturanPakai => 'Aturan pakai paling panjang $panjangAturanPakai karakter.',
      _ when komponen.isEmpty => 'Tambahkan minimal satu obat ke racikan.',
      _ when komponen.length > maksKomponen => 'Racikan paling banyak $maksKomponen obat.',
      _ when komponen.map((k) => k.uuidProduk).toSet().length != komponen.length =>
        'Ada obat yang tercantum dua kali. Gabungkan jumlahnya.',
      _ when komponen.any((k) => k.jumlah.BernilaiNol() || k.jumlah.BernilaiNegatif()) =>
        'Jumlah setiap obat harus lebih dari 0.',
      _ => null,
    };
    if (galat != null) {
      throw GalatKasir('RacikanTidakValid', galat);
    }
    return RacikanBaris(
      nama: rapi,
      jumlahKemasan: jumlahKemasan,
      aturanPakai: signa.isEmpty ? null : signa,
      komponen: komponen,
    );
  }
}
