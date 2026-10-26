import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/PenyediaSalesman.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../../Data/RepositoriSalesman.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Salesman/LayananSalesman.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatWaktu.dart';
import '../LayarRiwayat.dart';

/// Riwayat salesman: pesanan & kunjungan dari perangkat ini selama 7 hari (offline) dengan status kirim berteks
/// (Terkirim / Belum terkirim / Perlu tindakan + alasan dari server), dan kunjungan hari ini yang sudah tercatat di
/// kantor (online).
class BagianRiwayatSalesman extends ConsumerStatefulWidget {
  const BagianRiwayatSalesman({super.key, required this.staf, required this.tepi});

  final StafLokal staf;
  final double tepi;

  @override
  ConsumerState<BagianRiwayatSalesman> createState() => _BagianRiwayatSalesmanState();
}

class _BagianRiwayatSalesmanState extends ConsumerState<BagianRiwayatSalesman> {
  DaftarKunjunganSalesman? _server;
  String? _galatServer;
  bool _offline = false;
  bool _memuatServer = false;

  @override
  void initState() {
    super.initState();
    unawaited(_MuatServer());
  }

  Future<void> _MuatServer() async {
    setState(() {
      _memuatServer = true;
      _galatServer = null;
      _offline = false;
    });
    try {
      final hasil = await ref.read(penyediaLayananSalesman).AmbilKunjunganServer(widget.staf);
      if (mounted) {
        setState(() {
          _server = hasil;
          _memuatServer = false;
        });
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _memuatServer = false;
          _offline = galat.kode == 'PerluOnline';
          _galatServer = galat.pesan;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pesanan = ref.watch(penyediaRiwayatPesananSalesman(widget.staf.uuid));
    final kunjungan = ref.watch(penyediaRiwayatKunjunganSalesman(widget.staf.uuid));
    final butir = <_ButirRiwayat>[
      for (final p in pesanan.value ?? const <RiwayatPesananSalesman>[]) _ButirRiwayat.Pesanan(p),
      for (final k in kunjungan.value ?? const <RiwayatKunjunganSalesman>[]) _ButirRiwayat.Kunjungan(k),
    ]..sort((a, b) => b.waktu.compareTo(a.waktu));
    final memuat = (pesanan.isLoading && pesanan.value == null) || (kunjungan.isLoading && kunjungan.value == null);
    final server = _server;

    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 840),
        child: ListView(
          padding: EdgeInsets.all(widget.tepi),
          children: [
            Text('Di perangkat ini | $hariRiwayatSalesman hari terakhir', style: teks.titleMedium),
            const SizedBox(height: TokenJarak.jarak8),
            if (memuat) const LinearProgressIndicator(),
            if (pesanan.hasError || kunjungan.hasError)
              Text('Riwayat tidak bisa dimuat. Coba lagi.', style: TextStyle(color: warna.bahaya)),
            if (!memuat && butir.isEmpty)
              Text(
                'Belum ada kunjungan atau pesanan dari perangkat ini dalam $hariRiwayatSalesman hari terakhir.',
                style: teks.bodyMedium,
              ),
            if (butir.isNotEmpty)
              Material(
                color: warna.permukaan,
                shape: RoundedRectangleBorder(
                  side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                  borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
                ),
                clipBehavior: Clip.antiAlias,
                child: Column(
                  children: [
                    for (var i = 0; i < butir.length; i++) ...[
                      if (i > 0) Divider(height: TokenJarak.tebalGaris, color: warna.garis),
                      _BarisRiwayat(butir: butir[i]),
                    ],
                  ],
                ),
              ),
            const SizedBox(height: TokenJarak.jarak24),
            Row(
              children: [
                Expanded(child: Text('Tercatat di kantor hari ini', style: teks.titleMedium)),
                SizedBox(
                  height: TokenJarak.targetSentuh,
                  child: TextButton.icon(
                    onPressed: _memuatServer ? null : _MuatServer,
                    icon: const Icon(Icons.refresh),
                    label: const Text('Muat ulang'),
                  ),
                ),
              ],
            ),
            if (_memuatServer) const LinearProgressIndicator(),
            if (!_memuatServer && _galatServer != null)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    _offline ? Icons.wifi_off : Icons.error_outline,
                    size: TokenJarak.ikonSedang,
                    color: _offline ? warna.peringatan : warna.bahaya,
                  ),
                  const SizedBox(width: TokenJarak.jarak8),
                  Expanded(
                    child: Text(
                      _offline
                          ? 'Offline. Daftar dari kantor tampil saat online. Catatan di atas tetap tersimpan.'
                          : _galatServer!,
                      style: teks.bodyMedium,
                    ),
                  ),
                ],
              ),
            if (!_memuatServer && _galatServer == null && server != null && server.kunjungan.isEmpty)
              const KeadaanKosong(
                ikon: Icons.event_available_outlined,
                ilustrasi: IlustrasiKosong.Kalender,
                ringkas: true,
                judul: 'Belum ada kunjungan yang diterima kantor hari ini',
              ),
            if (!_memuatServer && _galatServer == null && server != null)
              for (final k in server.kunjungan) _BarisKunjunganServer(kunjungan: k),
          ],
        ),
      ),
    );
  }
}

