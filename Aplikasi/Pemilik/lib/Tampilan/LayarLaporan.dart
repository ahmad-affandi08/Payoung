import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'BilahSaringan.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';
import 'Komponen/GrafikKomposisi.dart';
import 'Komponen/KomponenModern.dart';

const List<(ModeLaporan, String)> _modeLaporan = [
  (ModeLaporan.Penjualan, 'Penjualan'),
  (ModeLaporan.LabaRugi, 'Laba rugi'),
  (ModeLaporan.AntiFraud, 'Anti-fraud'),
  (ModeLaporan.Stok, 'Stok'),
];

/// Laporan ringkas OWN-05: penjualan per produk/kategori/kasir/jam/kanal pada tanggal & outlet terpilih, atau laba rugi
/// bulan berjalan (dibandingkan bulan sebelumnya), skor risiko kasir 7 hari terakhir, atau cek stok (OWN-06).
class LayarLaporan extends ConsumerWidget {
  const LayarLaporan({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(penyediaModeLaporan);
    final outlet = ref.watch(penyediaDasbor).value?.outlet ?? const [];
    // D-183: Anti-fraud kasir butuh kasir (bukan usaha tanpa kasir) dan Stok butuh usaha yang mengelola stok.
    final konfigurasi = ref.watch(penyediaKonfigurasiAplikasi).value;
    final tanpaKasir = konfigurasi?.hanyaPerusahaan ?? false;
    final tanpaStok = !(konfigurasi?.mengelolaStok ?? true);
    final daftarMode = [
      for (final m in _modeLaporan)
        if (!(m.$1 == ModeLaporan.AntiFraud && tanpaKasir) && !(m.$1 == ModeLaporan.Stok && tanpaStok)) m,
    ];
    final modeAktif = daftarMode.any((m) => m.$1 == mode) ? mode : ModeLaporan.Penjualan;
    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      children: [
        const KepalaHalaman(judul: 'Laporan'),
        BilahSaringan(outlet: outlet),
        const SizedBox(height: TokenJarak.jarak12),
        _PilihanLaporan(
          pilihan: daftarMode,
          aktif: modeAktif,
          saatPilih: (nilai) => ref.read(penyediaModeLaporan.notifier).Atur(nilai),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        switch (modeAktif) {
          ModeLaporan.Penjualan => const _Penjualan(),
          ModeLaporan.LabaRugi => const _LabaRugi(),
          ModeLaporan.AntiFraud => const _AntiFraud(),
          ModeLaporan.Stok => const _Stok(),
        },
      ],
    );
  }
}

/// Pilihan laporan sebagai ubin berilustrasi (dua kolom): ubin terpilih berlatar nada merek dan bercincin merek.
class _PilihanLaporan extends StatelessWidget {
  const _PilihanLaporan({required this.pilihan, required this.aktif, required this.saatPilih});

  final List<(ModeLaporan, String)> pilihan;
  final ModeLaporan aktif;
  final ValueChanged<ModeLaporan> saatPilih;

