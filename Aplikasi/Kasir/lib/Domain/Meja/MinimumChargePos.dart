import 'package:mesin_kasir/MesinKasir.dart';

/// FnB minimum charge (§9.1, D-144) di perangkat: minimum belanja per meja (minimum meja, atau areanya) dan Uuid produk
/// "Selisih minimum charge" yang menampung selisihnya. Disimpan sebagai pengaturan dari `GET /api/pos/v1/meja`, jadi
/// bekerja offline. Kosong = tidak ada meja ber-minimum.
class MinimumChargePos {
  const MinimumChargePos({this.uuidProduk, this.perMeja = const {}});

  final String? uuidProduk;

  /// Minimum per Uuid meja. Meja tanpa minimum tidak ada di sini.
  final Map<String, Uang> perMeja;

  /// Minimum meja [uuidMeja]; null bila tanpa minimum atau produk penampungnya belum ada.
  Uang? Ambil(String? uuidMeja) => uuidMeja == null || uuidProduk == null ? null : perMeja[uuidMeja];

  /// Urai pengaturan tersimpan; bentuk tak dikenal = tanpa minimum (aplikasi tidak pernah gagal karenanya).
  static MinimumChargePos DariJson(Object? json) {
    if (json is! Map<String, Object?>) {
      return const MinimumChargePos();
    }
    final meja = json['Meja'];
    final hasil = <String, Uang>{};
    if (meja is Map) {
      for (final entri in meja.entries) {
        if (entri.key is String && entri.value is String) {
          try {
            hasil[entri.key as String] = Uang.Dari(entri.value as String);
          } on FormatException {
            continue;
          }
        }
      }
    }
    return MinimumChargePos(uuidProduk: json['UuidProduk'] as String?, perMeja: hasil);
  }
}
