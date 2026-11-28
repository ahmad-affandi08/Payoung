import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// D-340: penanda permanen selama ada data yang ditolak server. Dulu hanya satu butir kecil di bilah status, sehingga
/// penjualan yang tertahan baru ketahuan keesokan harinya saat rekap kosong. Banner ini tidak bisa ditutup, hanya
/// hilang setelah semua data ditangani di layar Sinkron.
class BannerPerluTindakan extends StatelessWidget {
  const BannerPerluTindakan({super.key, required this.jumlah, required this.jumlahPenjualan, required this.saatLihat});

  /// Semua item outbox berstatus Perlu tindakan.
  final int jumlah;

  /// Sebagian di antaranya penjualan, supaya kasir paham uangnya belum masuk laporan.
  final int jumlahPenjualan;
  final VoidCallback saatLihat;

  static String SusunKalimat(int jumlah, int jumlahPenjualan) {
    final awal = '$jumlah data ditolak server';
    if (jumlahPenjualan > 0) {
      return '$awal, termasuk $jumlahPenjualan penjualan yang belum masuk laporan.';
    }
    return '$awal dan belum sampai ke laporan.';
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    return Semantics(
      container: true,
      liveRegion: true,
      label: SusunKalimat(jumlah, jumlahPenjualan),
      child: ColoredBox(
        key: const ValueKey('BannerPerluTindakan'),
        color: warna.bahaya.withValues(alpha: 0.12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak4),
          child: Row(
            children: [
              Icon(Icons.error_outline, color: warna.bahaya, size: TokenJarak.ikonKecil),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: Text(
                  SusunKalimat(jumlah, jumlahPenjualan),
                  style: teks.labelLarge?.copyWith(color: warna.bahaya),
                  maxLines: 3,
                ),
              ),
              TextButton(key: const ValueKey('LihatPerluTindakan'), onPressed: saatLihat, child: const Text('Lihat')),
            ],
          ),
        ),
      ),
    );
  }
}
