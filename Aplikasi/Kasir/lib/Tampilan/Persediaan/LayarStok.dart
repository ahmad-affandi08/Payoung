import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../../Data/RepositoriPersediaan.dart';
import '../../Domain/Persediaan/LayananBahanTerbuang.dart';
import '../../Domain/Persediaan/LayananGudang.dart';
import '../Komponen/FormatWaktu.dart';
import '../LayarRiwayat.dart';
import '../RuangKerja/IsiAreaKerja.dart';

/// Layar Stok di ruang kerja: pencatatan persediaan dari perangkat. **Gudang** (POS-25, online): terima barang dari
/// PO, terima transfer masuk, hitung stok opname — tiap tombol tampil hanya bila staf berizin ([jenisGudang]) dan
/// membuka panel tugas lewat [saatGudang]. **Bahan terbuang** (F-05f bagian 2, offline): catatan hari ini dengan status
/// kirim berteks dan tombol "Catat bahan terbuang" ([saatCatatTerbuang]).
class LayarStok extends ConsumerWidget {
  const LayarStok({
    super.key,
    this.saatCatatTerbuang,
    this.jenisGudang = const [],
    this.saatGudang,
    this.tampilBahanTerbuang = true,
  });

  /// Buka lembar catat bahan terbuang (null = kasir tidak punya izin).
  final VoidCallback? saatCatatTerbuang;

  /// Pekerjaan gudang yang boleh dibuka staf ini (kosong = bagian Gudang tidak tampil).
  final List<JenisGudang> jenisGudang;
  final ValueChanged<JenisGudang>? saatGudang;

  /// D-183: bagian bahan terbuang hanya untuk usaha yang membuang barang (F&B, toko segar, apotek, pet shop, florist).
  final bool tampilBahanTerbuang;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final terbuang = ref.watch(penyediaBahanTerbuangHariIni);
    final daftar = terbuang.value ?? const <RiwayatBahanTerbuang>[];

    return IsiAreaKerja(
      judul: 'Stok',
      anak: [
        if (jenisGudang.isNotEmpty && saatGudang != null) ...[
          Text('Gudang', style: teks.titleSmall),
          const SizedBox(height: TokenJarak.jarak4),
          Wrap(
            spacing: TokenJarak.jarak12,
            runSpacing: TokenJarak.jarak8,
            children: [
              for (final j in jenisGudang)
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: OutlinedButton.icon(
                    onPressed: () => saatGudang!(j),
                    icon: Icon(switch (j) {
                      JenisGudang.Penerimaan => Icons.local_shipping_outlined,
                      JenisGudang.Transfer => Icons.swap_horiz,
                      JenisGudang.Opname => Icons.fact_check_outlined,
                    }),
                    label: Text(j.judul),
                  ),
                ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            'Perlu online. Pindai barcode dengan pemindai atau ketik SKU; isian tersimpan di perangkat sampai dikirim.',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak24),
        ],
        if (tampilBahanTerbuang) ...[
          Wrap(
            spacing: TokenJarak.jarak12,
            runSpacing: TokenJarak.jarak8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Bahan terbuang hari ini', style: teks.titleMedium),
              if (saatCatatTerbuang != null)
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: FilledButton.icon(
                    onPressed: saatCatatTerbuang,
                    icon: const Icon(Icons.delete_sweep_outlined),
                    label: const Text('Catat bahan terbuang'),
                  ),
                ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            'Catat bahan atau menu yang basi, rusak, salah buat, atau tumpah. Stok berkurang dan nilainya masuk '
            'laporan food cost. Bisa dicatat saat offline; terkirim otomatis saat online.',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak12),
          if (terbuang.isLoading && terbuang.value == null) const LinearProgressIndicator(),
          if (terbuang.hasError)
            Text('Catatan bahan terbuang tidak bisa dimuat. Coba lagi.', style: TextStyle(color: warna.bahaya)),
          if (!terbuang.isLoading && !terbuang.hasError && daftar.isEmpty)
            const KeadaanKosong(
              ikon: Icons.delete_outline,
              ilustrasi: IlustrasiKosong.Stok,
              ringkas: true,
              judul: 'Belum ada bahan terbuang yang dicatat hari ini',
              keterangan: 'Catatan bahan terbuang di perangkat ini akan muncul di sini.',
            ),
          if (daftar.isNotEmpty)
            Material(
              color: warna.permukaan,
              shape: RoundedRectangleBorder(
                side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
              ),
              clipBehavior: Clip.antiAlias,
              child: Column(
                children: [
                  for (var i = 0; i < daftar.length; i++) ...[
                    if (i > 0) Divider(height: TokenJarak.tebalGaris, color: warna.garis),
                    _BarisBahanTerbuang(riwayat: daftar[i]),
                  ],
                ],
              ),
            ),
        ],
      ],
    );
  }
}

class _BarisBahanTerbuang extends StatelessWidget {
  const _BarisBahanTerbuang({required this.riwayat});

  final RiwayatBahanTerbuang riwayat;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final b = riwayat.baris;
    final status = LayarRiwayat.AmbilStatus(riwayat.status);
    final alasan = AlasanBahanTerbuang.Cari(b.Alasan)?.label ?? b.Alasan;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(b.NamaProduk, maxLines: 2, overflow: TextOverflow.ellipsis, style: teks.labelLarge),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Text('${b.Jumlah} ${b.NamaSatuan}', style: teks.labelLarge),
            ],
          ),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${FormatWaktu.FormatJam(b.DibuatPada)} | $alasan | ${b.NamaPengguna}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: teks.bodySmall,
                ),
              ),
              Icon(status.ikon, size: TokenJarak.ikonKecil, color: BilahStatus.AmbilWarnaNada(warna, status.nada)),
              const SizedBox(width: TokenJarak.jarak4),
              Text(status.teks, style: teks.bodySmall?.copyWith(color: warna.teksUtama)),
            ],
          ),
          if (b.Catatan != null) Text(b.Catatan!, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
          if (riwayat.status == StatusSinkronPenjualan.PerluTindakan && riwayat.pesanGalat != null)
            Text(riwayat.pesanGalat!, style: teks.bodySmall?.copyWith(color: warna.bahaya)),
        ],
      ),
    );
  }
}
