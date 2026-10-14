import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import 'IsiAreaKerja.dart';
import 'KartuPembaruanAplikasi.dart';

/// P-10 (§14.6): aplikasi di bawah versi minimal. Layar jual & meja diganti panel ini; transaksi yang belum terkirim
/// tetap dikirim otomatis. Pembaruan dianjurkan setelah semua transaksi terkirim agar tidak ada yang tertinggal.
class PanelWajibPembaruan extends ConsumerWidget {
  const PanelWajibPembaruan({required this.konfigurasi, super.key});

  final KonfigurasiAplikasi konfigurasi;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;

    return IsiAreaKerja(
      judul: 'Perbarui aplikasi',
      anak: [
        Text(
          'Versi ${konfigurasi.versiSaatIni ?? 'ini'} sudah tidak didukung. Perbarui ke versi '
          '${konfigurasi.versiMinimal ?? konfigurasi.versiTerbaru ?? 'terbaru'} untuk berjualan lagi.',
          style: teks.titleMedium,
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          tertunda == 0
              ? 'Semua transaksi sudah terkirim. Aplikasi aman diperbarui sekarang.'
              : '$tertunda transaksi belum terkirim. Tunggu sampai terkirim (butuh internet), baru perbarui aplikasi.',
          style: teks.bodyMedium?.copyWith(color: tertunda == 0 ? warna.teksSekunder : warna.bahaya),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        // POS-23: Windows memasang sendiri; platform lain menampilkan tautan unduh / petunjuk toko aplikasi.
        KartuPembaruanAplikasi(konfigurasi: konfigurasi),
      ],
    );
  }
}
