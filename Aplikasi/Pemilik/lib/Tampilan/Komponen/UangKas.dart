import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/KlienPemilik.dart';
import '../FormatTampilan.dart';
import 'KomponenModern.dart';

/// Uang masuk dan uang keluar kas & bank (D-255): tiga angka (masuk, keluar, selisih) masing-masing dengan perubahan
/// dalam kata terhadap pembanding yang adil dari server. Dipakai kartu Beranda dan mode Uang di Laporan.
class RingkasanUangKas extends StatelessWidget {
  const RingkasanUangKas({super.key, required this.uang});

  final UangKasPemilik uang;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final negatif = uang.selisih.nilai.startsWith('-');
    return Column(
      children: [
        _BarisAngka(
          label: 'Uang masuk',
          angka: uang.masuk,
          jenis: uang.jenisPembanding,
          // Naik baik untuk uang masuk, jadi tidak ada warna peringatan. Teks "naik/turun" yang membawa maknanya.
          warnaNilai: warna.brandGelap,
        ),
        const SizedBox(height: TokenJarak.jarak12),
        _BarisAngka(
          label: 'Uang keluar',
          angka: uang.keluar,
          jenis: uang.jenisPembanding,
          warnaNilai: warna.brandGelap,
        ),
        const SizedBox(height: TokenJarak.jarak12),
        Divider(height: 1, color: warna.garis),
        const SizedBox(height: TokenJarak.jarak12),
        _BarisAngka(
          label: 'Selisih',
          angka: uang.selisih,
          jenis: uang.jenisPembanding,
          warnaNilai: negatif ? warna.bahaya : warna.sukses,
          besar: true,
        ),
      ],
    );
  }
}

class _BarisAngka extends StatelessWidget {
  const _BarisAngka({
    required this.label,
    required this.angka,
    required this.jenis,
    required this.warnaNilai,
    this.besar = false,
  });

  final String label;
  final AngkaUangKas angka;
  final String jenis;
  final Color warnaNilai;
  final bool besar;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final perubahan = FormatTampilan.KalimatPerubahan(angka.perubahan, jenis);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: (besar ? teks.titleSmall : teks.bodyMedium)?.copyWith(fontWeight: FontWeight.w700)),
              if (perubahan != null) Text(perubahan, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
            ],
          ),
        ),
        const SizedBox(width: TokenJarak.jarak12),
        // Nilai menyusut bila ruangnya sempit (layar kecil atau huruf dibesarkan), tidak pernah terpotong.
        Flexible(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerRight,
            child: Text(
              FormatTampilan.Rupiah(angka.nilai),
              style: (besar ? teks.titleLarge : teks.titleMedium)?.copyWith(
                fontWeight: FontWeight.w800,
                color: warnaNilai,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Peringatan kecil di bawah angka: akun kas belum dipetakan atau angka belum cocok dengan saldo kas. Selalu berteks
/// dan berikon, tidak hanya warna.
class CatatanUangKas extends StatelessWidget {
  const CatatanUangKas({super.key, required this.uang});

  final UangKasPemilik uang;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pesan = !uang.adaAkunKas
        ? 'Akun kas atau bank belum dipetakan, jadi uang masuk dan keluar belum bisa dihitung. Petakan di dashboard.'
        : !uang.cocok
        ? 'Belum cocok dengan saldo kas dan bank: selisih ${FormatTampilan.Rupiah(uang.selisihKecocokan)}. Periksa di dashboard.'
        : null;
    if (pesan == null) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, size: 18, color: warna.peringatan),
          const SizedBox(width: TokenJarak.jarak8),
          Expanded(child: Text(pesan, style: teks.bodySmall)),
        ],
      ),
    );
  }
}

/// Satu bagian rincian (Uang masuk dari mana, Uang keluar ke mana): nama kelompok, nilai, dan porsinya.
class DaftarKelompokUangKas extends StatelessWidget {
  const DaftarKelompokUangKas({super.key, required this.kelompok, required this.kosong});

  final List<KelompokUangKas> kelompok;
  final String kosong;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    if (kelompok.isEmpty) {
      return Text(kosong, style: teks.bodyMedium?.copyWith(color: warna.teksSekunder));
    }
    return Column(
      children: [
        for (final (i, k) in kelompok.indexed) ...[
          if (i > 0) const SizedBox(height: TokenJarak.jarak12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(k.kelompok, style: teks.bodyMedium),
                    if (k.porsi != null)
                      Text(
                        '${k.porsi!.replaceFirst('.', ',')}% dari total',
                        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: TokenJarak.jarak12),
              Text(
                FormatTampilan.Rupiah(k.nilai),
                style: teks.titleSmall?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

/// Kartu Beranda "Uang bulan ini" (D-255): bulan berjalan sampai hari ini, mengikuti outlet terpilih. Hanya tambahan,
/// jadi saat memuat, tanpa izin, di luar paket, atau offline kartu tidak ditampilkan. Mengetuknya membuka Laporan di
/// mode Uang.
class KartuUangBulanIni extends ConsumerWidget {
  const KartuUangBulanIni({super.key, required this.indeksTabLaporan});

  /// Posisi tab Laporan di bilah navigasi.
  final int indeksTabLaporan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final uang = ref.watch(penyediaUangKasBulanIni).value;
    if (uang == null) {
      return const SizedBox.shrink();
    }
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak12),
      child: KartuModern(
        saatTekan: () {
          ref.read(penyediaModeLaporan.notifier).Atur(ModeLaporan.UangKas);
          ref.read(penyediaPermintaanTab.notifier).Minta(indeksTabLaporan);
        },
        anak: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                LingkarIkon(ilustrasi: IlustrasiOwner.MiniKas, warna: warna.brand, ukuran: 40),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Uang bulan ini', style: teks.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                      Text(
                        'Masuk dan keluar kas dan bank, tanpa HPP',
                        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                      ),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right, color: warna.teksSekunder),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak16),
            RingkasanUangKas(uang: uang),
            CatatanUangKas(uang: uang),
          ],
        ),
      ),
    );
  }
}
