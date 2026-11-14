import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Aplikasi/VersiAplikasi.dart';
import '../../Data/RepositoriKasir.dart';
import '../Komponen/FormatWaktu.dart';
import '../RuangKerja/BagianKerja.dart';

/// Pengaturan › Perangkat (D-301): identitas perangkat yang tercatat (nama, outlet, versi aplikasi, skema data lokal),
/// kasir yang sedang masuk (ganti kasir, kunci layar), dan kapan data kasir terakhir diperbarui. Semua nilai dibaca
/// dari data lokal sehingga tetap tampil saat offline dan berguna saat menghubungi tim dukungan.
class BagianPerangkat extends ConsumerStatefulWidget {
  const BagianPerangkat({super.key});

  @override
  ConsumerState<BagianPerangkat> createState() => _BagianPerangkatState();
}

class _BagianPerangkatState extends ConsumerState<BagianPerangkat> {
  bool _memperbarui = false;
  String? _pesanData;

  Future<void> _PerbaruiData() async {
    setState(() {
      _memperbarui = true;
      _pesanData = null;
    });
    await ref.read(penyediaSesi.notifier).SegarkanData();
    if (!mounted) {
      return;
    }
    final koneksi = ref.read(penyediaKoneksi);
    setState(() {
      _memperbarui = false;
      _pesanData = koneksi == StatusKoneksi.Offline
          ? 'Belum tersambung ke server. Data kasir terakhir tetap dipakai.'
          : 'Data kasir sudah diperbarui.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final identitas = ref.watch(penyediaIdentitas).value;
    final sesi = ref.watch(penyediaSesi);
    final pengaturan = ref.watch(penyediaPengaturanSinkron).value ?? const <String, String>{};
    final diperbarui = DateTime.tryParse(pengaturan[KunciPengaturan.dataAwalPada] ?? '')?.toLocal();
    final skema = ref.watch(penyediaBasisData).schemaVersion;
    final platform = ref.watch(penyediaPlatform);
    final keterangan = teks.bodySmall?.copyWith(color: warna.teksSekunder);

    Widget Baris(String nama, String nilai, {Key? kunci}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(nama, style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
          ),
          const SizedBox(width: TokenJarak.jarak12),
          Flexible(
            child: Text(
              nilai,
              key: kunci,
              textAlign: TextAlign.right,
              style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        BagianKerja(
          judul: 'Perangkat ini',
          ikon: Icons.tablet_android_outlined,
          anak: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Identitas perangkat yang tercatat di server. Berguna saat menghubungi tim dukungan.',
                style: keterangan,
              ),
              const SizedBox(height: TokenJarak.jarak8),
              Baris(
                'Kode perangkat',
                (identitas?.perangkat ?? '').isEmpty ? '-' : identitas!.perangkat,
                kunci: const ValueKey('KodePerangkat'),
              ),
              Baris('Outlet', (identitas?.outlet ?? '').isEmpty ? '-' : identitas!.outlet),
              Baris('Platform', platform),
              Baris('Versi aplikasi', VersiAplikasi.lengkap, kunci: const ValueKey('VersiAplikasi')),
              Baris('Skema data lokal', '$skema'),
            ],
          ),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        BagianKerja(
          judul: 'Kasir yang masuk',
          ikon: Icons.person_outline,
          anak: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Shift tetap terbuka saat kasir berganti atau layar dikunci.', style: keterangan),
              const SizedBox(height: TokenJarak.jarak8),
              Baris('Kasir', sesi.kasir?.nama ?? '-', kunci: const ValueKey('KasirMasuk')),
              Baris('Peran', sesi.kasir == null ? '-' : (sesi.kasir!.pemilik ? 'Pemilik' : 'Kasir')),
              const SizedBox(height: TokenJarak.jarak8),
              Wrap(
                spacing: TokenJarak.jarak8,
                runSpacing: TokenJarak.jarak8,
                children: [
                  OutlinedButton.icon(
                    key: const ValueKey('GantiKasir'),
                    onPressed: sesi.kasir == null
                        ? null
                        : () => ref.read(penyediaSesi.notifier).Kunci(gantiKasir: true),
                    icon: const Icon(Icons.swap_horiz),
                    label: const Text('Ganti kasir'),
                  ),
                  OutlinedButton.icon(
                    key: const ValueKey('KunciLayar'),
                    onPressed: sesi.kasir == null ? null : () => ref.read(penyediaSesi.notifier).Kunci(),
                    icon: const Icon(Icons.lock_outline),
                    label: const Text('Kunci layar'),
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        BagianKerja(
          judul: 'Data kasir',
          ikon: Icons.storage_outlined,
          anak: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Ambil daftar kasir, PIN offline, produk, dan kategori kas terbaru dari back-office.',
                style: keterangan,
              ),
              const SizedBox(height: TokenJarak.jarak8),
              Baris(
                'Terakhir diperbarui',
                diperbarui == null ? 'Belum pernah' : FormatWaktu.FormatTanggalJam(diperbarui),
                kunci: const ValueKey('DataDiperbarui'),
              ),
              const SizedBox(height: TokenJarak.jarak8),
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: OutlinedButton(
                  onPressed: _memperbarui ? null : _PerbaruiData,
                  child: Text(_memperbarui ? 'Memperbarui…' : 'Perbarui data kasir'),
                ),
              ),
              if (_pesanData != null)
                Padding(
                  padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                  child: Text(_pesanData!, style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
