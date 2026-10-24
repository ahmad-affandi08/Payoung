import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show JangkaKuotaTransaksi;
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Penjualan/KuotaTransaksiEfektif.dart';
import 'IsiAreaKerja.dart';

/// P-04: kuota transaksi penjualan (bulan atau hari) habis. Layar jual & meja diganti panel ini; yang sudah terjadi tetap
/// tersimpan dan terkirim, dan layar lain (shift, kas, riwayat, stok, sinkron) tetap bisa dipakai. "Cek ulang" membaca
/// kuota dari server supaya kasir bisa lanjut begitu pemilik menaikkan paket, tanpa menunggu pemeriksaan berkala.
class PanelKuotaTransaksiHabis extends ConsumerStatefulWidget {
  const PanelKuotaTransaksiHabis({required this.kuota, super.key});

  final KuotaTransaksiEfektif kuota;

  @override
  ConsumerState<PanelKuotaTransaksiHabis> createState() => _PanelKuotaTransaksiHabisState();
}

class _PanelKuotaTransaksiHabisState extends ConsumerState<PanelKuotaTransaksiHabis> {
  bool _memeriksa = false;

  Future<void> _CekUlang() async {
    setState(() => _memeriksa = true);
    try {
      await ref.read(penyediaKonfigurasiAplikasi.notifier).Periksa(paksa: true);
    } finally {
      if (mounted) {
        setState(() => _memeriksa = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;

    return IsiAreaKerja(
      judul: 'Kuota transaksi ${widget.kuota.sebutan} habis',
      anak: [
        Text(
          'Paket usaha ini mencakup ${widget.kuota.batas} transaksi ${widget.kuota.satuan} dan semuanya sudah terpakai. '
          'Transaksi baru tidak bisa dibuat sampai kuota bertambah atau ${widget.kuota.jangka == JangkaKuotaTransaksi.Hari ? 'hari bisnis berganti' : 'bulan depan'}.',
          style: teks.titleMedium,
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Transaksi yang sudah dibuat tetap tersimpan dan dikirim. Minta pemilik usaha menaikkan paket atau menambah '
          'add-on di menu Langganan, lalu ketuk Cek ulang.',
          style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
        ),
        if (tertunda > 0) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Text('$tertunda transaksi menunggu terkirim.', style: teks.bodyMedium),
        ],
        const SizedBox(height: TokenJarak.jarak12),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            onPressed: _memeriksa ? null : () => unawaited(_CekUlang()),
            icon: const Icon(Icons.refresh),
            label: Text(_memeriksa ? 'Memeriksa…' : 'Cek ulang'),
          ),
        ),
      ],
    );
  }
}
