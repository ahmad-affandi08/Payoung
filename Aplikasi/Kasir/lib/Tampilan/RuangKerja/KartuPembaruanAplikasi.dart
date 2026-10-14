import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Pembaruan/PemasangPembaruan.dart';

/// POS-23 (D-154): pembaruan aplikasi kasir. Windows: tombol "Unduh dan pasang" mengunduh pemasang, memeriksa checksum
/// dari server, lalu memasangnya diam-diam (aplikasi menutup dan terbuka lagi sendiri). Android/iOS/server tanpa
/// checksum: tautan unduh atau toko aplikasi. Pemasangan hanya ditawarkan saat tidak ada transaksi tertunda kirim, agar
/// kasir tidak berpindah versi di tengah antrean (data tetap aman di perangkat, tetapi pemasangan bukan saat sibuk).
class KartuPembaruanAplikasi extends ConsumerStatefulWidget {
  const KartuPembaruanAplikasi({required this.konfigurasi, super.key});

  final KonfigurasiAplikasi konfigurasi;

  @override
  ConsumerState<KartuPembaruanAplikasi> createState() => _KartuPembaruanAplikasiState();
}

class _KartuPembaruanAplikasiState extends ConsumerState<KartuPembaruanAplikasi> {
  TahapPembaruan? _tahap;
  double? _bagian;
  String? _galat;

  bool get _sibuk => _tahap != null;

  Future<void> _Pasang(String tautan, String sha256) async {
    setState(() {
      _tahap = TahapPembaruan.Mengunduh;
      _bagian = null;
      _galat = null;
    });
    try {
      await ref
          .read(penyediaPemasangPembaruan)
          .UnduhDanPasang(
            tautan: tautan,
            sha256: sha256,
            saatKemajuan: (tahap, bagian) {
              if (mounted) {
                setState(() {
                  _tahap = tahap;
                  _bagian = bagian;
                });
              }
            },
          );
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _tahap = null;
          _galat = galat.pesan;
        });
      }
    }
  }

  String _LabelTahap() {
    final persen = _bagian == null ? '' : ' ${(_bagian! * 100).clamp(0, 100).round()}%';
    return switch (_tahap) {
      TahapPembaruan.Mengunduh => 'Mengunduh pembaruan…$persen',
      TahapPembaruan.Memeriksa => 'Memeriksa keaslian berkas…',
      TahapPembaruan.Memasang => 'Memasang. Aplikasi akan tertutup lalu terbuka lagi sendiri.',
      null => '',
    };
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final k = widget.konfigurasi;
    final tautan = k.tautanUnduh;
    final sha256 = k.sha256Unduhan;
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final pemasang = ref.watch(penyediaPemasangPembaruan);
    final bisaOtomatis = pemasang.bisaPasangSendiri && tautan != null && sha256 != null;
    return KotakPanel(
      anak: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.system_update_outlined, color: warna.brand),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: Text(
                  k.wajibPembaruan
                      ? 'Pasang versi ${k.versiMinimal ?? k.versiTerbaru ?? 'terbaru'} (wajib)'
                      : 'Versi ${k.versiTerbaru ?? 'baru'} tersedia',
                  style: teks.titleMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          if (bisaOtomatis) ...[
            if (tertunda > 0)
              Text(
                '$tertunda transaksi belum terkirim. Tunggu sampai terkirim (butuh internet), baru pasang pembaruan.',
                style: teks.bodyMedium?.copyWith(color: warna.bahaya),
              ),
            if (_sibuk) ...[
              const LinearProgressIndicator(),
              const SizedBox(height: TokenJarak.jarak4),
              Semantics(liveRegion: true, child: Text(_LabelTahap(), style: teks.bodyMedium)),
            ] else
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: FilledButton.icon(
                  onPressed: tertunda > 0 ? null : () => unawaited(_Pasang(tautan, sha256)),
                  icon: const Icon(Icons.download_outlined),
                  label: const Text('Unduh dan pasang'),
                ),
              ),
          ] else if (tautan != null)
            Row(
              children: [
                Expanded(child: SelectableText(tautan, style: teks.bodyMedium)),
                TextButton(
                  onPressed: () => Clipboard.setData(ClipboardData(text: tautan)),
                  child: const Text('Salin tautan'),
                ),
              ],
            )
          else
            Text(
              'Perbarui lewat toko aplikasi perangkat ini.',
              style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
            ),
          if (_galat != null) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Semantics(
              liveRegion: true,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.error_outline, size: TokenJarak.ikonKecil, color: warna.bahaya),
                  const SizedBox(width: TokenJarak.jarak4),
                  Expanded(child: Text(_galat!, style: teks.bodyMedium)),
                ],
              ),
            ),
          ],
          if (k.catatanRilis case final catatan? when catatan.trim().isNotEmpty) ...[
            const SizedBox(height: TokenJarak.jarak12),
            Text('Yang baru', style: teks.titleSmall),
            const SizedBox(height: TokenJarak.jarak4),
            Text(catatan, style: teks.bodyMedium),
          ],
        ],
      ),
    );
  }
}
