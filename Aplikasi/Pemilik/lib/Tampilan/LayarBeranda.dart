import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'BilahSaringan.dart';
import 'DaftarPengumuman.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';
import 'Komponen/GrafikKomposisi.dart';
import 'Komponen/KomponenModern.dart';
import 'LayarKunciAplikasi.dart';
import 'LayarNotifikasi.dart';

/// Beranda OWN-02 (§17.6 "Hari ini untung berapa, ada masalah apa?", D-231): sapaan dan bilah atas, satu angka omzet
/// besar dengan kurva per jam dan perbandingan kemarin & minggu lalu, ubin transaksi, rata-rata, dan laba kotor, kartu
/// sorotan jam tersibuk, lalu hal yang butuh tindakan, omzet per outlet, dan produk terlaris. Karyawan dan insight
/// ada di tab Lainnya.
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

class _IsiBeranda extends ConsumerWidget {
  const _IsiBeranda({required this.dasbor});

  final DasborPemilik dasbor;

  /// Batang omzet per jam dari jam pertama sampai terakhir yang ada penjualan (jam kosong = batang nol). Nilai tiap
  /// batang ada di tooltip dan label semantik.
  static List<BatangGrafik> _BatangJam(List<BarisNilai> perJam) {
    final nilai = <int, double>{
      for (final j in perJam)
        if (int.tryParse(j.nama) case final int jam) jam: double.tryParse(j.omzet) ?? 0,
    };
    final maks = nilai.values.fold<double>(0, (m, n) => n > m ? n : m);
    if (nilai.isEmpty || maks <= 0) {
      return const [];
    }
    final awal = nilai.keys.reduce((a, b) => a < b ? a : b);
    final akhir = nilai.keys.reduce((a, b) => a > b ? a : b);
    return [
      for (var jam = awal; jam <= akhir; jam++)
        BatangGrafik(
          label: '$jam',
          rasio: (nilai[jam] ?? 0) / maks,
          keterangan: 'Jam $jam: ${FormatTampilan.Rupiah('${(nilai[jam] ?? 0).round()}')}',
        ),
    ];
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final d = dasbor;

    double Maks(List<BarisNilai> daftar) =>
        daftar.fold<double>(0, (m, b) => (double.tryParse(b.omzet) ?? 0) > m ? double.tryParse(b.omzet) ?? 0 : m);
    final maksProduk = Maks(d.produkTeratas);

    // Jam tersibuk untuk kartu sorotan (hanya bila ada penjualan per jam).
    final maksJam = Maks(d.perJam);
    final jamTersibuk = maksJam > 0
        ? d.perJam.firstWhere((j) => (double.tryParse(j.omzet) ?? 0) == maksJam).nama.padLeft(2, '0')
        : null;

    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        const DaftarPengumuman(),
        const _KepalaBeranda(),
        const SizedBox(height: TokenJarak.jarak16),
        BilahSaringan(outlet: d.outlet),
        const SizedBox(height: TokenJarak.jarak16),
        _KartuOmzet(dasbor: d),
        const SizedBox(height: TokenJarak.jarak12),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _UbinAngka(
                  ilustrasi: IlustrasiOwner.IkonTransaksi,
                  warnaIkon: warna.brand,
                  label: 'Transaksi',
                  nilai: '${d.transaksi}',
                ),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: _UbinAngka(
                  ilustrasi: IlustrasiOwner.IkonRataRata,
                  warnaIkon: warna.aksen,
                  label: 'Rata-rata',
                  nilai: FormatTampilan.Rupiah(d.rataRata),
                ),
              ),
              if (d.labaKotor != null) ...[
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: _UbinAngka(
                    ilustrasi: IlustrasiOwner.IkonLaba,
                    warnaIkon: warna.sukses,
                    label: 'Laba kotor',
                    nilai: FormatTampilan.Rupiah(d.labaKotor!),
                  ),
                ),
              ],
            ],
          ),
        ),
        _JudulBagian(judul: 'Perlu tindakan', lencana: d.perluTindakan.isEmpty ? null : d.perluTindakan.length),
        if (d.perluTindakan.isEmpty)
          const KartuModern(
            anak: KeadaanKosong(
              ilustrasi: IlustrasiKosong.Umum,
              ikon: Icons.check_circle_outline,
              judul: 'Tidak ada yang perlu ditindaklanjuti.',
              keterangan: 'Selisih kas, stok menipis, dan hal penting lain akan muncul di sini.',
              ringkas: true,
            ),
          )
        else
          DaftarMenu(
            baris: [
              for (final h in d.perluTindakan)
                BarisMenu(
                  ikon: Icons.warning_amber_rounded,
                  warnaIkon: warna.peringatan,
                  judul: h.judul,
                  keterangan: h.keterangan,
                ),
            ],
          ),
        if (jamTersibuk != null) ...[
          const SizedBox(height: TokenJarak.jarak16),
          KartuSorotan(
            latar: TentukanNadaLembut(context, warna.aksen, 0.16),
            judul: 'Jam tersibuk: $jamTersibuk.00',
            keterangan: d.produkTeratas.isEmpty
                ? 'Omzet terbesar hari ini terjadi pada jam ini.'
                : '${d.produkTeratas.first.nama} paling laku hari ini.',
            ilustrasi: IlustrasiOwner.Toko,
            lebarIlustrasi: 132,
          ),
        ],
        if (_BatangJam(d.perJam) case final List<BatangGrafik> batangJam when batangJam.length >= 2) ...[
          const _JudulBagian(judul: 'Omzet per jam'),
          KartuModern(anak: GrafikBatang(batang: batangJam)),
        ],
        if (d.perOutlet.length > 1) ...[
          const _JudulBagian(judul: 'Per outlet'),
          KartuModern(
            anak: PanelKomposisi(
              irisan: IrisanKomposisi.DariBarisNilai(
                d.perOutlet,
                rincian: (o) => '${FormatTampilan.Rupiah(o.omzet)} | ${o.transaksi ?? 0} transaksi',
              ),
              judulPusat: 'Outlet',
              nilaiPusat: '${d.perOutlet.length}',
            ),
          ),
        ],
        if (d.produkTeratas.isNotEmpty) ...[
          const _JudulBagian(judul: 'Produk terlaris'),
          KartuModern(
            padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak8),
            anak: Column(
              children: [
                for (final (i, p) in d.produkTeratas.indexed)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
                    child: Row(
                      children: [
                        Container(
                          width: 32,
                          height: 32,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: i == 0 ? TentukanNadaLembut(context, warna.aksen, 0.35) : warna.brandTint,
                            shape: BoxShape.circle,
                          ),
                          child: Text(
                            '${i + 1}',
                            style: teks.labelLarge?.copyWith(
                              fontWeight: FontWeight.w800,
                              color: i == 0 ? warna.brandGelap : warna.brand,
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
        ],
        const SizedBox(height: TokenJarak.jarak16),
        // OWN-01: ajakan pasang PIN di paling bawah supaya tidak menggeser angka yang dicari pemilik.
        const KartuAjakanKunci(),
      ],
    );
  }
}

/// Bilah atas Beranda: avatar, sapaan menurut jam, nama usaha, dan lonceng notifikasi (titik apricot bila ada yang
/// belum dibaca).
class _KepalaBeranda extends ConsumerWidget {
  const _KepalaBeranda();

  static String Sapaan(int jam) => switch (jam) {
    < 11 => 'Selamat pagi',
    < 15 => 'Selamat siang',
    < 18 => 'Selamat sore',
    _ => 'Selamat malam',
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final sesi = ref.watch(penyediaSesi);
    final belumDibaca = ref.watch(penyediaNotifikasi).value?.belumDibaca ?? 0;
    final depan = sesi.namaPengguna.trim().split(RegExp(r'\s+')).first;
    final sapaan = Sapaan(ref.read(penyediaJam)().hour);
    return Row(
      children: [
        const AvatarPemilik(),
        const SizedBox(width: TokenJarak.jarak12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                depan.isEmpty ? sapaan : '$sapaan, $depan',
                style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
              ),
              Text(
                sesi.namaTenant ?? 'Payoung Owner',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: teks.titleLarge?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.3),
              ),
            ],
          ),
        ),
        Semantics(
          button: true,
          label: belumDibaca > 0 ? 'Notifikasi ($belumDibaca belum dibaca)' : 'Notifikasi',
          excludeSemantics: true,
          child: Material(
            color: warna.permukaan,
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: () => BukaNotifikasi(context),
              child: SizedBox(
                width: 48,
                height: 48,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    const Icon(Icons.notifications_outlined),
                    if (belumDibaca > 0)
                      Positioned(
                        top: 11,
                        right: 12,
                        child: Container(
                          width: 11,
                          height: 11,
                          decoration: BoxDecoration(
                            color: warna.aksen,
                            shape: BoxShape.circle,
                            border: Border.all(color: warna.permukaan, width: 2),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Kartu omzet hari ini: satu angka besar di atas latar merek gelap, dua chip perbandingan (kemarin & minggu lalu), dan
/// kurva omzet per jam. Teks perbandingan tetap lengkap (persen + nilai) sehingga tidak bergantung pada warna ikon
/// (§17.6.11).
class _KartuOmzet extends StatelessWidget {
  const _KartuOmzet({required this.dasbor});

  final DasborPemilik dasbor;

  /// Deret omzet per jam dari jam pertama sampai terakhir yang ada penjualan (jam kosong = 0), dinormalkan 0 sampai 1.
  static ({List<double> titik, List<int> jam}) DeretJam(List<BarisNilai> perJam) {
    final nilai = <int, double>{
      for (final j in perJam)
        if (int.tryParse(j.nama) case final int jam) jam: double.tryParse(j.omzet) ?? 0,
    };
    if (nilai.length < 2) {
      return (titik: const [], jam: const []);
    }
    final awal = nilai.keys.reduce((a, b) => a < b ? a : b);
    final akhir = nilai.keys.reduce((a, b) => a > b ? a : b);
    final maks = nilai.values.reduce((a, b) => a > b ? a : b);
    if (maks <= 0) {
      return (titik: const [], jam: const []);
    }
    final jam = [for (var i = awal; i <= akhir; i++) i];
    return (titik: [for (final i in jam) (nilai[i] ?? 0) / maks], jam: jam);
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final d = dasbor;
    final deret = DeretJam(d.perJam);

    Widget BuatPerbandingan(String label, String pembanding) {
      final ubah = FormatTampilan.Perubahan(d.omzet, pembanding);
      final naik = ubah != null && !ubah.startsWith('-');
      return DecoratedBox(
        decoration: BoxDecoration(
          color: warna.permukaan.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(TokenJarak.radiusTombol),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
          child: Row(
            children: [
              if (ubah != null) ...[
                Icon(
                  naik ? Icons.trending_up : Icons.trending_down,
                  size: TokenJarak.ikonSedang,
                  color: warna.permukaan,
                ),
                const SizedBox(width: TokenJarak.jarak8),
              ],
              Expanded(
                child: Text(
                  ubah == null
                      ? '$label: ${FormatTampilan.Rupiah(pembanding)}'
                      : '$ubah dari $label (${FormatTampilan.Rupiah(pembanding)})',
                  style: teks.labelLarge?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w700),
                ),
              ),
            ],
          ),
        ),
      );
    }

    // Label sumbu jam: paling banyak empat, rata dari jam pertama sampai terakhir.
    final labelJam = <int>[];
    if (deret.jam.isNotEmpty) {
      final banyak = deret.jam.length < 4 ? deret.jam.length : 4;
      for (var i = 0; i < banyak; i++) {
        labelJam.add(deret.jam[(i * (deret.jam.length - 1) / (banyak - 1)).round()]);
      }
    }
    return DecoratedBox(
      decoration: BoxDecoration(
        color: warna.brandGelap,
        borderRadius: BorderRadius.circular(TokenJarak.radiusKartuUtama),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          TokenJarak.jarak24 - 4,
          TokenJarak.jarak16 + 2,
          TokenJarak.jarak24 - 4,
          TokenJarak.jarak16,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Omzet', style: teks.labelLarge?.copyWith(color: warna.permukaan.withValues(alpha: 0.85))),
            const SizedBox(height: TokenJarak.jarak8),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(
                FormatTampilan.Rupiah(d.omzet),
                style: teks.displaySmall?.copyWith(
                  color: warna.permukaan,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -1,
                ),
              ),
            ),
            const SizedBox(height: TokenJarak.jarak12),
            // Dua perbandingan sejajar (tinggi sama); teks membungkus bila sempit.
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: BuatPerbandingan('kemarin', d.omzetKemarin)),
                  const SizedBox(width: TokenJarak.jarak8),
                  Expanded(child: BuatPerbandingan('minggu lalu', d.omzetMingguLalu)),
                ],
              ),
            ),
            if (deret.titik.isNotEmpty) ...[
              const SizedBox(height: TokenJarak.jarak16),
              GrafikLengkung(
                titik: deret.titik,
                tinggi: 76,
                label:
                    'Omzet per jam: ${[for (final j in d.perJam) 'jam ${j.nama} ${FormatTampilan.Rupiah(j.omzet)}'].join(', ')}',
              ),
              const SizedBox(height: TokenJarak.jarak4),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  for (final j in labelJam)
                    Text(
                      '${j.toString().padLeft(2, '0')}.00',
                      style: teks.labelSmall?.copyWith(color: warna.permukaan.withValues(alpha: 0.7)),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Ubin angka ringkas: ikon berlatar lembut, label, lalu nilai (mengecil bila sempit).
class _UbinAngka extends StatelessWidget {
  const _UbinAngka({required this.ilustrasi, required this.warnaIkon, required this.label, required this.nilai});

  final IlustrasiOwner ilustrasi;
  final Color warnaIkon;
  final String label;
  final String nilai;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return KartuModern(
      padding: const EdgeInsets.all(TokenJarak.jarak12),
      anak: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          LingkarIkon(ilustrasi: ilustrasi, warna: warnaIkon, ukuran: 42),
          const SizedBox(height: TokenJarak.jarak8),
          Text(label, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(nilai, style: teks.titleMedium?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.2)),
          ),
        ],
      ),
    );
  }
}

/// Judul bagian dengan lencana jumlah opsional.
class _JudulBagian extends StatelessWidget {
  const _JudulBagian({required this.judul, this.lencana});

  final String judul;
  final int? lencana;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(TokenJarak.jarak4, TokenJarak.jarak24, 0, TokenJarak.jarak8),
    child: Row(
      children: [
        Text(judul, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        if (lencana case final int n) ...[
          const SizedBox(width: TokenJarak.jarak8),
          LencanaAngka(jumlah: n, label: '$n hal perlu tindakan'),
        ],
      ],
    ),
  );
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
                    Text(
                      judul,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
                    ),
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
            borderRadius: BorderRadius.circular(TokenJarak.radiusKapsul),
            child: LinearProgressIndicator(
              value: rasio.clamp(0.02, 1).toDouble(),
              minHeight: 8,
              color: warna.brand,
              backgroundColor: warna.latar,
            ),
          ),
        ],
      ),
    );
  }
}
