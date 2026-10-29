import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Tampilan/GerbangPemilik.dart';
import 'Lingkungan.dart';

/// Akar widget Aplikasi Owner (PRD §10.2a): masuk, pilih usaha, dasbor, laporan ringkas, shift, perangkat.
class AplikasiPemilik extends StatelessWidget {
  const AplikasiPemilik({super.key, required this.lingkungan});

  final Lingkungan lingkungan;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Payoung Owner',
      debugShowCheckedModeBanner: false,
      // Hanya tema terang, tanpa darkTheme (D-14).
      theme: BuatTemaModern(),
      themeMode: ThemeMode.light,
      builder: (context, anak) => lingkungan.tampilkanPenanda
          ? Banner(
              message: lingkungan.label,
              location: BannerLocation.topEnd,
              color: TokenWarna.AmbilDari(context).peringatan,
              child: anak,
            )
          : anak!,
      home: const GerbangPemilik(),
    );
  }
}
