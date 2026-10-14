import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';

/// Status perangkat POS per outlet (OWN-08): terakhir aktif, data belum terkirim, versi aplikasi. Perangkat yang tidak
/// menghubungi server lebih dari 30 menit ditandai.
class LayarPerangkat extends ConsumerWidget {
  const LayarPerangkat({super.key});

  static const Duration batasDiam = Duration(minutes: 30);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    final sekarang = ref.read(penyediaJam)();
    return RefreshIndicator(
      onRefresh: () => ref.refresh(penyediaPerangkat.future),
      child: KeadaanData(
        nilai: ref.watch(penyediaPerangkat),
        saatCobaLagi: () => ref.invalidate(penyediaPerangkat),
        isi: (daftar) => ListView(
          padding: const EdgeInsets.all(TokenJarak.jarak16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            if (daftar.isEmpty)
              const KotakPanel(
                anak: KeadaanKosong(
                  ilustrasi: IlustrasiKosong.Outlet,
                  ikon: Icons.point_of_sale_outlined,
                  judul: 'Belum ada perangkat POS.',
                  keterangan: 'Perangkat kasir yang sudah diaktifkan muncul di sini beserta status sambungannya.',
                ),
              )
            else
              KotakPanel(
                rapat: true,
                anak: Column(
                  children: [
                    for (final (i, p) in daftar.indexed) ...[
                      if (i > 0) Divider(height: 1, color: warna.garis),
                      Builder(
                        builder: (context) {
                          final diam =
                              p.status == 'Aktif' &&
                              (p.terakhirAktifPada == null || sekarang.difference(p.terakhirAktifPada!) > batasDiam);
                          return ListTile(
                            leading: CircleAvatar(
                              backgroundColor: diam ? warna.peringatan.withValues(alpha: 0.12) : warna.latar,
                              child: Icon(
                                diam ? Icons.cloud_off_outlined : Icons.point_of_sale_outlined,
                                color: diam ? warna.peringatan : warna.teksSekunder,
                              ),
                            ),
                            title: Text('${p.nama} (${p.kode})'),
                            subtitle: Text(
                              [
                                '${p.jenis} | ${p.outlet}',
                                if (p.status != 'Aktif') p.status,
                                if (p.terakhirAktifPada != null)
                                  'aktif ${FormatTampilan.TanggalJam(p.terakhirAktifPada!)}${diam ? ' (lama tidak tersambung)' : ''}',
                                if (p.outboxTertunda > 0) '${p.outboxTertunda} data belum terkirim',
                                if (p.versiAplikasi != null) 'versi ${p.versiAplikasi}',
                              ].join(' | '),
                            ),
                            trailing: p.status == 'Dicabut'
                                ? null
                                : IconButton(
                                    tooltip: 'Cabut perangkat',
                                    icon: const Icon(Icons.link_off_outlined),
                                    onPressed: () => _Cabut(context, ref, p),
                                  ),
                          );
                        },
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// Cabut dengan konfirmasi: perangkat berhenti bisa sinkron dan harus diaktifkan ulang dengan kode baru.
  Future<void> _Cabut(BuildContext context, WidgetRef ref, PerangkatPemilik p) async {
    final yakin = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: Text('Cabut "${p.nama}"?'),
        content: const Text(
          'Perangkat ini tidak bisa lagi masuk atau sinkron. Untuk memakainya lagi, aktifkan ulang dengan kode baru.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Ya, cabut')),
        ],
      ),
    );
    if (yakin != true || !context.mounted) {
      return;
    }
    final pesan = ScaffoldMessenger.maybeOf(context);
    try {
      await ref.read(penyediaKlien).CabutPerangkat(p.uuid);
      pesan?.showSnackBar(SnackBar(content: Text('"${p.nama}" dicabut.')));
    } on GalatApi catch (galat) {
      pesan?.showSnackBar(SnackBar(content: Text(galat.pesan)));
    } on GalatJaringan {
      pesan?.showSnackBar(const SnackBar(content: Text('Tidak tersambung ke server. Coba lagi.')));
    } finally {
      ref.invalidate(penyediaPerangkat);
    }
  }
}
