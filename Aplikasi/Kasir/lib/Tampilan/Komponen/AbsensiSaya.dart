import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Karyawan/LayananAbsensi.dart';
import 'FormatWaktu.dart';

/// Absensi staf yang sedang bekerja di Ruang Kerja (F-18): masuk atau pulang tanpa keluar ke layar pilih kasir dan tanpa
/// PIN kedua (PIN sudah dipakai untuk masuk). Layanan, swafoto, dan antrean offline sama dengan layar Absen sebelum
/// masuk; yang berubah hanya tempatnya, supaya staf tanpa shift (pelayan, salesman) juga bisa absen pulang.
class PanelAbsensiSaya extends ConsumerStatefulWidget {
  const PanelAbsensiSaya({super.key});

  @override
  ConsumerState<PanelAbsensiSaya> createState() => _StatusPanelAbsensiSaya();
}

class _StatusPanelAbsensiSaya extends ConsumerState<PanelAbsensiSaya> {
  BarisAbsensiLokal? _terbuka;
  bool _dimuat = false;
  bool _sibuk = false;
  String? _pesan;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    final kasir = ref.read(penyediaSesi).kasir;
    final terbuka = kasir == null ? null : await ref.read(penyediaLayananAbsensi).AmbilTerbuka(kasir);
    if (mounted) {
      setState(() {
        _terbuka = terbuka;
        _dimuat = true;
      });
    }
  }

  Future<void> _Absen() async {
    final kasir = ref.read(penyediaSesi).kasir;
    if (kasir == null || _sibuk) {
      return;
    }
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    try {
      final layanan = ref.read(penyediaLayananAbsensi);
      final hasil = await layanan.Catat(kasir, await layanan.AmbilSwafoto());
      final jam = FormatWaktu.FormatJam(hasil.waktu);
      if (mounted) {
        setState(
          () => _pesan = '${hasil.nama} absen ${hasil.jenis == JenisAbsensi.Masuk ? 'masuk' : 'pulang'} pukul $jam.',
        );
      }
      await _Muat();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _pesan = galat.pesan);
      }
    } on Object {
      if (mounted) {
        setState(() => _pesan = 'Absen belum tercatat. Coba lagi.');
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final terbuka = _terbuka;
    final pesan = _pesan;

    if (!_dimuat) {
      return const SizedBox(height: 40);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          terbuka == null
              ? 'Anda belum absen masuk.'
              : 'Tercatat bekerja sejak ${FormatWaktu.FormatJam(terbuka.MasukPada)}.',
          key: const ValueKey('StatusAbsensiSaya'),
          style: teks.bodyMedium,
        ),
        const SizedBox(height: TokenJarak.jarak8),
        FilledButton.icon(
          key: const ValueKey('TombolAbsensiSaya'),
          onPressed: _sibuk ? null : _Absen,
          icon: const Icon(Icons.badge_outlined),
          label: Text(_sibuk ? 'Menyimpan…' : (terbuka == null ? 'Absen masuk' : 'Absen pulang')),
        ),
        if (pesan != null) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            pesan,
            key: const ValueKey('PesanAbsensiSaya'),
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
        ],
      ],
    );
  }
}

/// Sebelum kunci layar atau ganti kasir: bila staf aktif masih tercatat bekerja dan belum menolak tawaran hari ini,
/// tawarkan absen pulang. Langkah opsional yang tidak pernah menahan penguncian: "Nanti" menunda tawaran sampai besok,
/// dan galat apa pun (izin kamera, basis data) hanya menampilkan pesan. Penguncian otomatis karena diam tidak memanggil ini.
Future<void> TawarkanAbsenPulang(BuildContext context, WidgetRef ref) async {
  try {
    final kasir = ref.read(penyediaSesi).kasir;
    if (kasir == null) {
      return;
    }
    final sekarang = ref.read(penyediaJam)();
    if (ref.read(penyediaTundaAbsenPulang.notifier).CekDitunda(kasir.uuid, sekarang)) {
      return;
    }
    final layanan = ref.read(penyediaLayananAbsensi);
    final terbuka = await layanan.AmbilTerbuka(kasir);
    if (terbuka == null || !context.mounted) {
      return;
    }
    final absen = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: const Text('Absen pulang sekarang?'),
        content: Text('${kasir.nama} masih tercatat bekerja sejak ${FormatWaktu.FormatJam(terbuka.MasukPada)}.'),
        actions: [
          TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Nanti')),
          FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Absen pulang')),
        ],
      ),
    );
    if (absen != true) {
      ref.read(penyediaTundaAbsenPulang.notifier).Tunda(kasir.uuid, sekarang);
      return;
    }
    final hasil = await layanan.Catat(kasir, await layanan.AmbilSwafoto());
    // Dialog, bukan notifikasi melayang: begitu dialog ditutup layar langsung terkunci, dan notifikasi di Scaffold yang
    // sudah tergantikan tidak sempat terbaca.
    if (context.mounted) {
      await UmpanAksi.BerhasilPenting(
        context,
        judul: 'Absen pulang tercatat',
        pesan: '${hasil.nama} absen pulang pukul ${FormatWaktu.FormatJam(hasil.waktu)}. Terima kasih.',
      );
    }
  } on Object catch (galat) {
    if (context.mounted) {
      await UmpanAksi.Gagal(
        context,
        judul: 'Absen pulang belum tercatat',
        pesan: galat is GalatKasir
            ? galat.pesan
            : 'Absen pulang belum tercatat. Coba lagi lewat menu Pengaturan › Absensi.',
      );
    }
  }
}
