import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';

/// Persetujuan jarak jauh (OWN-03, X4): antrean permintaan dari kasir yang boleh diputuskan pengguna (outlet akses &
/// izin sama dengan back-office), terlama dulu. Setujui dengan konfirmasi; tolak wajib alasan 5–255 karakter yang
/// dibaca kasir. Permintaan berlaku 10 menit; yang sudah diputuskan orang lain atau kedaluwarsa hilang saat dimuat ulang.
class LayarPersetujuan extends ConsumerWidget {
  const LayarPersetujuan({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return RefreshIndicator(
      onRefresh: () {
        ref.invalidate(penyediaDokumenPersetujuan);
        return ref.refresh(penyediaPersetujuan.future);
      },
      child: KeadaanData(
        nilai: ref.watch(penyediaPersetujuan),
        saatCobaLagi: () => ref.invalidate(penyediaPersetujuan),
        isi: (daftar) => ListView(
          padding: const EdgeInsets.all(TokenJarak.jarak16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            if (daftar.isEmpty && (ref.watch(penyediaDokumenPersetujuan).value ?? const <DokumenMenunggu>[]).isEmpty)
              const KotakPanel(
                anak: KeadaanKosong(
                  ilustrasi: IlustrasiKosong.Umum,
                  ikon: Icons.approval_outlined,
                  judul: 'Tidak ada permintaan persetujuan.',
                  keterangan:
                      'Permintaan dari kasir muncul di sini dan dicek otomatis tiap 15 detik saat aplikasi terbuka.',
                ),
              ),
            for (final p in daftar) _KartuPersetujuan(permintaan: p),
            for (final d in ref.watch(penyediaDokumenPersetujuan).value ?? const <DokumenMenunggu>[])
              _KartuDokumen(dokumen: d),
          ],
        ),
      ),
    );
  }
}

/// OWN-04: PO atau penyesuaian stok yang menunggu persetujuan. Setujui dengan konfirmasi, tolak wajib alasan; aturan
/// pemisahan tugas dijaga server (pembuat/pengaju tidak bisa menyetujui, tombol dimatikan dengan penjelasan).
class _KartuDokumen extends ConsumerStatefulWidget {
  const _KartuDokumen({required this.dokumen});

  final DokumenMenunggu dokumen;

  @override
  ConsumerState<_KartuDokumen> createState() => _KartuDokumenState();
}

class _KartuDokumenState extends ConsumerState<_KartuDokumen> {
  bool _sibuk = false;

