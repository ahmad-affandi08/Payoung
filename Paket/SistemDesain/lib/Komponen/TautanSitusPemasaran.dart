import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../Token/TokenJarak.dart';
import '../Token/TokenWarna.dart';

/// Penunjuk ke situs pemasaran Payoung untuk orang yang mengunduh aplikasi lebih dulu dan belum punya akun: tanpa ini
/// layar masuk/aktivasi buntu karena akun dan kode aktivasi dibuat di situs/back-office, bukan di aplikasi.
///
/// Dibuka di peramban luar. [saatBuka] hanya untuk tes (mengganti `launchUrl`); bila peramban tidak bisa dibuka, alamat
/// ditampilkan di pesan supaya pengguna bisa mengetiknya sendiri.
class TautanSitusPemasaran extends StatelessWidget {
  const TautanSitusPemasaran({super.key, this.saatBuka});

  /// Alamat situs pemasaran. Domain pemasaran tetap `payoung.id` (D-20), terpisah dari server tiap toko.
  static final Uri alamat = Uri.parse('https://payoung.id');

  final Future<bool> Function(Uri alamat)? saatBuka;

  Future<void> _Buka(BuildContext context) async {
    final pesan = ScaffoldMessenger.of(context);
    var berhasil = false;
    try {
      berhasil = await (saatBuka ?? (a) => launchUrl(a, mode: LaunchMode.externalApplication))(alamat);
    } on Object {
      berhasil = false;
    }
    if (!berhasil) {
      pesan.showSnackBar(const SnackBar(content: Text('Peramban tidak bisa dibuka. Buka payoung.id secara manual.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          'Belum punya akun Payoung?',
          textAlign: TextAlign.center,
          style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
        ),
        TextButton.icon(
          key: const ValueKey('TautanSitusPemasaran'),
          style: TextButton.styleFrom(minimumSize: const Size(0, TokenJarak.targetSentuh)),
          onPressed: () => _Buka(context),
          icon: const Icon(Icons.open_in_new, size: TokenJarak.ikonKecil),
          label: const Text('Daftar atau kenali Payoung di payoung.id'),
        ),
      ],
    );
  }
}
