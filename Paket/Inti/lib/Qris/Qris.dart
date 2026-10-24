import 'dart:convert';

/// Galat pemeriksaan atau pembuatan QRIS. [kode] sama di PHP, Dart, dan TypeScript (vektor bersama
/// `Spesifikasi/VektorUjiQris/QrisDinamis.json`): `Kosong`, `BukanQris`, `CrcTidakValid`, `BukanStatis`,
/// `BukanRupiahIndonesia`, `TanpaMerchant`, `NominalTidakSah`.
final class GalatQris implements Exception {
  const GalatQris(this.kode, this.pesan);

  final String kode;
  final String pesan;

  @override
  String toString() => 'GalatQris($kode): $pesan';
}

/// Satu elemen TLV dari payload QRIS (tag 2 digit, panjang 2 digit, nilai).
final class BagianQris {
  const BagianQris(this.tag, this.nilai);

  final String tag;
  final String nilai;
}

/// Ringkasan QRIS statis yang sah, untuk ditampilkan ke pemilik toko saat memeriksa hasil baca gambar.
final class InfoQris {
  const InfoQris({required this.namaMerchant, required this.kota});

  final String namaMerchant;
  final String kota;
}

/// QRIS statis milik toko diubah jadi QRIS bernominal di perangkat (D-189): tag 01 jadi `12`, tag 54 (nominal)
/// disisipkan sesudah tag 53, CRC16 (CCITT-FALSE, polinomial 0x1021, awal 0xFFFF) dihitung ulang. Tanpa penyedia
/// pembayaran dan tanpa internet. Penerimaan dana tetap dikonfirmasi kasir, karena tidak ada notifikasi otomatis.
///
/// Dart murni; padanan `App\Domain\Penjualan\Layanan\PemeriksaQris` (PHP) dan `Pustaka/Qris.ts` (web).
abstract final class Qris {
  /// CRC16/CCITT-FALSE atas byte [teks], empat digit heksa huruf besar.
  static String HitungCrc16(String teks) {
    var crc = 0xFFFF;
    for (final byte in utf8.encode(teks)) {
      crc ^= byte << 8;
      for (var i = 0; i < 8; i++) {
        crc = (crc & 0x8000) != 0 ? ((crc << 1) ^ 0x1021) & 0xFFFF : (crc << 1) & 0xFFFF;
      }
    }
    return crc.toRadixString(16).toUpperCase().padLeft(4, '0');
  }

  /// Menguraikan payload menjadi TLV tingkat atas. [GalatQris] `BukanQris` bila bukan QRIS atau strukturnya terpotong.
  static List<BagianQris> Urai(String teks) {
    if (teks.length < 12 || !teks.startsWith('000201')) {
      throw const GalatQris(
        'BukanQris',
        'Ini bukan kode QRIS. Pastikan gambar berisi QRIS dari bank atau penyedia Anda.',
      );
    }
    final hasil = <BagianQris>[];
    var i = 0;
    while (i < teks.length) {
      if (i + 4 > teks.length) {
        throw const GalatQris('BukanQris', 'Kode QRIS terpotong. Gunakan gambar QRIS yang utuh.');
      }
      final tag = teks.substring(i, i + 2);
      final panjang = int.tryParse(teks.substring(i + 2, i + 4));
      if (panjang == null ||
          !RegExp(r'^\d{2}$').hasMatch(tag) ||
          !RegExp(r'^\d{2}$').hasMatch(teks.substring(i + 2, i + 4)) ||
          i + 4 + panjang > teks.length) {
        throw const GalatQris('BukanQris', 'Kode QRIS terpotong. Gunakan gambar QRIS yang utuh.');
      }
      hasil.add(BagianQris(tag, teks.substring(i + 4, i + 4 + panjang)));
      i += 4 + panjang;
    }
    return hasil;
  }

  /// Memeriksa bahwa [teks] adalah QRIS **statis** yang utuh (CRC sah, rupiah Indonesia, ada blok merchant).
  static InfoQris Periksa(String teks) {
    final isi = teks.trim();
    if (isi.isEmpty) {
      throw const GalatQris('Kosong', 'Kode QRIS kosong.');
    }
    final bagian = Urai(isi);
    if (bagian.last.tag != '63' || bagian.last.nilai.length != 4) {
      throw const GalatQris('BukanQris', 'Kode QRIS tidak lengkap. Gunakan gambar QRIS yang utuh.');
    }
    if (HitungCrc16(isi.substring(0, isi.length - 4)) != bagian.last.nilai.toUpperCase()) {
      throw const GalatQris(
        'CrcTidakValid',
        'Kode QRIS rusak atau sudah diubah (checksum tidak cocok). Unggah ulang gambar QRIS asli.',
      );
    }
    String? Nilai(String tag) {
      for (final b in bagian) {
        if (b.tag == tag) {
          return b.nilai;
        }
      }
      return null;
    }

    final titik = Nilai('01');
    if (titik == '12') {
      throw const GalatQris('BukanStatis', 'QRIS ini sudah berisi nominal (dinamis). Unggah QRIS statis toko Anda.');
    }
    if (titik != '11') {
      throw const GalatQris('BukanQris', 'Jenis QRIS tidak dikenali. Unggah QRIS statis toko Anda.');
    }
    if (Nilai('53') != '360' || Nilai('58') != 'ID') {
      throw const GalatQris('BukanRupiahIndonesia', 'QRIS ini bukan QRIS rupiah Indonesia.');
    }
    if (!bagian.any((b) => int.parse(b.tag) >= 26 && int.parse(b.tag) <= 51)) {
      throw const GalatQris(
        'TanpaMerchant',
        'QRIS ini tidak memuat data merchant. Unggah QRIS dari bank atau penyedia Anda.',
      );
    }
    return InfoQris(namaMerchant: Nilai('59') ?? '', kota: Nilai('60') ?? '');
  }

  /// Nominal tag 54: rupiah bulat atau dengan satu/dua desimal; ".00" dibuang. [GalatQris] `NominalTidakSah` bila
  /// bukan angka positif, lebih dari 10 digit bulat, atau lebih dari dua desimal.
  static String NormalkanNominal(String nominal) {
    final isi = nominal.trim();
    if (!RegExp(r'^\d{1,10}(\.\d{1,2})?$').hasMatch(isi) || RegExp(r'^0+(\.0+)?$').hasMatch(isi)) {
      throw const GalatQris('NominalTidakSah', 'Nominal QRIS harus lebih dari nol.');
    }
    return RegExp(r'^\d+\.0+$').hasMatch(isi) ? isi.substring(0, isi.indexOf('.')) : isi;
  }

  /// QRIS bernominal dari [teksStatis] untuk [nominal] (string desimal, mis. `"25000.00"`).
  static String BuatDinamis(String teksStatis, String nominal) {
    final isi = teksStatis.trim();
    Periksa(isi);
    final tag54 = NormalkanNominal(nominal);
    final badan = StringBuffer();
    for (final b in Urai(isi)) {
      if (b.tag == '54' || b.tag == '63') {
        continue;
      }
      badan.write(_Tlv(b.tag, b.tag == '01' ? '12' : b.nilai));
      if (b.tag == '53') {
        badan.write(_Tlv('54', tag54));
      }
    }
    badan.write('6304');
    final tanpaCrc = badan.toString();
    return '$tanpaCrc${HitungCrc16(tanpaCrc)}';
  }

  static String _Tlv(String tag, String nilai) => '$tag${nilai.length.toString().padLeft(2, '0')}$nilai';
}