  static IlustrasiOwner PilihIlustrasi(ModeLaporan mode) => switch (mode) {
    ModeLaporan.Penjualan => IlustrasiOwner.MiniPenjualan,
    ModeLaporan.LabaRugi => IlustrasiOwner.MiniLaba,
    ModeLaporan.AntiFraud => IlustrasiOwner.MiniWaspada,
    ModeLaporan.Stok => IlustrasiOwner.KotakStok,
  };

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return LayoutBuilder(
      builder: (context, batas) {
        final lebar = (batas.maxWidth - TokenJarak.jarak8) / 2;
        return Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final (nilai, label) in pilihan)
              SizedBox(
                key: ValueKey('Laporan-${nilai.name}'),
                width: lebar,
                child: Semantics(
                  button: true,
                  selected: aktif == nilai,
                  child: Material(
                    color: aktif == nilai ? TentukanNadaLembut(context, warna.brand, 0.14) : warna.permukaan,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(TokenJarak.radiusKartu - 4),
                      side: BorderSide(color: aktif == nilai ? warna.brand : warna.permukaan, width: 2),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: () => saatPilih(nilai),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(minHeight: 68),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: TokenJarak.jarak12,
                            vertical: TokenJarak.jarak8,
                          ),
                          child: Row(
                            children: [
                              IlustrasiMerek(PilihIlustrasi(nilai), lebar: 44),
                              const SizedBox(width: TokenJarak.jarak8),
                              Expanded(
                                child: Text(
                                  label,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: teks.titleSmall?.copyWith(fontWeight: FontWeight.w800),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
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
              ? const KartuModern(
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
                    if (_GrafikPenjualan.Layak(l.baris, kelompok)) ...[
                      KartuModern(
                        anak: _GrafikPenjualan(baris: l.baris, kelompok: kelompok, totalOmzet: l.totalOmzet),
                      ),
                      const SizedBox(height: TokenJarak.jarak12),
                    ],
                    KartuModern(
                      padding: EdgeInsets.zero,
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

/// Grafik penjualan sesuai kelompok: per jam = batang jam 0 sampai 23 yang ada, selain itu donat komposisi omzet
/// (paling banyak lima irisan, sisanya "Lainnya"). Daftar di bawahnya tetap memuat semua angka.
class _GrafikPenjualan extends StatelessWidget {
  const _GrafikPenjualan({required this.baris, required this.kelompok, required this.totalOmzet});

  final List<BarisNilai> baris;
  final String kelompok;
  final String totalOmzet;

  /// Perlu setidaknya dua baris berangka positif supaya perbandingan bermakna.
  static bool Layak(List<BarisNilai> baris, String kelompok) =>
      baris.where((b) => (double.tryParse(b.omzet) ?? 0) > 0).length >= 2;

  @override
  Widget build(BuildContext context) {
    if (kelompok == 'Jam') {
      final nilai = {for (final b in baris) b.nama: double.tryParse(b.omzet) ?? 0};
      final jam = baris.map((b) => int.tryParse(b.nama)).whereType<int>().toList()..sort();
      final maks = nilai.values.fold<double>(0, (m, n) => n > m ? n : m);
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Omzet per jam', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: TokenJarak.jarak12),
          GrafikBatang(
            batang: [
              if (jam.isNotEmpty)
                for (var j = jam.first; j <= jam.last; j++)
                  BatangGrafik(
                    label: '$j',
                    rasio: maks <= 0 ? 0 : (nilai['$j'] ?? 0) / maks,
                    keterangan: 'Jam $j: ${FormatTampilan.Rupiah('${(nilai['$j'] ?? 0).round()}')}',
                  ),
            ],
          ),
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Komposisi omzet', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: TokenJarak.jarak12),
        PanelKomposisi(
          irisan: IrisanKomposisi.DariBarisNilai(baris),
          judulPusat: 'Total',
          nilaiPusat: FormatTampilan.Rupiah(totalOmzet),
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
            KartuModern(
              padding: EdgeInsets.zero,
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
            if (_AlokasiPendapatan(l) case final List<IrisanKomposisi> irisan) ...[
              const SizedBox(height: TokenJarak.jarak12),
              KartuModern(
                anak: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Ke mana pendapatan pergi', style: teks.titleSmall),
                    const SizedBox(height: TokenJarak.jarak12),
                    PanelKomposisi(irisan: irisan, judulPusat: 'Pendapatan', nilaiPusat: '100%'),
                  ],
                ),
              ),
            ],
            if (akun.isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: TokenJarak.jarak12),
                child: KartuModern(
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
              KartuModern(
                padding: EdgeInsets.zero,
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

  /// Pembagian pendapatan ke HPP, beban, dan laba bersih. Hanya bila pendapatan positif dan ketiganya tidak negatif
  /// (rugi tidak bisa digambar sebagai porsi); selain itu null dan angka tetap terbaca di daftar.
  static List<IrisanKomposisi>? _AlokasiPendapatan(LabaRugiPemilik l) {
    // Hanya tanda angka yang diperiksa (porsi gambar), bukan perhitungan uang.
    int Tanda(String nilai) => (double.tryParse(nilai) ?? 0).sign.toInt();
    if (Tanda(l.pendapatan.nilai) <= 0 ||
        Tanda(l.hpp.nilai) < 0 ||
        Tanda(l.beban.nilai) < 0 ||
        Tanda(l.labaBersih.nilai) < 0) {
      return null;
    }
    return [
      for (final (label, n) in [
        ('Harga pokok penjualan', l.hpp.nilai),
        ('Beban', l.beban.nilai),
        ('Laba bersih', l.labaBersih.nilai),
      ])
        if ((double.tryParse(n) ?? 0) > 0) IrisanKomposisi(label: label, nilai: double.parse(n)),
    ];
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
          ? const KartuModern(
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
                    child: KartuModern(
                      anak: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(child: Text(k.nama, style: teks.titleSmall)),
                              const SizedBox(width: TokenJarak.jarak8),
                              Flexible(
                                child: Text(
                                  'Risiko ${k.tingkat.toLowerCase()} | skor ${k.skor}',
                                  style: teks.titleSmall,
                                  textAlign: TextAlign.end,
                                ),
                              ),
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

/// Cek stok (OWN-06): nilai persediaan, stok kritis, saldo per produk dengan pencarian, dan kartu stok per baris.
class _Stok extends ConsumerStatefulWidget {
  const _Stok();

  @override
  ConsumerState<_Stok> createState() => _StokState();
}

class _StokState extends ConsumerState<_Stok> {
  late final TextEditingController _cari = TextEditingController(text: ref.read(penyediaCariStok));

  @override
  void dispose() {
    _cari.dispose();
    super.dispose();
  }

  void _BukaKartu(BuildContext context, SaldoStokPemilik baris) {
    unawaited(
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => _LembarKartuStok(baris: baris),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _cari,
          textInputAction: TextInputAction.search,
          decoration: const InputDecoration(labelText: 'Cari nama, SKU, atau barcode', prefixIcon: Icon(Icons.search)),
          onSubmitted: (kata) => ref.read(penyediaCariStok.notifier).Atur(kata),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        KeadaanData(
          nilai: ref.watch(penyediaStok),
          saatCobaLagi: () => ref.invalidate(penyediaStok),
          isi: (s) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DecoratedBox(
                decoration: BoxDecoration(
                  color: warna.brandGelap,
                  borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
                ),
                child: ListTile(
                  title: Text('Nilai persediaan', style: teks.titleSmall?.copyWith(color: warna.permukaan)),
                  subtitle: Text(
                    '${s.jumlahProduk} produk | ${s.jumlahKritis} stok kritis',
                    style: teks.bodySmall?.copyWith(color: warna.permukaan),
                  ),
                  trailing: Text(
                    FormatTampilan.Rupiah(s.nilaiPersediaan),
                    style: teks.titleMedium?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
              if (s.kritis.isNotEmpty) ...[
                const SizedBox(height: TokenJarak.jarak12),
                Text('Perlu dibeli', style: teks.titleSmall),
                const SizedBox(height: TokenJarak.jarak8),
                KartuModern(
                  padding: EdgeInsets.zero,
                  anak: Column(
                    children: [
                      for (final (i, k) in s.kritis.indexed) ...[
                        if (i > 0) Divider(height: 1, color: warna.garis),
                        ListTile(
                          leading: Icon(Icons.warning_amber_outlined, color: warna.peringatan),
                          title: Text(k.nama),
                          subtitle: Text(
                            '${k.lokasi} | minimum ${FormatTampilan.Jumlah(k.minimum ?? '0')} ${k.satuan}',
                          ),
                          trailing: Text('${FormatTampilan.Jumlah(k.jumlah)} ${k.satuan}', style: teks.titleSmall),
                          onTap: () => _BukaKartu(context, k),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
              const SizedBox(height: TokenJarak.jarak12),
              Text('Saldo stok', style: teks.titleSmall),
              const SizedBox(height: TokenJarak.jarak8),
              if (s.saldo.isEmpty)
                const KartuModern(
                  anak: KeadaanKosong(
                    ilustrasi: IlustrasiKosong.Laporan,
                    ikon: Icons.inventory_2_outlined,
                    judul: 'Tidak ada stok yang cocok.',
                    keterangan: 'Saldo stok per produk dan lokasi muncul di sini. Coba kata cari lain.',
                  ),
                )
              else ...[
                KartuModern(
                  padding: EdgeInsets.zero,
                  anak: Column(
                    children: [
                      for (final (i, b) in s.saldo.indexed) ...[
                        if (i > 0) Divider(height: 1, color: warna.garis),
                        ListTile(
                          title: Text(b.nama),
                          subtitle: Text('${b.lokasi} | ${FormatTampilan.Rupiah(b.nilai)}'),
                          trailing: Text('${FormatTampilan.Jumlah(b.jumlah)} ${b.satuan}', style: teks.titleSmall),
                          onTap: () => _BukaKartu(context, b),
                        ),
                      ],
                    ],
                  ),
                ),
                if (s.totalSaldo > s.saldo.length)
                  Padding(
                    padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                    child: Text(
                      'Menampilkan ${s.saldo.length} dari ${s.totalSaldo}. Cari nama atau SKU untuk mempersempit.',
                      style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                    ),
                  ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// Riwayat kartu stok 30 hari terakhir untuk satu baris saldo.
class _LembarKartuStok extends ConsumerWidget {
  const _LembarKartuStok({required this.baris});

  final SaldoStokPemilik baris;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final kartu = ref.watch(penyediaKartuStok((baris.uuidProduk, baris.uuidGudang)));
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.8),
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(TokenJarak.jarak16, 0, TokenJarak.jarak16, TokenJarak.jarak16),
          children: [
            Text('Kartu stok | ${baris.nama}', style: teks.titleMedium),
            Text(baris.lokasi, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
            const SizedBox(height: TokenJarak.jarak12),
            KeadaanData(
              nilai: kartu,
              saatCobaLagi: () => ref.invalidate(penyediaKartuStok((baris.uuidProduk, baris.uuidGudang))),
              isi: (k) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Saldo awal ${FormatTampilan.Jumlah(k.saldoAwal)} ${k.satuan} | saldo akhir '
                    '${FormatTampilan.Jumlah(k.saldoAkhir)} ${k.satuan}',
                    style: teks.bodyMedium,
                  ),
                  const SizedBox(height: TokenJarak.jarak8),
                  if (k.mutasi.isEmpty)
                    const KeadaanKosong(
                      ikon: Icons.history,
                      judul: 'Belum ada mutasi 30 hari terakhir.',
                      keterangan: 'Barang masuk dan keluar muncul di sini.',
                    )
                  else
                    for (final m in k.mutasi)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(m.jenis),
                        subtitle: Text([m.tanggal, if (m.referensi != null) m.referensi!].join(' | ')),
                        trailing: Text(
                          m.masuk != null
                              ? '+${FormatTampilan.Jumlah(m.masuk!)}'
                              : '-${FormatTampilan.Jumlah(m.keluar ?? '0')}',
                          style: teks.titleSmall,
                        ),
                      ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
