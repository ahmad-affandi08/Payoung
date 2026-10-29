import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'Komponen/KomponenModern.dart';

/// P-10 PGL-18: Aplikasi Owner di bawah versi minimal. Seluruh isi aplikasi diganti layar ini sampai aplikasi
/// diperbarui; tidak ada transaksi tertunda di Owner (semua data dibaca langsung dari server), jadi tidak ada yang
/// tertinggal. "Cek lagi" membaca ulang konfigurasi bila pengguna sudah memperbarui lewat toko aplikasi.
class LayarWajibPerbarui extends ConsumerWidget {
  const LayarWajibPerbarui({required this.konfigurasi, super.key});

  final KonfigurasiAplikasi konfigurasi;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tautan = konfigurasi.tautanUnduh;
    final catatan = konfigurasi.catatanRilis?.trim() ?? '';

    return Scaffold(
      appBar: AppBar(title: const Text('Perbarui aplikasi')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(TokenJarak.jarak24),
          children: [
            Icon(Icons.system_update_outlined, size: 48, color: warna.brand),
            const SizedBox(height: TokenJarak.jarak16),
            Text(
              'Versi ${konfigurasi.versiSaatIni ?? 'ini'} sudah tidak didukung. Perbarui ke versi '
              '${konfigurasi.versiMinimal ?? konfigurasi.versiTerbaru ?? 'terbaru'} untuk membuka laporan lagi.',
              style: teks.titleMedium,
            ),
            const SizedBox(height: TokenJarak.jarak12),
            if (tautan != null)
              KartuModern(
                anak: Row(
                  children: [
                    Expanded(child: SelectableText(tautan, style: teks.bodyMedium)),
                    TextButton(
                      onPressed: () => Clipboard.setData(ClipboardData(text: tautan)),
                      child: const Text('Salin tautan'),
                    ),
                  ],
                ),
              )
            else
              Text(
                'Perbarui lewat toko aplikasi perangkat ini.',
                style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
              ),
            if (catatan.isNotEmpty) ...[
              const SizedBox(height: TokenJarak.jarak24),
              Text('Yang baru', style: teks.titleMedium),
              const SizedBox(height: TokenJarak.jarak8),
              Text(catatan, style: teks.bodyMedium),
            ],
            const SizedBox(height: TokenJarak.jarak24),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton(
                onPressed: () => ref.invalidate(penyediaKonfigurasiAplikasi),
                child: const Text('Cek lagi'),
              ),
            ),
            const SizedBox(height: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: TextButton(onPressed: () => ref.read(penyediaSesi.notifier).Keluar(), child: const Text('Keluar')),
            ),
          ],
        ),
      ),
    );
  }
}
