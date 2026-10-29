import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'BilahSaringan.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';
import 'Komponen/KomponenModern.dart';

/// Shift kasir pada tanggal terpilih: siapa, kapan, dan selisih kas saat tutup shift (OWN-03/05).
class LayarShift extends ConsumerWidget {
  const LayarShift({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final shift = ref.watch(penyediaShift);
    final outlet = ref.watch(penyediaDasbor).value?.outlet ?? const [];
    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      children: [
        BilahSaringan(outlet: outlet),
        const SizedBox(height: TokenJarak.jarak12),
        KeadaanData(
          nilai: shift,
          saatCobaLagi: () => ref.invalidate(penyediaShift),
          isi: (daftar) => daftar.isEmpty
              ? const KartuModern(
                  anak: KeadaanKosong(
                    ilustrasi: IlustrasiKosong.Penjualan,
                    ikon: Icons.schedule_outlined,
                    judul: 'Belum ada shift pada tanggal ini.',
                    keterangan: 'Shift kasir, jam buka dan tutup, serta selisih kas muncul di sini.',
                  ),
                )
              : KartuModern(
                  padding: EdgeInsets.zero,
                  anak: Column(
                    children: [
                      for (final (i, s) in daftar.indexed) ...[
                        if (i > 0) Divider(height: 1, color: warna.garis),
                        ListTile(
                          leading: CircleAvatar(
                            backgroundColor: warna.latar,
                            child: Icon(
                              s.ditutupPada == null ? Icons.lock_open_outlined : Icons.lock_outline,
                              color: s.ditutupPada == null ? warna.sukses : warna.teksSekunder,
                            ),
                          ),
                          title: Text('${s.kasir} | ${s.outlet}'),
                          subtitle: Text(
                            [
                              if (s.dibukaPada != null) 'Buka ${FormatTampilan.TanggalJam(s.dibukaPada!)}',
                              if (s.ditutupPada != null) 'tutup ${FormatTampilan.TanggalJam(s.ditutupPada!)}',
                              if (s.ditutupPada == null) 'masih berjalan',
                            ].join(', '),
                          ),
                          trailing: s.selisih == null
                              ? LencanaTeks(teks: s.status, label: 'Status shift ${s.status}')
                              : Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text('Selisih', style: teks.bodySmall),
                                    Text(
                                      FormatTampilan.Rupiah(s.selisih!),
                                      style: teks.titleSmall?.copyWith(
                                        color: (double.tryParse(s.selisih!) ?? 0) == 0 ? warna.sukses : warna.bahaya,
                                      ),
                                    ),
                                  ],
                                ),
                        ),
                      ],
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}
