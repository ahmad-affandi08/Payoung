import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../LayarStatusSinkron.dart';

/// Satu butir daftar periksa kesiapan perangkat: [nada] Sukses = beres, Peringatan = perlu dicek, Netral = belum
/// diketahui.
typedef ButirKesiapan = ({String judul, String keterangan, NadaStatus nada});

/// Server dianggap lambat bila pemeriksaan konfigurasi memakan lebih dari ini (milidetik).
const int batasResponsLambatMs = 2000;

/// Ringkasan kesiapan perangkat untuk panel kanan Pengaturan dan lencana kategori (D-301). Murni: semua masukan
/// diberikan pemanggil sehingga mudah diuji tanpa basis data.
List<ButirKesiapan> SusunKesiapanPerangkat({
  required StatusKoneksi koneksi,
  required int tertunda,
  required StatusPrinter printer,
  required int? selisihJamDetik,
  required DateTime? dataDiperbarui,
  required DateTime sekarang,
  int? responsServerMs,
}) {
  return [
    switch (koneksi) {
      StatusKoneksi.Online => (judul: 'Tersambung ke server', keterangan: 'Online', nada: NadaStatus.Sukses),
      StatusKoneksi.Offline => (
        judul: 'Tidak tersambung ke server',
        keterangan: 'Data aman di perangkat dan dikirim otomatis saat online.',
        nada: NadaStatus.Peringatan,
      ),
      StatusKoneksi.BelumDiketahui => (
        judul: 'Koneksi belum diperiksa',
        keterangan: 'Status muncul setelah perangkat mencoba menghubungi server.',
        nada: NadaStatus.Netral,
      ),
    },
    if (koneksi == StatusKoneksi.Online && responsServerMs != null)
      responsServerMs > batasResponsLambatMs
          ? (
              judul: 'Server lambat menjawab',
              keterangan:
                  'Menjawab dalam ${(responsServerMs / 1000).toStringAsFixed(1).replaceAll('.', ',')} detik. Cek sinyal atau Wi-Fi.',
              nada: NadaStatus.Peringatan,
            )
          : (
              judul: 'Server cepat menjawab',
              keterangan: 'Menjawab dalam $responsServerMs ms.',
              nada: NadaStatus.Sukses,
            ),
    tertunda == 0
        ? (judul: 'Semua data sudah terkirim', keterangan: 'Tidak ada transaksi tertunda.', nada: NadaStatus.Sukses)
        : (
            judul: '$tertunda data belum terkirim',
            keterangan: 'Dikirim otomatis saat online. Lihat menu Sinkron.',
            nada: NadaStatus.Peringatan,
          ),
    switch (printer.keadaan) {
      KeadaanPrinter.BelumDiatur => (
        judul: 'Printer struk belum diatur',
        keterangan: 'Atur di kategori Printer & struk.',
        nada: NadaStatus.Peringatan,
      ),
      KeadaanPrinter.Gagal => (
        judul: 'Printer struk bermasalah',
        keterangan: printer.pesan ?? 'Cetak terakhir gagal. Periksa sambungan printer.',
        nada: NadaStatus.Peringatan,
      ),
      KeadaanPrinter.Mencetak || KeadaanPrinter.Siap => (
        judul: 'Printer struk siap',
        keterangan: printer.profil?.nama ?? 'Printer kasir',
        nada: NadaStatus.Sukses,
      ),
    },
    if (selisihJamDetik != null && selisihJamDetik.abs() > LayarStatusSinkron.batasSelisihJamDetik)
      (
        judul: 'Jam perangkat tidak tepat',
        keterangan:
            'Jam ${selisihJamDetik > 0 ? 'lebih cepat' : 'lebih lambat'} ${(selisihJamDetik.abs() / 60).round()} menit dari server.',
        nada: NadaStatus.Peringatan,
      )
    else if (selisihJamDetik != null)
      (
        judul: 'Jam perangkat tepat',
        keterangan: 'Selisih ${selisihJamDetik.abs()} detik dari server.',
        nada: NadaStatus.Sukses,
      ),
    if (dataDiperbarui != null && sekarang.difference(dataDiperbarui) > LayarStatusSinkron.batasDataLama)
      (
        judul: 'Data kasir sudah lama',
        keterangan: 'Perbarui data kasir agar harga dan promo terbaru dipakai.',
        nada: NadaStatus.Peringatan,
      ),
  ];
}

/// Panel kanan Pengaturan (D-301): daftar periksa kesiapan perangkat dengan jalan pintas ke Bantuan.
class PanelKesiapanPerangkat extends ConsumerWidget {
  const PanelKesiapanPerangkat({super.key, required this.butir, required this.saatBantuan});

  final List<ButirKesiapan> butir;
  final VoidCallback saatBantuan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(header: true, child: Text('Ringkasan perangkat', style: teks.titleSmall)),
        const SizedBox(height: TokenJarak.jarak8),
        for (final b in butir)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  switch (b.nada) {
                    NadaStatus.Sukses => Icons.check_circle,
                    NadaStatus.Peringatan => Icons.error,
                    _ => Icons.radio_button_unchecked,
                  },
                  size: TokenJarak.ikonSedang,
                  color: switch (b.nada) {
                    NadaStatus.Sukses => warna.sukses,
                    NadaStatus.Peringatan => warna.peringatan,
                    _ => warna.teksSekunder,
                  },
                ),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(b.judul, style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700)),
                      Text(b.keterangan, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        const Divider(height: TokenJarak.jarak24),
        Text('Butuh bantuan?', style: teks.titleSmall),
        const SizedBox(height: TokenJarak.jarak8),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            key: const ValueKey('BukaBantuan'),
            onPressed: saatBantuan,
            icon: const Icon(Icons.help_outline),
            label: const Text('Hubungi tim Payoung'),
          ),
        ),
      ],
    );
  }
}
