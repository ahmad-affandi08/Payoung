import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'FormatTampilan.dart';

/// P-10 PGL-19 (v3.47): pengumuman dari pengelola platform di atas Beranda — Penting, Pemeliharaan (dengan jadwal),
/// Yang baru, Info. Info & Yang baru bisa ditutup selama aplikasi berjalan; Penting & Pemeliharaan tetap tampil
/// sampai masa tampilnya habis di server. Pengumuman bukan data inti, jadi saat memuat atau gagal tidak tampil apa pun.
class DaftarPengumuman extends ConsumerStatefulWidget {
  const DaftarPengumuman({super.key});

  @override
  ConsumerState<DaftarPengumuman> createState() => _KeadaanDaftarPengumuman();
}

class _KeadaanDaftarPengumuman extends ConsumerState<DaftarPengumuman> {
  final Set<String> _ditutup = {};
  bool _pembaruanDitutup = false;

  static Color AmbilWarna(TokenWarna warna, JenisPengumuman jenis) => switch (jenis) {
    JenisPengumuman.Penting => warna.bahaya,
    JenisPengumuman.Pemeliharaan => warna.peringatan,
    JenisPengumuman.YangBaru => warna.sukses,
    JenisPengumuman.Info => warna.info,
  };

  static IconData AmbilIkon(JenisPengumuman jenis) => switch (jenis) {
    JenisPengumuman.Penting => Icons.priority_high,
    JenisPengumuman.Pemeliharaan => Icons.build_outlined,
    JenisPengumuman.YangBaru => Icons.auto_awesome_outlined,
    JenisPengumuman.Info => Icons.info_outline,
  };

  @override
  Widget build(BuildContext context) {
    final daftar = ref.watch(penyediaPengumuman).value ?? const <PengumumanAplikasi>[];
    final tampil = [
      for (final p in daftar)
        if (!(p.bolehDitutup && _ditutup.contains(p.uuid))) p,
    ];
    // PGL-18: versi baru tersedia (belum wajib) diingatkan sebagai kartu Info yang bisa ditutup selama aplikasi berjalan.
    final konfigurasi = ref.watch(penyediaKonfigurasiAplikasi).value;
    final adaPembaruan = konfigurasi != null && konfigurasi.adaPembaruan && !_pembaruanDitutup;
    if (tampil.isEmpty && !adaPembaruan) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [if (adaPembaruan) _KartuPembaruan(konfigurasi), for (final p in tampil) _Kartu(p)],
    );
  }

  Widget _KartuPembaruan(KonfigurasiAplikasi konfigurasi) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final tujuan = konfigurasi.versiTerbaru ?? 'terbaru';
    return Semantics(
      container: true,
      label: 'Pembaruan aplikasi: versi $tujuan tersedia',
      child: Container(
        margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
        decoration: BoxDecoration(
          color: warna.permukaan,
          border: Border(
            left: BorderSide(color: warna.info, width: 4),
            top: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
            right: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
          ),
        ),
        padding: const EdgeInsets.fromLTRB(
          TokenJarak.jarak12,
          TokenJarak.jarak8,
          TokenJarak.jarak4,
          TokenJarak.jarak12,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak4),
              child: Icon(Icons.system_update_outlined, color: warna.info, size: 20),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Pembaruan: versi $tujuan tersedia', style: teks.titleSmall),
                    const SizedBox(height: TokenJarak.jarak4),
                    Text(
                      konfigurasi.tautanUnduh == null
                          ? 'Perbarui lewat toko aplikasi perangkat ini.'
                          : 'Unduh di ${konfigurasi.tautanUnduh}',
                      style: teks.bodyMedium,
                    ),
                    if (konfigurasi.catatanRilis case final catatan? when catatan.trim().isNotEmpty) ...[
                      const SizedBox(height: TokenJarak.jarak4),
                      Text(catatan.trim(), style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                    ],
                  ],
                ),
              ),
            ),
            IconButton(
              tooltip: 'Tutup pembaruan',
              icon: const Icon(Icons.close),
              onPressed: () => setState(() => _pembaruanDitutup = true),
            ),
          ],
        ),
      ),
    );
  }

  Widget _Kartu(PengumumanAplikasi p) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final nada = AmbilWarna(warna, p.jenis);
    final mulai = p.pemeliharaanMulai;
    final selesai = p.pemeliharaanSelesai;
    return Semantics(
      container: true,
      label: 'Pengumuman ${p.labelJenis}: ${p.judul}',
      child: Container(
        margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
        decoration: BoxDecoration(
          color: warna.permukaan,
          border: Border(
            left: BorderSide(color: nada, width: 4),
            top: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
            right: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
          ),
        ),
        padding: const EdgeInsets.fromLTRB(
          TokenJarak.jarak12,
          TokenJarak.jarak8,
          TokenJarak.jarak4,
          TokenJarak.jarak12,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak4),
              child: Icon(AmbilIkon(p.jenis), color: nada, size: 20),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${p.labelJenis}: ${p.judul}', style: teks.titleSmall),
                    const SizedBox(height: TokenJarak.jarak4),
                    Text(p.isi, style: teks.bodyMedium),
                    if (mulai != null && selesai != null) ...[
                      const SizedBox(height: TokenJarak.jarak4),
                      Text(
                        'Jadwal: ${FormatTampilan.TanggalJam(mulai)} – ${FormatTampilan.TanggalJam(selesai)}',
                        style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ],
                    if (p.tautan != null) ...[
                      const SizedBox(height: TokenJarak.jarak4),
                      SelectableText(p.tautan!, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                    ],
                  ],
                ),
              ),
            ),
            if (p.bolehDitutup)
              IconButton(
                tooltip: 'Tutup pengumuman',
                icon: const Icon(Icons.close),
                onPressed: () => setState(() => _ditutup.add(p.uuid)),
              ),
          ],
        ),
      ),
    );
  }
}
