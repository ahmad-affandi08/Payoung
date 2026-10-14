import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/BasisData/BasisDataKasir.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Karyawan/LayananAbsensi.dart';
import '../Domain/Sesi/StafLokal.dart';
import 'Komponen/FormatWaktu.dart';

/// Absensi staf (F-18, EMP-03), dibuka dari layar pilih kasir sebelum masuk: pilih nama → PIN → swafoto kamera depan
/// (bila perangkat berkamera) → absen masuk/keluar tercatat (juga offline). Tidak membuka sesi kasir.
class LayarAbsensi extends ConsumerStatefulWidget {
  const LayarAbsensi({super.key});

  @override
  ConsumerState<LayarAbsensi> createState() => _LayarAbsensiState();
}

class _LayarAbsensiState extends ConsumerState<LayarAbsensi> {
  StafLokal? _dipilih;
  BarisAbsensiLokal? _terbuka;
  HasilAbsensi? _hasil;
  bool _sibuk = false;
  String? _galat;
  List<BarisAbsensiLokal> _terbaru = const [];

  @override
  void initState() {
    super.initState();
    unawaited(_MuatTerbaru());
  }

  Future<void> _MuatTerbaru() async {
    final daftar = await ref.read(penyediaRepositoriAbsensi).AmbilTerbaru(batas: 10);
    if (mounted) {
      setState(() => _terbaru = daftar);
    }
  }

  Future<void> _Pilih(StafLokal staf) async {
    final terbuka = await ref.read(penyediaLayananAbsensi).AmbilTerbuka(staf);
    if (mounted) {
      setState(() {
        _dipilih = staf;
        _terbuka = terbuka;
        _galat = null;
      });
    }
  }

  Future<void> _Absen(String pin) async {
    final staf = _dipilih;
    if (staf == null) {
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      await ref.read(penyediaLayananMasuk).Masuk(staf, pin);
      final layanan = ref.read(penyediaLayananAbsensi);
      final foto = await layanan.AmbilSwafoto();
      final hasil = await layanan.Catat(staf, foto);
      if (mounted) {
        setState(() => _hasil = hasil);
      }
      await _MuatTerbaru();
      await ref.read(penyediaSesi.notifier).Sinkronkan();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  void _Ulang() => setState(() {
    _dipilih = null;
    _terbuka = null;
    _hasil = null;
    _galat = null;
  });

  static String _Durasi(Duration d) =>
      d.inHours == 0 ? '${d.inMinutes} menit' : '${d.inHours} jam ${d.inMinutes % 60} menit';

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final staf = ref.watch(penyediaStaf);
    final hasil = _hasil;
    final dipilih = _dipilih;

    Widget isi;
    if (hasil != null) {
      final masuk = hasil.jenis == JenisAbsensi.Masuk;
      isi = Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.check_circle_outline, color: warna.sukses, size: TokenJarak.ikonBesar),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            '${hasil.nama} absen ${masuk ? 'masuk' : 'keluar'} pukul ${FormatWaktu.FormatJam(hasil.waktu)}.',
            style: teks.titleMedium,
            textAlign: TextAlign.center,
          ),
          if (!masuk && hasil.masukPada != null)
            Text(
              'Masuk ${FormatWaktu.FormatJam(hasil.masukPada!)} | bekerja ${_Durasi(hasil.waktu.difference(hasil.masukPada!))}',
              style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
            ),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            'Tersimpan di perangkat dan dikirim otomatis.',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: FilledButton(autofocus: true, onPressed: _Ulang, child: const Text('Selesai')),
          ),
        ],
      );
    } else if (dipilih != null) {
      isi = Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            _terbuka == null ? 'Absen masuk ${dipilih.nama}' : 'Absen keluar ${dipilih.nama}',
            style: teks.headlineSmall,
            textAlign: TextAlign.center,
          ),
          if (_terbuka != null)
            Text(
              'Masuk pukul ${FormatWaktu.FormatJam(_terbuka!.MasukPada)}',
              style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
            ),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            ref.read(penyediaKameraSwafoto).CekTersedia()
                ? 'Masukkan PIN, lalu ambil swafoto wajah.'
                : 'Masukkan PIN. Perangkat ini tanpa kamera, jadi tanpa swafoto.',
            style: teks.bodySmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: TokenJarak.jarak16),
          PapanPin(saatSelesai: _Absen, sibuk: _sibuk, pesanGalat: _galat),
          const SizedBox(height: TokenJarak.jarak8),
          TextButton(onPressed: _sibuk ? null : _Ulang, child: const Text('Ganti nama')),
        ],
      );
    } else {
      isi = staf.when(
        loading: () => const CircularProgressIndicator(),
        error: (galat, _) => Text('Data staf tidak bisa dimuat: $galat'),
        data: (daftar) => Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('Siapa yang absen?', style: teks.headlineSmall),
            const SizedBox(height: TokenJarak.jarak16),
            Wrap(
              spacing: TokenJarak.jarak12,
              runSpacing: TokenJarak.jarak12,
              alignment: WrapAlignment.center,
              children: [
                for (final s in daftar)
                  SizedBox(
                    width: 200,
                    height: 64,
                    child: OutlinedButton(
                      onPressed: () => _Pilih(s),
                      child: Text(s.nama, textAlign: TextAlign.center),
                    ),
                  ),
              ],
            ),
            if (_terbaru.isNotEmpty) ...[
              const SizedBox(height: TokenJarak.jarak24),
              Text('Absensi terakhir di perangkat ini', style: teks.titleSmall),
              const SizedBox(height: TokenJarak.jarak8),
              for (final a in _terbaru)
                Text(
                  '${a.NamaStaf} | masuk ${FormatWaktu.FormatJam(a.MasukPada)}'
                  '${a.KeluarPada == null ? ' | belum keluar' : ' | keluar ${FormatWaktu.FormatJam(a.KeluarPada!)}'}',
                  style: teks.bodySmall,
                ),
            ],
          ],
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Absensi')),
      body: Center(
        child: SingleChildScrollView(padding: const EdgeInsets.all(TokenJarak.jarak24), child: isi),
      ),
    );
  }
}
