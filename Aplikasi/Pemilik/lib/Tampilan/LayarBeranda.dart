import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KunciFlagFitur;
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'BilahSaringan.dart';
import 'DaftarPengumuman.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';
import 'LayarInsight.dart';
import 'LayarKaryawan.dart';

/// Beranda OWN-02 (§17.6 "Hari ini untung berapa, ada masalah apa?"): satu angka besar omzet + perbandingan kemarin &
/// minggu lalu, transaksi, rata-rata, laba kotor; lalu hal yang butuh tindakan, omzet per outlet, per jam, dan produk
/// terlaris.
class LayarBeranda extends ConsumerWidget {
  const LayarBeranda({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dasbor = ref.watch(penyediaDasbor);
    return RefreshIndicator(
      onRefresh: () {
        ref.invalidate(penyediaPengumuman);
        return ref.refresh(penyediaDasbor.future);
      },
      child: KeadaanData(
        nilai: dasbor,
        saatCobaLagi: () => ref.invalidate(penyediaDasbor),
        isi: (d) => _IsiBeranda(dasbor: d),
      ),
    );
  }
}

class _IsiBeranda extends StatelessWidget {
  const _IsiBeranda({required this.dasbor});

  final DasborPemilik dasbor;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final d = dasbor;

    final maksJam = d.perJam.fold<double>(0, (m, j) {
      final v = double.tryParse(j.omzet) ?? 0;
      return v > m ? v : m;
    });
    final maksOutlet = d.perOutlet.fold<double>(0, (m, o) {
      final v = double.tryParse(o.omzet) ?? 0;
      return v > m ? v : m;
    });
    final maksProduk = d.produkTeratas.fold<double>(0, (m, p) {
      final v = double.tryParse(p.omzet) ?? 0;
      return v > m ? v : m;
    });

    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        const DaftarPengumuman(),
        BilahSaringan(outlet: d.outlet),
        const SizedBox(height: TokenJarak.jarak16),
        _KartuOmzet(dasbor: d),
        const SizedBox(height: TokenJarak.jarak12),
        DeretKartuAngka(
          lebarMinimum: 104,
          kartu: [
            KartuAngka(label: 'Transaksi', ikon: Icons.receipt_long_outlined, nilai: Text('${d.transaksi}')),
            KartuAngka(
              label: 'Rata-rata',
              ikon: Icons.shopping_basket_outlined,
              nilai: Text(FormatTampilan.Rupiah(d.rataRata)),
            ),
            if (d.labaKotor != null)
              KartuAngka(
                label: 'Laba kotor',
                ikon: Icons.savings_outlined,
                nilai: Text(FormatTampilan.Rupiah(d.labaKotor!)),
              ),
          ],
        ),
        const _KartuInsight(),
        const _KartuKaryawan(),
        _BagianBeranda(
          judul: 'Perlu tindakan',
          ikon: Icons.notifications_active_outlined,
          lencana: d.perluTindakan.isEmpty ? null : '${d.perluTindakan.length}',
          anak: d.perluTindakan.isEmpty
              ? const KeadaanKosong(
                  ilustrasi: IlustrasiKosong.Umum,
                  ikon: Icons.check_circle_outline,
                  judul: 'Tidak ada yang perlu ditindaklanjuti.',
                  keterangan: 'Selisih kas, stok menipis, dan hal penting lain akan muncul di sini.',
                  ringkas: true,
                )
              : Column(
                  children: [
                    for (final h in d.perluTindakan)
                      ListTile(
                        leading: Icon(Icons.warning_amber_rounded, color: warna.peringatan),
                        title: Text(h.judul),
                        subtitle: Text(h.keterangan),
                      ),
                  ],
                ),
        ),
        if (d.perOutlet.length > 1)
          _BagianBeranda(
            judul: 'Per outlet',
            ikon: Icons.storefront_outlined,
            anak: Column(
              children: [
                for (final o in d.perOutlet)
                  _BarisPeringkat(
                    judul: o.nama,
                    keterangan: '${o.transaksi ?? 0} transaksi',
                    nilai: FormatTampilan.Rupiah(o.omzet),
                    rasio: maksOutlet == 0 ? 0 : (double.tryParse(o.omzet) ?? 0) / maksOutlet,
                  ),
              ],
            ),
          ),
        if (d.perJam.isNotEmpty && maksJam > 0)
          _BagianBeranda(
            judul: 'Per jam',
            ikon: Icons.schedule_outlined,
            anak: GrafikBatang(
              batang: [
                for (final j in d.perJam)
                  BatangGrafik(
                    label: j.nama,
                    rasio: (double.tryParse(j.omzet) ?? 0) / maksJam,
                    keterangan: 'Jam ${j.nama}: ${FormatTampilan.Rupiah(j.omzet)}',
                  ),
              ],
            ),
          ),
        if (d.produkTeratas.isNotEmpty)
          _BagianBeranda(
            judul: 'Produk terlaris',
            ikon: Icons.local_fire_department_outlined,
            anak: Column(
              children: [
                for (final (i, p) in d.produkTeratas.indexed)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
                    child: Row(
                      children: [
                        CircleAvatar(
                          radius: 14,
                          backgroundColor: i == 0 ? warna.brand : warna.latar,
                          child: Text(
                            '${i + 1}',
                            style: teks.labelMedium?.copyWith(
                              color: i == 0 ? warna.permukaan : warna.teksSekunder,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                        const SizedBox(width: TokenJarak.jarak12),
                        Expanded(
                          child: _BarisPeringkat(
                            judul: p.nama,
                            keterangan: p.jumlah == null ? null : '${FormatTampilan.Jumlah(p.jumlah!)} terjual',
                            nilai: FormatTampilan.Rupiah(p.omzet),
                            rasio: maksProduk == 0 ? 0 : (double.tryParse(p.omzet) ?? 0) / maksProduk,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        const SizedBox(height: TokenJarak.jarak16),
      ],
    );
  }
}

/// Kartu omzet hari ini: satu angka besar di atas latar merek gelap, dengan dua chip perbandingan (kemarin & minggu
/// lalu). Teks perbandingan tetap lengkap (persen + nilai) sehingga tidak bergantung pada warna ikon (§17.6.11).
class _KartuOmzet extends StatelessWidget {
  const _KartuOmzet({required this.dasbor});

  final DasborPemilik dasbor;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final d = dasbor;

    Widget Perbandingan(String label, String pembanding) {
      final ubah = FormatTampilan.Perubahan(d.omzet, pembanding);
      final naik = ubah != null && !ubah.startsWith('-');
      return DecoratedBox(
        decoration: BoxDecoration(
          color: warna.permukaan.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (ubah != null) ...[
                Icon(
                  naik ? Icons.trending_up : Icons.trending_down,
                  size: TokenJarak.ikonSedang,
                  color: warna.permukaan,
                ),
                const SizedBox(width: TokenJarak.jarak8),
              ],
              Flexible(
                child: Text(
                  ubah == null
                      ? '$label: ${FormatTampilan.Rupiah(pembanding)}'
                      : '$ubah dari $label (${FormatTampilan.Rupiah(pembanding)})',
                  style: teks.bodyMedium?.copyWith(color: warna.permukaan),
                ),
              ),
            ],
          ),
        ),
      );
    }

    return DecoratedBox(
      decoration: BoxDecoration(color: warna.brandGelap, borderRadius: BorderRadius.circular(TokenJarak.radiusPanel)),
      child: Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.payments_outlined, size: TokenJarak.ikonSedang, color: warna.permukaan),
                const SizedBox(width: TokenJarak.jarak8),
                Text('Omzet', style: teks.labelLarge?.copyWith(color: warna.permukaan.withValues(alpha: 0.85))),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak8),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(
                FormatTampilan.Rupiah(d.omzet),
                style: teks.displaySmall?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w700),
              ),
            ),
            const SizedBox(height: TokenJarak.jarak16),
            Wrap(
              spacing: TokenJarak.jarak8,
              runSpacing: TokenJarak.jarak8,
              children: [Perbandingan('kemarin', d.omzetKemarin), Perbandingan('minggu lalu', d.omzetMingguLalu)],
            ),
          ],
        ),
      ),
    );
  }
}

