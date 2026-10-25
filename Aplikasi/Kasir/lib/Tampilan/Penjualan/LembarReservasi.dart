import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Sesi/StafLokal.dart';
import 'PanelKalenderBooking.dart';

/// F-07 mode service bagian 2: antrian reservasi hari ini (perlu online). "Layani" mencatat kedatangan pelanggan lalu
/// memuat layanan + staf + pelanggan ke keranjang; pembayaran di layar Jual menyelesaikan reservasi di server.
/// K-20: tab "Kalender & booking" menampilkan kalender per staf dan membuat booking baru dari kasir.
class LembarReservasi extends ConsumerStatefulWidget {
  const LembarReservasi({super.key, required this.kasir, required this.saatDimuat});

  static const String judul = 'Reservasi hari ini';

  final StafLokal kasir;

  /// Dipanggil setelah keranjang terisi (pindah ke layar Jual).
  final VoidCallback saatDimuat;

  /// Jam `HH.mm` menurut zona outlet.
  static String FormatJam(DateTime waktu, String zona) {
    final lokal = ZonaWaktuOutlet.KeWaktuOutlet(waktu, zona);
    return '${lokal.hour.toString().padLeft(2, '0')}.${lokal.minute.toString().padLeft(2, '0')}';
  }

  @override
  ConsumerState<LembarReservasi> createState() => _LembarReservasiState();
}

class _LembarReservasiState extends ConsumerState<LembarReservasi> {
  List<ReservasiPos>? _daftar;
  String? _galat;
  bool _sibuk = false;
  String? _uuidDiproses;
  bool _kalender = false;

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
      final daftar = await ref.read(penyediaLayananReservasi).Ambil();
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

  Future<void> _Layani(ReservasiPos reservasi) async {
    if (!ref.read(penyediaKeranjang).CekBebas) {
      setState(() => _galat = 'Keranjang masih berisi. Selesaikan, tahan, atau batalkan transaksi itu dulu.');
      return;
    }
    setState(() {
      _uuidDiproses = reservasi.uuid;
      _galat = null;
    });
    try {
      final layanan = ref.read(penyediaLayananReservasi);
      final katalog = await ref.read(penyediaKatalog.future);
      final k = await ref.read(penyediaKonteksPenjualan.future);
      // Periksa katalog dulu supaya kedatangan tidak tercatat bila layanan belum bisa dijual di perangkat ini.
      layanan.MuatKeKeranjang(reservasi, katalog, k);
      final hadir = await layanan.Hadir(reservasi, kasir: widget.kasir);
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.MuatKeKeranjang(hadir, katalog, k));
      widget.saatDimuat();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _uuidDiproses = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final zona = ref.watch(penyediaKonteksPenjualan).value?.zonaWaktu ?? ZonaWaktuOutlet.bawaan;
    final daftar = _daftar ?? const <ReservasiPos>[];
    final pilihan = SegmentedButton<bool>(
      key: const ValueKey('TabReservasi'),
      segments: const [
        ButtonSegment(value: false, label: Text('Antrian hari ini')),
        ButtonSegment(value: true, label: Text('Kalender & booking')),
      ],
      selected: {_kalender},
      onSelectionChanged: (nilai) {
        setState(() => _kalender = nilai.first);
        if (!_kalender) {
          unawaited(_Muat());
        }
      },
    );
    if (_kalender) {
      return Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            pilihan,
            const SizedBox(height: TokenJarak.jarak12),
            PanelKalenderBooking(kasir: widget.kasir),
          ],
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          pilihan,
          const SizedBox(height: TokenJarak.jarak12),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Reservasi outlet ini hari ini, urut jam. Perlu koneksi internet; pembayaran tetap bisa offline.',
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
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          if (_daftar != null && daftar.isEmpty)
            const KeadaanKosong(
              ikon: Icons.event_busy_outlined,
              ilustrasi: IlustrasiKosong.Kalender,
              ringkas: true,
              judul: 'Belum ada reservasi hari ini',
              keterangan: 'Reservasi pelanggan untuk hari ini akan muncul di sini.',
            ),
          for (final r in daftar)
            Padding(
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
                      Wrap(
                        spacing: TokenJarak.jarak8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            '${LembarReservasi.FormatJam(r.mulaiPada, zona)}–${LembarReservasi.FormatJam(r.selesaiPada, zona)}',
                            style: teks.titleSmall,
                          ),
                          TeksKode(r.nomor, gaya: teks.bodySmall),
                          Text(r.labelStatus, style: teks.labelMedium),
                        ],
                      ),
                      Text(r.namaPelanggan, style: teks.bodyLarge),
                      Text(
                        [r.namaLayanan, if (r.namaStaf != null) 'oleh ${r.namaStaf}'].join(' | '),
                        style: teks.bodyMedium,
                      ),
                      if (r.catatan != null && r.catatan!.isNotEmpty)
                        Text('Catatan: ${r.catatan}', style: teks.bodySmall),
                      if (r.BisaDilayani) ...[
                        const SizedBox(height: TokenJarak.jarak8),
                        Align(
                          alignment: Alignment.centerRight,
                          child: SizedBox(
                            height: TokenJarak.targetSentuh,
                            child: FilledButton(
                              onPressed: _uuidDiproses != null ? null : () => unawaited(_Layani(r)),
                              child: Text(
                                _uuidDiproses == r.uuid ? 'Memproses…' : 'Layani ${r.namaPelanggan.split(' ').first}',
                              ),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