/// Satu butir riwayat lokal: pesanan atau kunjungan.
class _ButirRiwayat {
  _ButirRiwayat.Pesanan(RiwayatPesananSalesman p)
    : waktu = p.baris.DibuatPada,
      judul = 'Pesanan | ${p.baris.NamaPelanggan}',
      rincian =
          '${p.baris.JumlahBaris} produk | perkiraan ${Uang.Dari(p.baris.PerkiraanTotal).FormatRupiah()}'
          '${p.baris.Catatan == null ? '' : ' | ${p.baris.Catatan}'}',
      ikon = Icons.receipt_long_outlined,
      status = p.status,
      pesanGalat = p.pesanGalat;

  _ButirRiwayat.Kunjungan(RiwayatKunjunganSalesman k)
    : waktu = k.baris.MasukPada,
      judul = 'Kunjungan | ${k.baris.NamaPelanggan}',
      rincian = [
        HasilKunjungan.Cari(k.baris.Hasil)?.label ?? k.baris.Hasil ?? '',
        if (k.baris.KeluarPada != null)
          '${FormatWaktu.FormatJam(k.baris.MasukPada)}–${FormatWaktu.FormatJam(k.baris.KeluarPada!)}',
        if (k.baris.Latitude == null) 'tanpa lokasi',
        ?k.baris.Catatan,
      ].join(' | '),
      ikon = Icons.directions_walk,
      status = k.status,
      pesanGalat = k.pesanGalat;

  final DateTime waktu;
  final String judul;
  final String rincian;
  final IconData ikon;
  final StatusSinkronPenjualan status;
  final String? pesanGalat;
}

class _BarisRiwayat extends StatelessWidget {
  const _BarisRiwayat({required this.butir});

  final _ButirRiwayat butir;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final status = LayarRiwayat.AmbilStatus(butir.status);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(butir.ikon, size: TokenJarak.ikonSedang, color: warna.teksSekunder),
          const SizedBox(width: TokenJarak.jarak12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(butir.judul, maxLines: 2, overflow: TextOverflow.ellipsis, style: teks.labelLarge),
                Text(
                  '${FormatWaktu.FormatTanggalJam(butir.waktu)} | ${butir.rincian}',
                  style: teks.bodySmall,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                ),
                Row(
                  children: [
                    Icon(
                      status.ikon,
                      size: TokenJarak.ikonKecil,
                      color: BilahStatus.AmbilWarnaNada(warna, status.nada),
                    ),
                    const SizedBox(width: TokenJarak.jarak4),
                    Text(status.teks, style: teks.bodySmall?.copyWith(color: warna.teksUtama)),
                  ],
                ),
                if (butir.status == StatusSinkronPenjualan.PerluTindakan && butir.pesanGalat != null)
                  Text(butir.pesanGalat!, style: teks.bodySmall?.copyWith(color: warna.bahaya)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _BarisKunjunganServer extends StatelessWidget {
  const _BarisKunjunganServer({required this.kunjungan});

  final KunjunganSalesmanPos kunjungan;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final k = kunjungan;
    return ListTile(
      minTileHeight: TokenJarak.targetSentuh,
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.cloud_done_outlined),
      title: Text(k.namaPelanggan, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        [
          if (k.masukPada != null) FormatWaktu.FormatJam(k.masukPada!),
          k.labelHasil,
          if (k.nomorPesananGrosir != null) 'Pesanan ${k.nomorPesananGrosir}',
          if (k.latitude == null) 'tanpa lokasi',
        ].join(' | '),
        style: teks.bodySmall,
      ),
    );
  }
}
