import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KunciFlagFitur;
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'Komponen/KomponenModern.dart';
import 'LayarAksiCepat.dart';
import 'LayarBantuan.dart';
import 'LayarInsight.dart';
import 'LayarKaryawan.dart';
import 'LayarKunciAplikasi.dart';
import 'LayarNotifikasi.dart';
import 'LayarPerangkat.dart';
import 'LayarShift.dart';

/// Tab Lainnya (D-231): profil, aksi cepat (OWN-07), lalu hal yang jarang dibuka pemilik: shift, perangkat kasir,
/// karyawan (OWN-10), insight (OWN-11), notifikasi, keamanan (OWN-01), bantuan (P-09), ganti usaha, dan keluar.
class LayarLainnya extends ConsumerWidget {
  const LayarLainnya({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    final sesi = ref.watch(penyediaSesi);
    final notifier = ref.read(penyediaSesi.notifier);
    final konfigurasi = ref.watch(penyediaKonfigurasiAplikasi).value;
    // D-183: usaha tanpa kasir (sektor Perusahaan) tidak punya shift, perangkat kasir, dan aksi kasir.
    final tanpaKasir = konfigurasi?.hanyaPerusahaan ?? false;
    final belumDibaca = ref.watch(penyediaNotifikasi).value?.belumDibaca ?? 0;
    final karyawan = CekFlagFitur(ref, KunciFlagFitur.pemilikKaryawan) ? ref.watch(penyediaKaryawan).value : null;
    final insight = CekFlagFitur(ref, KunciFlagFitur.pemilikInsight) ? ref.watch(penyediaInsight).value : null;
    return ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      children: [
        const KepalaHalaman(judul: 'Lainnya'),
        _KartuProfil(
          nama: sesi.namaPengguna.isEmpty ? 'Pemilik' : sesi.namaPengguna,
          usaha: sesi.namaTenant ?? 'Payoung Owner',
          bisaGanti: sesi.tenant.length > 1,
          saatGanti: notifier.GantiTenant,
        ),
        JarakBagian,
        Text('Aksi cepat', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        const SizedBox(height: TokenJarak.jarak8),
        _GrafisAksi(
          butir: [
            (Icons.sell_outlined, 'Ubah harga', warna.aksen, TujuanAksiCepat.harga),
            (Icons.do_not_disturb_on_outlined, 'Tandai habis', warna.bahaya, TujuanAksiCepat.habis),
            (Icons.local_offer_outlined, 'Promo', warna.sukses, TujuanAksiCepat.promo),
            (Icons.receipt_long_outlined, 'Catat pengeluaran', warna.info, TujuanAksiCepat.pengeluaran),
          ],
        ),
        JarakBagian,
        DaftarMenu(
          baris: [
            if (!tanpaKasir) ...[
              BarisMenu(
                ikon: Icons.schedule_outlined,
                warnaIkon: warna.brand,
                judul: 'Shift',
                keterangan: 'Kas dan selisih per kasir',
                saatTekan: () => unawaited(BukaHalaman(context, judul: 'Shift', isi: const LayarShift())),
              ),
              BarisMenu(
                ikon: Icons.point_of_sale_outlined,
                warnaIkon: warna.brand,
                judul: 'Perangkat kasir',
                keterangan: 'Status online dan cabut perangkat',
                saatTekan: () => unawaited(BukaHalaman(context, judul: 'Perangkat kasir', isi: const LayarPerangkat())),
              ),
            ],
            if (karyawan != null)
              BarisMenu(
                ikon: Icons.groups_outlined,
                warnaIkon: karyawan.belumMasuk > 0 || karyawan.terlambat > 0 ? warna.peringatan : warna.brand,
                judul: 'Karyawan hari ini',
                keterangan: LayarKaryawan.Ringkasan(karyawan),
                saatTekan: () => unawaited(BukaHalaman(context, judul: 'Karyawan', isi: const LayarKaryawan())),
              ),
            if (insight != null)
              BarisMenu(
                ikon: Icons.insights_outlined,
                warnaIkon: warna.aksen,
                judul: 'Insight minggu lalu',
                keterangan: LayarInsight.Ringkasan(insight),
                saatTekan: () =>
                    unawaited(BukaHalaman(context, judul: 'Insight minggu lalu', isi: const LayarInsight())),
              ),
            BarisMenu(
              ikon: Icons.notifications_outlined,
              warnaIkon: warna.brand,
              judul: 'Pusat notifikasi',
              keterangan: belumDibaca > 0 ? '$belumDibaca belum dibaca' : 'Semua sudah dibaca',
              saatTekan: () => unawaited(BukaNotifikasi(context)),
              akhir: belumDibaca > 0 ? LencanaAngka(jumlah: belumDibaca, label: '$belumDibaca belum dibaca') : null,
            ),
          ],
        ),
        JarakBagian,
        DaftarMenu(
          baris: [
            BarisMenu(
              ikon: Icons.shield_outlined,
              warnaIkon: warna.sukses,
              judul: 'Kunci aplikasi',
              keterangan: 'PIN dan sidik jari',
              saatTekan: () => unawaited(
                Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const LayarKunciAplikasi())),
              ),
            ),
            BarisMenu(
              ikon: Icons.help_outline,
              warnaIkon: warna.info,
              judul: 'Bantuan',
              keterangan: 'Hubungi tim Payoung',
              saatTekan: () => unawaited(
                Navigator.of(context)
                    .push(MaterialPageRoute<void>(builder: (_) => const LayarBantuan(layarAsal: 'Lainnya'))),
              ),
            ),
            if (sesi.tenant.length > 1)
              BarisMenu(
                ikon: Icons.swap_horiz,
                warnaIkon: warna.brand,
                judul: 'Ganti usaha',
                keterangan: 'Pilih usaha lain',
                saatTekan: notifier.GantiTenant,
              ),
            BarisMenu(
              ikon: Icons.logout,
              warnaIkon: warna.bahaya,
              judul: 'Keluar',
              saatTekan: () => unawaited(notifier.Keluar()),
            ),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak16),
      ],
    );
  }
}

