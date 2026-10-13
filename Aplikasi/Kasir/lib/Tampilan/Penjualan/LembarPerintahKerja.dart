import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/LayananPerintahKerja.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatAngka.dart';

/// Bengkel bagian 2 (§9.10, K-27): perintah kerja (WO) yang siap ditagih di outlet ini (perlu online). Ketuk satu
/// perintah kerja untuk melihat rinciannya (jasa beserta mekanik, sparepart, diskon), lalu "Tagih ke keranjang" memuat
/// baris yang disetujui pelanggan + pelanggannya ke keranjang; pembayaran di layar Jual (bisa offline) menandai
/// perintah kerja Ditagih di server lewat `UuidPerintahKerja`.
class LembarPerintahKerja extends ConsumerStatefulWidget {
  const LembarPerintahKerja({super.key, required this.kasir, required this.saatDimuat});

  static const String judul = 'Servis siap tagih';

  final StafLokal kasir;

  /// Dipanggil setelah keranjang terisi (pindah ke layar Jual).
  final VoidCallback saatDimuat;

  @override
  ConsumerState<LembarPerintahKerja> createState() => _LembarPerintahKerjaState();
}

class _LembarPerintahKerjaState extends ConsumerState<LembarPerintahKerja> {
  List<PerintahKerjaPos>? _daftar;
  PerintahKerjaPos? _dipilih;
  String? _galat;
  bool _sibuk = false;
  bool _menagih = false;
  bool _semuaAktif = false;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final daftar = await ref.read(penyediaLayananPerintahKerja).Ambil(semuaAktif: _semuaAktif);
      if (mounted) {
        setState(() => _daftar = daftar);
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  Future<void> _Tagih(PerintahKerjaPos pk) async {
    if (!ref.read(penyediaKeranjang).CekBebas) {
      setState(() => _galat = 'Keranjang masih berisi. Selesaikan, tahan, atau batalkan transaksi itu dulu.');
      return;
    }
    setState(() {
      _menagih = true;
      _galat = null;
    });
    try {
      LayananPerintahKerja.PeriksaIzin(widget.kasir);
      final layanan = ref.read(penyediaLayananPerintahKerja);
      final katalog = await ref.read(penyediaKatalog.future);
      final k = await ref.read(penyediaKonteksPenjualan.future);
      // Baca ulang dulu: perintah kerja bisa sudah ditagih perangkat lain atau barisnya berubah sejak daftar dimuat.
      final terbaru = await layanan.AmbilSatu(pk.uuid);
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.MuatKeKeranjang(terbaru, katalog, k));
      widget.saatDimuat();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _menagih = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final dipilih = _dipilih;
    return dipilih == null ? _BangunDaftar(context) : _BangunRincian(context, dipilih);
  }

  Widget _BangunGalat(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak8),
      child: Semantics(
        liveRegion: true,
        child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
      ),
    );
  }

  Widget _BangunDaftar(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final daftar = _daftar ?? const <PerintahKerjaPos>[];
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SegmentedButton<bool>(
          key: const ValueKey('TabPerintahKerja'),
          segments: const [
            ButtonSegment(value: false, label: Text('Siap tagih')),
            ButtonSegment(value: true, label: Text('Semua yang berjalan')),
          ],
          selected: {_semuaAktif},
          onSelectionChanged: _sibuk
              ? null
              : (nilai) {
                  setState(() => _semuaAktif = nilai.first);
                  unawaited(_Muat());
                },
        ),
        const SizedBox(height: TokenJarak.jarak12),
        Row(
          children: [
            Expanded(
              child: Text(
                'Perintah kerja outlet ini yang sudah disetujui pelanggan. Perlu koneksi internet; pembayaran tetap '
                'bisa offline.',
                style: teks.bodySmall,
              ),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton.icon(
                onPressed: _sibuk ? null : () => unawaited(_Muat()),
                icon: const Icon(Icons.refresh),
                label: const Text('Muat ulang'),
              ),
            ),
          ],
        ),
        if (_sibuk)
          const Padding(
            padding: EdgeInsets.only(top: TokenJarak.jarak8),
            child: LinearProgressIndicator(),
          ),
        if (_galat != null) _BangunGalat(context),
        if (_daftar != null && daftar.isEmpty)
          KeadaanKosong(
            ikon: Icons.build_outlined,
            ilustrasi: IlustrasiKosong.Servis,
            ringkas: true,
            judul: _semuaAktif ? 'Tidak ada perintah kerja yang sedang berjalan' : 'Belum ada servis yang siap ditagih',
          ),
        if (daftar.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak12),
            child: Material(
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
                    _BarisPerintahKerja(
                      pk: daftar[i],
                      saatDiketuk: () => setState(() {
                        _dipilih = daftar[i];
                        _galat = null;
                      }),
                    ),
                  ],
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _BangunRincian(BuildContext context, PerintahKerjaPos pk) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final jasa = pk.baris.where((b) => b.CekJasa).toList();
    final sparepart = pk.baris.where((b) => !b.CekJasa).toList();

    Widget Baris(BarisPerintahKerjaPos b) {
      final jumlah = Kuantitas.Dari(b.jumlah);
      final harga = Uang.Dari(b.hargaSatuan);
      final diskon = Uang.Dari(b.diskon);
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(b.namaProduk, style: teks.bodyLarge),
                  Text(
                    [
                      '${FormatAngka.FormatJumlah(jumlah)} × ${harga.FormatRupiah()}',
                      if (!diskon.BernilaiNol()) 'diskon ${diskon.FormatRupiah()}',
                      if (b.CekJasa) b.namaKaryawan == null ? 'tanpa mekanik' : 'Mekanik: ${b.namaKaryawan}',
                    ].join(' | '),
                    style: teks.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            TeksUang(harga.Kali(jumlah.KeDesimal()).Kurangi(diskon), gaya: teks.bodyLarge),
          ],
        ),
      );
    }

    Widget Bagian(String judul, List<BarisPerintahKerjaPos> isi) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: TokenJarak.jarak12),
        Text(judul, style: teks.titleSmall),
        Divider(height: TokenJarak.jarak8, color: warna.garis),
        for (final b in isi) Baris(b),
      ],
    );

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(
            height: TokenJarak.targetSentuh,
            child: TextButton.icon(
              onPressed: _menagih ? null : () => setState(() => _dipilih = null),
              icon: const Icon(Icons.arrow_back),
              label: const Text('Kembali ke daftar'),
            ),
          ),
        ),
        Wrap(
          spacing: TokenJarak.jarak8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(pk.pengenalUnit ?? 'Tanpa unit', style: teks.titleMedium),
            TeksKode(pk.nomor, gaya: teks.bodySmall),
            Text(pk.labelStatus, style: teks.labelMedium),
          ],
        ),
        Text(
          [
            if (!pk.unitPerangkat) ?pk.labelKendaraan,
            if (pk.unitPerangkat) ?pk.nomorPolisi,
            pk.namaPelanggan ?? 'Pelanggan umum',
            if (!pk.unitPerangkat && pk.kmMasuk != null) 'KM ${pk.kmMasuk}',
          ].join(' | '),
          style: teks.bodyMedium,
        ),
        if (pk.keluhan case final String keluhan when keluhan.isNotEmpty)
          Text('Keluhan: $keluhan', style: teks.bodySmall),
        if (pk.catatanQc case final String qc when qc.isNotEmpty) Text('Catatan QC: $qc', style: teks.bodySmall),
        if (jasa.isNotEmpty) Bagian('Jasa', jasa),
        if (sparepart.isNotEmpty) Bagian('Sparepart', sparepart),
        if (pk.baris.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak12),
            child: Text('Belum ada baris yang disetujui pelanggan.', style: teks.bodyMedium),
          ),
        Divider(height: TokenJarak.jarak16, color: warna.garis),
        Row(
          children: [
            Expanded(child: Text('Total disetujui', style: teks.titleSmall)),
            TeksUang(Uang.Dari(pk.totalDisetujui), gaya: teks.titleMedium),
          ],
        ),
        Text('Total di kasir dihitung ulang dengan pajak & promo yang berlaku saat dibayar.', style: teks.bodySmall),
        if (_galat != null) _BangunGalat(context),
        if (!pk.siapTagih)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Row(
              children: [
                Icon(Icons.info_outline, size: TokenJarak.ikonKecil, color: warna.peringatan),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Text(
                    'Belum bisa ditagih: perintah kerja ${pk.labelStatus.toLowerCase()}.',
                    style: teks.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: TokenJarak.jarak12),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: FilledButton.icon(
            onPressed: _menagih || !pk.siapTagih || pk.baris.isEmpty ? null : () => unawaited(_Tagih(pk)),
            icon: const Icon(Icons.shopping_cart_checkout),
            label: Text(_menagih ? 'Memuat…' : 'Tagih ke keranjang'),
          ),
        ),
      ],
    );
  }
}

class _BarisPerintahKerja extends StatelessWidget {
  const _BarisPerintahKerja({required this.pk, required this.saatDiketuk});

  final PerintahKerjaPos pk;
  final VoidCallback saatDiketuk;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return InkWell(
      onTap: saatDiketuk,
      child: Container(
        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
        padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: TokenJarak.jarak8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(pk.pengenalUnit ?? 'Tanpa unit', style: teks.titleSmall),
                      TeksKode(pk.nomor, gaya: teks.bodySmall),
                    ],
                  ),
                  Text(
                    [?pk.namaPelanggan, pk.labelStatus, '${pk.baris.length} baris'].join(' | '),
                    style: teks.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: TokenJarak.jarak8),
            TeksUang(Uang.Dari(pk.totalDisetujui), gaya: teks.labelLarge),
            Icon(Icons.chevron_right, color: warna.teksSekunder),
          ],
        ),
      ),
    );
  }
}