/// Bagian Beranda dalam panel bergaris: kepala (ikon, judul, lencana jumlah opsional) lalu isi.
class _BagianBeranda extends StatelessWidget {
  const _BagianBeranda({required this.judul, required this.ikon, required this.anak, this.lencana});

  final String judul;
  final IconData ikon;
  final Widget anak;
  final String? lencana;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak16),
      child: KotakPanel(
        anak: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(ikon, size: TokenJarak.ikonSedang, color: warna.brand),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(child: Text(judul, style: teks.titleMedium)),
                if (lencana case final String isi)
                  LencanaTeks(teks: isi, label: '$isi hal perlu tindakan', nada: NadaStatus.Peringatan),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak8),
            anak,
          ],
        ),
      ),
    );
  }
}

/// Baris peringkat: judul + keterangan di kiri, nilai di kanan, dan batang tipis sepanjang [rasio] (0–1) dari yang
/// terbesar. Angka selalu tertulis; batang hanya membantu membandingkan sekilas.
class _BarisPeringkat extends StatelessWidget {
  const _BarisPeringkat({required this.judul, required this.nilai, required this.rasio, this.keterangan});

  final String judul;
  final String nilai;
  final double rasio;
  final String? keterangan;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(judul, maxLines: 1, overflow: TextOverflow.ellipsis, style: teks.bodyMedium),
                    if (keterangan case final String isi)
                      Text(isi, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                  ],
                ),
              ),
              const SizedBox(width: TokenJarak.jarak12),
              Text(nilai, style: teks.labelLarge),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          ClipRRect(
            borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
            child: LinearProgressIndicator(
              value: rasio.clamp(0.02, 1).toDouble(),
              minHeight: 6,
              color: warna.brand,
              backgroundColor: warna.latar,
            ),
          ),
        ],
      ),
    );
  }
}

