import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/LayananPesananOnline.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// F-17: pesanan toko online outlet ini yang belum ditagihkan (perlu online), lalu muat ke keranjang kanal `Online`.
/// Pesanan yang sudah dibayar di muka membawa uang mukanya, sehingga panel Bayar otomatis mengisi baris Uang muka dan
/// kasir hanya menagih sisanya (biasanya nol).
///
/// BR-17.3 (v3.33): pesanan yang menunggu konfirmasi ikut tampil; staf bisa menerima/menolak (dengan alasan), mulai
/// proses, dan menandai siap langsung dari sini, tanpa membuka back-office. Pembeli dikabari lewat WhatsApp oleh server.
class LembarPesananOnline extends ConsumerStatefulWidget {
  const LembarPesananOnline({super.key, required this.kasir, required this.saatDimuat});

  /// Staf yang sedang masuk (pelaku ubah status).
  final StafLokal kasir;

  static const String judul = 'Pesanan toko online';

  /// Dipanggil setelah keranjang terisi (pindah ke layar Jual).
  final VoidCallback saatDimuat;

  @override
  ConsumerState<LembarPesananOnline> createState() => _LembarPesananOnlineState();
}

class _LembarPesananOnlineState extends ConsumerState<LembarPesananOnline> {
  HasilPesananOnline? _hasil;
  String? _galat;
  bool _sibuk = false;

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
      final hasil = await ref.read(penyediaLayananPesananOnline).AmbilAktif();
      if (mounted) {
        setState(() => _hasil = hasil);
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

  Future<void> _UbahStatus(PesananOnlinePos pesanan, String status) async {
    String? alasan;
    if (status == 'Ditolak') {
      alasan = await showDialog<String>(context: context, builder: (_) => const _DialogAlasanTolak());
      if (alasan == null) {
        return;
      }
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      await ref.read(penyediaLayananPesananOnline).UbahStatus(pesanan, status, widget.kasir, alasan: alasan);
      await _Muat();
    } on GalatKasir catch (galat) {
      // Status bisa sudah diubah staf lain (409): muat ulang daftar supaya kartu basi tidak tampil dengan tombol lama,
      // lalu tampilkan alasannya (pemuatan ulang menghapus galat sebelumnya).
      await _Muat();
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  Future<void> _Tagih(PesananOnlinePos pesanan) async {
    final hasil = _hasil;
    if (hasil == null) {
      return;
    }
    if (!ref.read(penyediaKeranjang).CekBebas) {
      setState(() => _galat = 'Keranjang masih berisi. Selesaikan, tahan, atau batalkan transaksi itu dulu.');
      return;
    }
    try {
      final katalog = await ref.read(penyediaKatalog.future);
      final k = await ref.read(penyediaKonteksPenjualan.future);
      final keranjang = ref.read(penyediaLayananPesananOnline).MuatKeKeranjang(pesanan, hasil, katalog, k);
      ref.read(penyediaKeranjang.notifier).Ganti(keranjang);
      widget.saatDimuat();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pesanan = _hasil?.pesanan ?? const <PesananOnlinePos>[];
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Pesanan toko online outlet ini: terima, siapkan, lalu tagih. Perlu koneksi internet.',
                  style: teks.bodySmall,
                ),
              ),
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: OutlinedButton.icon(
                  onPressed: _sibuk ? null : () => unawaited(_Muat()),
                  icon: const Icon(Icons.refresh),
                  label: Text(_sibuk ? 'Memuat…' : 'Muat ulang'),
                ),
              ),
            ],
          ),
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          if (!_sibuk && _galat == null && pesanan.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak12),
              child: Text('Belum ada pesanan toko online yang menunggu ditagihkan.', style: teks.bodyMedium),
            ),
          for (final p in pesanan)
            _KartuPesanan(
              pesanan: p,
              saatTagih: () => unawaited(_Tagih(p)),
              saatUbahStatus: LayananPesananOnline.CekBolehUbahStatus(widget.kasir) && !_sibuk
                  ? (status) => unawaited(_UbahStatus(p, status))
                  : null,
            ),
        ],
      ),
    );
  }
}

