import 'package:flutter/material.dart';

/// Ilustrasi merek Aplikasi Owner (D-231): flat, berpalet merek, netral untuk semua sektor usaha. Sumber SVG dan
/// ekspor PNG transparan 3x ada di `Spesifikasi/Merek/BuatIlustrasiOwner.py`; nama berkas = nama nilai.
enum IlustrasiOwner {
  /// Payung menadah koin: wajah merek di layar Masuk.
  Payung,

  /// Toko dengan tenda belang: kartu sorotan Beranda dan profil.
  Toko,

  /// Papan persetujuan bertanda centang: layar Persetujuan dan keadaan "semua beres".
  PapanSetuju,

  /// Tumpukan kardus: ringkasan stok.
  KotakStok,

  /// Lonceng dengan tanda centang: notifikasi sudah dibaca semua.
  Lonceng,

  /// Wajah pemilik di lingkaran mint: avatar bilah atas dan profil.
  Avatar,

  /// Empat ilustrasi mini ubin laporan.
  MiniPenjualan,
  MiniLaba,
  MiniKas,
  MiniWaspada,
}

/// Menampilkan [ilustrasi] selebar [lebar]. Dekoratif: tidak dibacakan pembaca layar (namanya selalu tertulis di
/// dekatnya), dan bila berkas gagal dimuat tempatnya dikosongkan agar tata letak tidak bergeser.
class IlustrasiMerek extends StatelessWidget {
  const IlustrasiMerek(this.ilustrasi, {super.key, required this.lebar});

  final IlustrasiOwner ilustrasi;
  final double lebar;

  @override
  Widget build(BuildContext context) => Image.asset(
    'assets/ilustrasi/owner/${ilustrasi.name}.png',
    package: 'sistem_desain',
    key: ValueKey('Ilustrasi${ilustrasi.name}'),
    width: lebar,
    fit: BoxFit.contain,
    excludeFromSemantics: true,
    errorBuilder: (_, _, _) => SizedBox(width: lebar),
  );
}