  Future<void> _Putuskan(bool setujui) async {
    final d = widget.dokumen;
    String? alasan;
    if (setujui) {
      final yakin = await showDialog<bool>(
        context: context,
        builder: (konteks) => AlertDialog(
          title: Text('Setujui ${d.judul}?'),
          content: Text(
            d.jenis == 'PenyesuaianStok'
                ? '${FormatTampilan.Rupiah(d.nilai)}. Stok langsung berubah dan jurnal dicatat.'
                : '${FormatTampilan.Rupiah(d.nilai)}. PO bisa langsung dikirim ke pemasok.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Batal')),
            FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Ya, setujui')),
          ],
        ),
      );
      if (yakin != true) {
        return;
      }
    } else {
      alasan = await showDialog<String>(
        context: context,
        builder: (_) => const _DialogAlasanTolak(label: 'Alasan (dibaca pembuat dokumen)'),
      );
      if (alasan == null) {
        return;
      }
    }
    if (!mounted) {
      return;
    }
    setState(() => _sibuk = true);
    final pesan = ScaffoldMessenger.maybeOf(context);
    try {
      await ref.read(penyediaKlien).PutuskanDokumen(d, setujui: setujui, alasan: alasan);
      pesan?.showSnackBar(SnackBar(content: Text(setujui ? '${d.judul} disetujui.' : '${d.judul} ditolak.')));
    } on GalatApi catch (galat) {
      pesan?.showSnackBar(SnackBar(content: Text(galat.pesan)));
    } on GalatJaringan {
      pesan?.showSnackBar(const SnackBar(content: Text('Tidak tersambung ke server. Coba lagi.')));
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
      ref.invalidate(penyediaDokumenPersetujuan);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final d = widget.dokumen;
    return Container(
      margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
      decoration: BoxDecoration(
        color: warna.permukaan,
        border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
        borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
      ),
      child: Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(
                  backgroundColor: warna.peringatan.withValues(alpha: 0.12),
                  child: Icon(
                    d.jenis == 'PenyesuaianStok' ? Icons.inventory_2_outlined : Icons.local_shipping_outlined,
                    color: warna.peringatan,
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(child: Text(d.judul, style: teks.titleMedium)),
                Text(FormatTampilan.Rupiah(d.nilai), style: teks.titleMedium),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak4),
            Text(
              [
                if (d.nomor != null) d.nomor!,
                if (d.diajukanOleh != null) 'diajukan ${d.diajukanOleh}',
                if (d.diajukanPada != null) FormatTampilan.TanggalJam(d.diajukanPada!),
              ].join(' | '),
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
            const SizedBox(height: TokenJarak.jarak8),
            for (final r in d.rincian) Text(r, style: teks.bodyMedium),
            if (!d.bisaDisetujui)
              Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                child: Text(
                  'Dokumen ini Anda buat sendiri, jadi harus disetujui orang lain.',
                  style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                ),
              ),
            const SizedBox(height: TokenJarak.jarak12),
            Row(
              children: [
                Expanded(
                  child: SizedBox(
                    height: TokenJarak.targetSentuh,
                    child: OutlinedButton(
                      onPressed: _sibuk || !d.bisaDisetujui ? null : () => unawaited(_Putuskan(false)),
                      child: const Text('Tolak'),
                    ),
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(
                  child: SizedBox(
                    height: TokenJarak.targetSentuh,
                    child: FilledButton(
                      onPressed: _sibuk || !d.bisaDisetujui ? null : () => unawaited(_Putuskan(true)),
                      child: Text(_sibuk ? 'Mengirim…' : 'Setujui'),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _KartuPersetujuan extends ConsumerStatefulWidget {
  const _KartuPersetujuan({required this.permintaan});

  final PermintaanPersetujuanPos permintaan;

  @override
  ConsumerState<_KartuPersetujuan> createState() => _KartuPersetujuanState();
}

class _KartuPersetujuanState extends ConsumerState<_KartuPersetujuan> {
  bool _sibuk = false;

  Future<void> _Putuskan(bool setujui) async {
    final p = widget.permintaan;
    String? alasan;
    if (setujui) {
      final yakin = await showDialog<bool>(
        context: context,
        builder: (konteks) => AlertDialog(
          title: Text('Setujui "${p.judul}"?'),
          content: Text(
            [
              if (p.nilai != null) FormatTampilan.Rupiah(p.nilai!),
              'Diminta ${p.namaPemohon} di ${p.namaOutlet}.',
            ].join('\n'),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Batal')),
            FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Ya, setujui')),
          ],
        ),
      );
      if (yakin != true) {
        return;
      }
    } else {
      alasan = await showDialog<String>(context: context, builder: (_) => const _DialogAlasanTolak());
      if (alasan == null) {
        return;
      }
    }
    if (!mounted) {
      return;
    }
    setState(() => _sibuk = true);
    final pesan = ScaffoldMessenger.maybeOf(context);
    final klien = ref.read(penyediaKlien);
    try {
      if (setujui) {
        await klien.SetujuiPersetujuan(p.uuid);
      } else {
        await klien.TolakPersetujuan(p.uuid, alasan!);
      }
      pesan?.showSnackBar(
        SnackBar(content: Text(setujui ? '"${p.judul}" disetujui. Kasir bisa melanjutkan.' : '"${p.judul}" ditolak.')),
      );
    } on GalatApi catch (galat) {
      pesan?.showSnackBar(SnackBar(content: Text(galat.pesan)));
    } on GalatJaringan {
      pesan?.showSnackBar(const SnackBar(content: Text('Tidak tersambung ke server. Coba lagi.')));
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
      ref.invalidate(penyediaPersetujuan);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = widget.permintaan;
    return Container(
      margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
      decoration: BoxDecoration(
        color: warna.permukaan,
        border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
        borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
      ),
      child: Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(
                  backgroundColor: warna.peringatan.withValues(alpha: 0.12),
                  child: Icon(Icons.approval_outlined, color: warna.peringatan),
                ),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(child: Text(p.judul, style: teks.titleMedium)),
                if (p.nilai != null) Text(FormatTampilan.Rupiah(p.nilai!), style: teks.titleMedium),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak4),
            Text(
              [
                p.namaOutlet,
                p.namaPerangkat,
                p.namaPemohon,
                if (p.dibuatPada != null) FormatTampilan.TanggalJam(p.dibuatPada!.toLocal()),
              ].where((t) => t.isNotEmpty).join(' | '),
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
            if (p.kedaluwarsaPada != null)
              Text(
                'Berlaku sampai ${FormatTampilan.TanggalJam(p.kedaluwarsaPada!.toLocal())}',
                style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
              ),
            const SizedBox(height: TokenJarak.jarak8),
            if (p.rincian.isNotEmpty)
              DecoratedBox(
                decoration: BoxDecoration(
                  color: warna.latar,
                  borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(TokenJarak.jarak12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final r in p.rincian)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 2),
                          child: Text.rich(
                            TextSpan(
                              children: [
                                TextSpan(
                                  text: '${r.label}: ',
                                  style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                                ),
                                TextSpan(text: r.nilai, style: teks.bodyMedium),
                              ],
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: TokenJarak.jarak12),
            Row(
              children: [
                Expanded(
                  child: SizedBox(
                    height: TokenJarak.targetSentuh,
                    child: OutlinedButton(
                      onPressed: _sibuk ? null : () => unawaited(_Putuskan(false)),
                      child: const Text('Tolak'),
                    ),
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(
                  child: SizedBox(
                    height: TokenJarak.targetSentuh,
                    child: FilledButton(
                      onPressed: _sibuk ? null : () => unawaited(_Putuskan(true)),
                      child: Text(_sibuk ? 'Mengirim…' : 'Setujui'),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _DialogAlasanTolak extends StatefulWidget {
  const _DialogAlasanTolak({this.label = 'Alasan (dibaca kasir)'});

  final String label;

  @override
  State<_DialogAlasanTolak> createState() => _DialogAlasanTolakState();
}

class _DialogAlasanTolakState extends State<_DialogAlasanTolak> {
  final _alasan = TextEditingController();
  String? _galat;

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  void _Kirim() {
    final teks = _alasan.text.trim();
    if (teks.length < 5) {
      setState(() => _galat = 'Tulis alasan minimal 5 huruf agar kasir tahu.');
      return;
    }
    Navigator.of(context).pop(teks);
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Alasan menolak'),
    content: TextField(
      key: const ValueKey('AlasanTolak'),
      controller: _alasan,
      autofocus: true,
      maxLength: 255,
      maxLines: 3,
      minLines: 1,
      decoration: InputDecoration(labelText: widget.label, errorText: _galat, border: const OutlineInputBorder()),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(onPressed: _Kirim, child: const Text('Tolak')),
    ],
  );
}
