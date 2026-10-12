import 'package:mesin_kasir/MesinKasir.dart';

/// K-14 (BR-08.2, v5.68): bagian bayar satu tamu pada tagihan yang dibagi. Hanya untuk struk per tamu; server tidak
/// menerimanya (penjualan tetap satu dokumen dengan beberapa pembayaran), jadi tersimpan lokal di perangkat kasir.
class BagianTamu {
  const BagianTamu({required this.namaMetode, required this.porsi, required this.diterima});

  factory BagianTamu.DariJson(Map<String, Object?> json) => BagianTamu(
    namaMetode: '${json['NamaMetode']}',
    porsi: Uang.Dari('${json['Porsi']}'),
    diterima: Uang.Dari('${json['Diterima']}'),
  );

  /// Nama metode yang dipakai tamu ini (mis. Tunai, QRIS).
  final String namaMetode;

  /// Bagian tagihan yang ditanggung tamu ini.
  final Uang porsi;

  /// Uang yang diserahkan tamu. Sama dengan [porsi] untuk metode non-tunai; boleh lebih untuk tunai.
  final Uang diterima;

  /// Kembalian tamu ini (hanya tunai yang uangnya melebihi porsi).
  Uang get kembalian => diterima.Bandingkan(porsi) > 0 ? diterima.Kurangi(porsi) : Uang.Nol();

  Map<String, Object?> KeJson() => {
    'NamaMetode': namaMetode,
    'Porsi': porsi.KeString(),
    'Diterima': diterima.KeString(),
  };
}

/// Rencana pembagian tagihan yang sudah dibayar: urutan [tamu] = urutan pembayaran (tamu 1, 2, dst.).
class BagianTagihan {
  const BagianTagihan({required this.rata, required this.tamu});

  factory BagianTagihan.DariJson(Map<String, Object?> json) => BagianTagihan(
    rata: json['Rata'] == true,
    tamu: [
      for (final t in (json['Tamu'] as List<Object?>? ?? const []))
        BagianTamu.DariJson((t! as Map).cast<String, Object?>()),
    ],
  );

  /// true = dibagi rata per orang; false = per nominal.
  final bool rata;
  final List<BagianTamu> tamu;

  Map<String, Object?> KeJson() => {
    'Rata': rata,
    'Tamu': [for (final t in tamu) t.KeJson()],
  };
}
