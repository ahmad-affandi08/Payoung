import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'BilahSaringan.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';

/// Laporan ringkas OWN-05: penjualan per produk/kategori/kasir/jam/kanal pada tanggal & outlet terpilih, atau laba rugi
/// bulan berjalan (dibandingkan bulan sebelumnya), atau skor risiko kasir 7 hari terakhir.
class LayarLaporan extends ConsumerWidget {
  const LayarLaporan({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(penyediaModeLaporan);
    final outlet = ref.watch(penyediaDasbor).value?.outlet ?? const [];
    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      children: [
        BilahSaringan(outlet: outlet),
        const SizedBox(height: TokenJarak.jarak12),
        SegmentedButton<ModeLaporan>(
          segments: const [
            ButtonSegment(value: ModeLaporan.Penjualan, label: Text('Penjualan')),
            ButtonSegment(value: ModeLaporan.LabaRugi, label: Text('Laba rugi')),
            ButtonSegment(value: ModeLaporan.AntiFraud, label: Text('Anti-fraud')),
          ],
          selected: {mode},
          onSelectionChanged: (pilih) => ref.read(penyediaModeLaporan.notifier).Atur(pilih.first),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        switch (mode) {
          ModeLaporan.Penjualan => const _Penjualan(),
          ModeLaporan.LabaRugi => const _LabaRugi(),
          ModeLaporan.AntiFraud => const _AntiFraud(),
        },
      ],
    );
  }
}

/// Penjualan per kelompok (produk, kategori, kasir, jam, kanal).
class _Penjualan extends ConsumerWidget {
  const _Penjualan();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final laporan = ref.watch(penyediaLaporan);
    final kelompok = ref.watch(penyediaKelompokLaporan);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final (nilai, label) in PengaturKelompokLaporan.pilihan)
              ChoiceChip(
                label: Text(label),
                selected: kelompok == nilai,
                showCheckmark: false,
                onSelected: (_) => ref.read(penyediaKelompokLaporan.notifier).Atur(nilai),
              ),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak12),
        KeadaanData(
          nilai: laporan,
          saatCobaLagi: () => ref.invalidate(penyediaLaporan),
          isi: (l) => l.baris.isEmpty
              ? const KotakPanel(
                  anak: KeadaanKosong(
                    ilustrasi: IlustrasiKosong.Laporan,
                    ikon: Icons.bar_chart_outlined,
                    judul: 'Belum ada penjualan pada tanggal ini.',
                    keterangan: 'Ringkasan penjualan per produk, kategori, kasir, jam, dan kanal muncul di sini.',
                  ),
                )
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    KotakPanel(
                      rapat: true,
                      anak: Column(
                        children: [
                          for (final (i, b) in l.baris.indexed) ...[
                            if (i > 0) Divider(height: 1, color: warna.garis),
                            ListTile(
                              title: Text(kelompok == 'Jam' ? 'Jam ${b.nama}' : b.nama),
                              subtitle: b.jumlah == null ? null : Text('Jumlah ${b.jumlah}'),
                              trailing: Text(FormatTampilan.Rupiah(b.omzet), style: teks.titleSmall),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(height: TokenJarak.jarak12),
                    DecoratedBox(
                      decoration: BoxDecoration(
                        color: warna.brandGelap,
                        borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
                      ),
                      child: ListTile(
                        title: Text('Total', style: teks.titleSmall?.copyWith(color: warna.permukaan)),
                        trailing: Text(
                          FormatTampilan.Rupiah(l.totalOmzet),
                          style: teks.titleMedium?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ),
                  ],
                ),
        ),
      ],
    );
  }
}

/// Laba rugi sederhana (OWN-05): ringkasan lima angka dengan perubahan terhadap bulan sebelumnya, lalu rincian akun.
class _LabaRugi extends ConsumerWidget {
  const _LabaRugi();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return KeadaanData(
      nilai: ref.watch(penyediaLabaRugi),
      saatCobaLagi: () => ref.invalidate(penyediaLabaRugi),
      isi: (l) {
        final ringkasan = [
          ('Pendapatan', l.pendapatan),
          ('Harga pokok penjualan', l.hpp),
          ('Laba kotor', l.labaKotor),
          ('Beban', l.beban),
        ];
        final akun = l.baris.where((b) => b.jenis == 'Akun').toList();
        final perubahan = FormatTampilan.Perubahan(l.labaBersih.nilai, l.labaBersih.nilaiSebelumnya);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Periode ${l.dari} s.d. ${l.sampai}', style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
            const SizedBox(height: TokenJarak.jarak8),
            KotakPanel(
              rapat: true,
              anak: Column(
                children: [
                  for (final (i, (label, n)) in ringkasan.indexed) ...[
                    if (i > 0) Divider(height: 1, color: warna.garis),
                    ListTile(
                      title: Text(label),
                      subtitle: Text('Bulan sebelumnya ${FormatTampilan.Rupiah(n.nilaiSebelumnya)}'),
                      trailing: Text(FormatTampilan.Rupiah(n.nilai), style: teks.titleSmall),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: TokenJarak.jarak12),
            DecoratedBox(
              decoration: BoxDecoration(
                color: warna.brandGelap,
                borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
              ),
              child: ListTile(
                title: Text('Laba bersih', style: teks.titleSmall?.copyWith(color: warna.permukaan)),
                subtitle: perubahan == null
                    ? null
                    : Text('$perubahan dari bulan sebelumnya', style: teks.bodySmall?.copyWith(color: warna.permukaan)),
                trailing: Text(
                  FormatTampilan.Rupiah(l.labaBersih.nilai),
                  style: teks.titleMedium?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w700),
                ),
              ),
            ),
            if (akun.isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: TokenJarak.jarak12),
                child: KotakPanel(
                  anak: KeadaanKosong(
                    ilustrasi: IlustrasiKosong.Laporan,
                    ikon: Icons.balance_outlined,
                    judul: 'Belum ada pembukuan bulan ini.',
                    keterangan: 'Pendapatan, HPP, dan beban per akun muncul di sini setelah ada transaksi.',
                  ),
                ),
              )
            else ...[
              const SizedBox(height: TokenJarak.jarak12),
              KotakPanel(
                rapat: true,
                anak: Column(
                  children: [
                    for (final (i, b) in akun.indexed) ...[
                      if (i > 0) Divider(height: 1, color: warna.garis),
                      ListTile(
                        dense: true,
                        title: Text(b.label),
                        subtitle: Text('${b.kode ?? ''} | ${_Kelompok(b.kelompok)}'),
                        trailing: Text(FormatTampilan.Rupiah(b.nilai ?? '0'), style: teks.bodyMedium),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ],
        );
      },
    );
  }

  static String _Kelompok(String kunci) => switch (kunci) {
    'Pendapatan' => 'Pendapatan',
    'Hpp' => 'HPP',
    _ => 'Beban',
  };
}

/// Anti-fraud kasir (OWN-09): skor risiko per kasir selama 7 hari berakhir di tanggal terpilih, urut skor menurun.
/// Setiap skor membawa alasan berteks; skor adalah petunjuk untuk diperiksa, bukan bukti kesalahan.
class _AntiFraud extends ConsumerWidget {
  const _AntiFraud();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return KeadaanData(
      nilai: ref.watch(penyediaAntiFraud),
      saatCobaLagi: () => ref.invalidate(penyediaAntiFraud),
      isi: (daftar) => daftar.isEmpty
          ? const KotakPanel(
              anak: KeadaanKosong(
                ilustrasi: IlustrasiKosong.Laporan,
                ikon: Icons.shield_outlined,
                judul: 'Belum ada aktivitas kasir untuk dinilai.',
                keterangan: 'Skor risiko kasir 7 hari terakhir muncul di sini setelah ada transaksi.',
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  '7 hari terakhir. Skor adalah petunjuk untuk diperiksa, bukan bukti kesalahan.',
                  style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                ),
                const SizedBox(height: TokenJarak.jarak8),
                for (final k in daftar)
                  Padding(
                    padding: const EdgeInsets.only(bottom: TokenJarak.jarak12),
                    child: KotakPanel(
                      anak: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(child: Text(k.nama, style: teks.titleSmall)),
                              Text('Risiko ${k.tingkat.toLowerCase()} | skor ${k.skor}', style: teks.titleSmall),
                            ],
                          ),
                          Text(
                            '${k.jumlahTransaksi} transaksi | ${k.jumlahVoid} void',
                            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                          ),
                          if (k.alasan.isEmpty)
                            Text('Tidak ada pola yang mencurigakan.', style: teks.bodyMedium)
                          else
                            for (final a in k.alasan) Text('• $a', style: teks.bodyMedium),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
    );
  }
}
