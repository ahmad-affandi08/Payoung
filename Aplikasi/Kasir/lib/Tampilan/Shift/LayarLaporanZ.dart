import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../Struk/BagianCetakDokumen.dart';
import 'KartuLaporanShift.dart';

/// Laporan Z (Rincian F-11): tampil setelah shift ditutup, sebelum layar buka shift berikutnya. Bertahan bila aplikasi
/// dimulai ulang sampai kasir menekan "Selesai". Laporan dicetak otomatis sekali bila cetak otomatis aktif (v1.84).
class LayarLaporanZ extends ConsumerWidget {
  const LayarLaporanZ({super.key, required this.uuidShift});

  final String uuidShift;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final laporan = ref.watch(penyediaLaporanShift(uuidShift));
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Laporan tutup shift (Z)'), automaticallyImplyLeading: false),
      body: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 640),
          child: ListView(
            padding: const EdgeInsets.all(TokenJarak.jarak16),
            children: [
              laporan.when(
                loading: () => const LinearProgressIndicator(),
                error: (galat, _) => Text('Laporan tidak bisa dibaca: $galat', style: TextStyle(color: warna.bahaya)),
                data: (l) => Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    KartuLaporanShift(laporan: l),
                    const SizedBox(height: TokenJarak.jarak12),
                    // Cetak struk bagian 3b: laporan Z dicetak otomatis sekali bila cetak otomatis aktif.
                    BagianCetakDokumen(
                      kunci: 'LaporanZ:$uuidShift',
                      namaDokumen: 'laporan Z',
                      cetak: (layanan, _, _) => layanan.CetakLaporanShift(l),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: TokenJarak.jarak8),
              Text(
                'Data tutup shift terkirim otomatis ke back-office saat online.',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(color: warna.teksSekunder),
              ),
            ],
          ),
        ),
      ),
      // Tombol utama menempel di bawah agar selalu terjangkau di layar sempit.
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(TokenJarak.jarak16),
          child: SizedBox(
            height: 56,
            child: FilledButton(
              onPressed: () => ref.read(penyediaLayananTutupShift).SelesaikanLaporanZ(),
              child: const Text('Selesai'),
            ),
          ),
        ),
      ),
    );
  }
}