/// Profil di kartu gelap: avatar, nama, usaha, tautan ganti usaha, dan ilustrasi toko.
class _KartuProfil extends StatelessWidget {
  const _KartuProfil({required this.nama, required this.usaha, required this.bisaGanti, required this.saatGanti});

  final String nama;
  final String usaha;
  final bool bisaGanti;
  final VoidCallback saatGanti;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Material(
      color: warna.brandGelap,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartuUtama)),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.only(
          left: TokenJarak.jarak16 + 4,
          top: TokenJarak.jarak16 + 2,
          bottom: TokenJarak.jarak8,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const AvatarPemilik(ukuran: 56),
                    const SizedBox(height: TokenJarak.jarak8),
                    Text(
                      nama,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: teks.titleLarge?.copyWith(fontWeight: FontWeight.w800, color: warna.permukaan),
                    ),
                    Text(
                      'Pemilik | $usaha',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: teks.bodyMedium?.copyWith(color: warna.permukaan.withValues(alpha: 0.78)),
                    ),
                    if (bisaGanti) ...[
                      const SizedBox(height: TokenJarak.jarak12),
                      InkWell(
                        borderRadius: BorderRadius.circular(TokenJarak.radiusKapsul),
                        onTap: saatGanti,
                        child: Container(
                          constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                          padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
                          decoration: BoxDecoration(
                            color: warna.permukaan.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(TokenJarak.radiusKapsul),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.swap_horiz, size: TokenJarak.ikonKecil, color: warna.permukaan),
                              const SizedBox(width: TokenJarak.jarak4),
                              Text(
                                'Ganti usaha',
                                style: teks.labelLarge?.copyWith(fontWeight: FontWeight.w800, color: warna.permukaan),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
            Transform.translate(
              offset: const Offset(14, 8),
              child: const IlustrasiMerek(IlustrasiOwner.Toko, lebar: 150),
            ),
          ],
        ),
      ),
    );
  }
}

/// Empat ubin aksi cepat berjajar, tiap ubin membuka layar aksinya.
class _GrafisAksi extends StatelessWidget {
  const _GrafisAksi({required this.butir});

  final List<(IconData, String, Color, TujuanAksiCepat)> butir;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, (ikon, label, warnaIkon, tujuan)) in butir.indexed) ...[
            if (i > 0) const SizedBox(width: 6),
            Expanded(
              child: KartuModern(
                padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak12, horizontal: 2),
                saatTekan: () => unawaited(BukaAksiCepat(context, tujuan)),
                anak: Column(
                  mainAxisAlignment: MainAxisAlignment.start,
                  children: [
                    LingkarIkon(ikon: ikon, warna: warnaIkon, ukuran: 46),
                    const SizedBox(height: TokenJarak.jarak8),
                    Text(
                      label,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: teks.labelSmall?.copyWith(fontWeight: FontWeight.w800, height: 1.2),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