class _KartuPesanan extends StatelessWidget {
  const _KartuPesanan({required this.pesanan, required this.saatTagih, this.saatUbahStatus});

  final PesananOnlinePos pesanan;
  final VoidCallback saatTagih;

  /// Null = staf tanpa izin (tombol status tidak tampil).
  final void Function(String status)? saatUbahStatus;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final alasan = LayananPesananOnline.AlasanBelumBisaDitagih(pesanan);
    final sisa = Uang.Dari(pesanan.sisaUangMuka);
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak12),
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
          borderRadius: BorderRadius.circular(TokenJarak.jarak8),
        ),
        child: Padding(
          padding: const EdgeInsets.all(TokenJarak.jarak12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TeksKode(pesanan.nomor, gaya: teks.titleSmall),
              Text(
                [
                  pesanan.namaPelanggan,
                  pesanan.CekKirim ? 'dikirim' : 'ambil sendiri',
                  pesanan.status.toLowerCase(),
                ].join(' | '),
                style: teks.bodySmall,
              ),
              Text(
                '${pesanan.baris.length} barang | ${Uang.Dari(pesanan.total).FormatRupiah()}',
                style: teks.bodyMedium,
              ),
              if (pesanan.sudahDibayar)
                Text(
                  sisa.Bandingkan(Uang.Nol()) > 0
                      ? 'Sudah dibayar online | uang muka ${sisa.FormatRupiah()}'
                      : 'Sudah dibayar online | uang mukanya sudah terpakai',
                  style: teks.bodySmall,
                )
              else
                Text('Belum dibayar | tagih penuh di kasir', style: teks.bodySmall),
              if (alasan != null)
                Padding(
                  padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                  child: Text(alasan, style: TextStyle(color: warna.teksSekunder)),
                ),
              const SizedBox(height: TokenJarak.jarak8),
              Wrap(
                alignment: WrapAlignment.end,
                spacing: TokenJarak.jarak8,
                runSpacing: TokenJarak.jarak8,
                children: [
                  if (saatUbahStatus != null)
                    for (final l in LayananPesananOnline.AmbilLangkah(pesanan))
                      SizedBox(
                        height: TokenJarak.targetSentuh,
                        child: l.status == 'Ditolak'
                            ? TextButton(
                                key: ValueKey('Status-${pesanan.uuid}-${l.status}'),
                                onPressed: () => saatUbahStatus!(l.status),
                                child: Text(l.label),
                              )
                            : FilledButton(
                                key: ValueKey('Status-${pesanan.uuid}-${l.status}'),
                                onPressed: () => saatUbahStatus!(l.status),
                                child: Text(l.label),
                              ),
                      ),
                  if (pesanan.status != 'MenungguKonfirmasi')
                    SizedBox(
                      height: TokenJarak.targetSentuh,
                      child: OutlinedButton(
                        onPressed: alasan == null ? saatTagih : null,
                        child: Text('Tagih ${pesanan.nomor.split('-').last}'),
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Alasan menolak pesanan online (wajib; dikirim ke pembeli lewat status pesanannya).
class _DialogAlasanTolak extends StatefulWidget {
  const _DialogAlasanTolak();

  @override
  State<_DialogAlasanTolak> createState() => _DialogAlasanTolakState();
}

class _DialogAlasanTolakState extends State<_DialogAlasanTolak> {
  final _alasan = TextEditingController();

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Tolak pesanan'),
    content: TextField(
      key: const ValueKey('AlasanTolak'),
      controller: _alasan,
      autofocus: true,
      maxLength: 255,
      decoration: const InputDecoration(labelText: 'Alasan (misal: bahan habis)'),
      onChanged: (_) => setState(() {}),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(
        onPressed: _alasan.text.trim().length < 3 ? null : () => Navigator.of(context).pop(_alasan.text.trim()),
        child: const Text('Tolak pesanan'),
      ),
    ],
  );
}