/// Baris tautan ke layar lain (Karyawan, Insight) dalam panel: ikon dalam lingkaran, judul, ringkasan, chevron.
class _BarisTautan extends StatelessWidget {
  const _BarisTautan({
    required this.ikon,
    required this.warnaIkon,
    required this.judul,
    required this.ringkasan,
    required this.saatTekan,
  });

  final IconData ikon;
  final Color warnaIkon;
  final String judul;
  final String ringkasan;
  final VoidCallback saatTekan;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak12),
      child: KotakPanel(
        rapat: true,
        anak: ListTile(
          leading: CircleAvatar(
            backgroundColor: warnaIkon.withValues(alpha: 0.12),
            child: Icon(ikon, color: warnaIkon),
          ),
          title: Text(judul),
          subtitle: Text(ringkasan),
          trailing: const Icon(Icons.chevron_right),
          onTap: saatTekan,
        ),
      ),
    );
  }
}

/// OWN-10: ringkasan kehadiran karyawan hari ini; ketuk untuk membuka layar Karyawan. Tidak tampil selama memuat atau
/// bila pengguna tidak punya izin `karyawan.lihat` (server menolak) — Beranda tetap utuh.
class _KartuKaryawan extends ConsumerWidget {
  const _KartuKaryawan();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // PGL-18: kill switch `pemilik.karyawan` menyembunyikan kartu tanpa memanggil server.
    if (!CekFlagFitur(ref, KunciFlagFitur.pemilikKaryawan)) return const SizedBox.shrink();
    final data = ref.watch(penyediaKaryawan).value;
    if (data == null) return const SizedBox.shrink();
    final warna = TokenWarna.AmbilDari(context);
    return _BarisTautan(
      ikon: Icons.groups_outlined,
      warnaIkon: data.belumMasuk > 0 || data.terlambat > 0 ? warna.peringatan : warna.brand,
      judul: 'Karyawan hari ini',
      ringkasan: LayarKaryawan.Ringkasan(data),
      saatTekan: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            appBar: AppBar(title: const Text('Karyawan')),
            body: const LayarKaryawan(),
          ),
        ),
      ),
    );
  }
}

/// OWN-11: ringkasan insight minggu lalu; ketuk untuk membuka layar Insight. Tidak tampil selama memuat, bila belum ada
/// penjualan untuk dibandingkan, atau bila server menolak.
class _KartuInsight extends ConsumerWidget {
  const _KartuInsight();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // PGL-18: kill switch `pemilik.insight` menyembunyikan kartu tanpa memanggil server.
    if (!CekFlagFitur(ref, KunciFlagFitur.pemilikInsight)) return const SizedBox.shrink();
    final data = ref.watch(penyediaInsight).value;
    if (data == null) return const SizedBox.shrink();
    final warna = TokenWarna.AmbilDari(context);
    return _BarisTautan(
      ikon: Icons.insights_outlined,
      warnaIkon: warna.brand,
      judul: 'Insight minggu lalu',
      ringkasan: LayarInsight.Ringkasan(data),
      saatTekan: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            appBar: AppBar(title: const Text('Insight minggu lalu')),
            body: const LayarInsight(),
          ),
        ),
      ),
    );
  }
}
