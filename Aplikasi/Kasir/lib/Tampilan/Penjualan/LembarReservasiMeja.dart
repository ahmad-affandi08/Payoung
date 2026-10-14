import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Sesi/StafLokal.dart';
import 'LembarReservasi.dart';

/// FnB reservasi meja + uang muka (D-145): antrian reservasi meja hari ini (perlu online). "Tamu datang" mencatat
/// kedatangan (dan menempatkan tamu bila reservasi belum bermeja); "Tagih di keranjang" menempelkan reservasi ke
/// keranjang yang sedang berisi (pesanan meja atau belanja biasa) sehingga layar Bayar otomatis memotong uang
/// mukanya dan penjualan menyelesaikan reservasi di server.
class LembarReservasiMeja extends ConsumerStatefulWidget {
  const LembarReservasiMeja({super.key, required this.kasir, required this.saatDimuat});

  static const String judul = 'Reservasi meja';

  final StafLokal kasir;

  /// Dipanggil setelah reservasi menempel di keranjang (pindah ke layar Jual).
  final VoidCallback saatDimuat;

  @override
  ConsumerState<LembarReservasiMeja> createState() => _LembarReservasiMejaState();
}

class _LembarReservasiMejaState extends ConsumerState<LembarReservasiMeja> {
  AntrianReservasiMejaPos? _antrian;
  String? _galat;
  bool _sibuk = false;
  String? _uuidDiproses;

  /// Meja pilihan kasir untuk reservasi yang belum bermeja (kunci = Uuid reservasi).
  final Map<String, String> _mejaPilihan = {};

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
      final antrian = await ref.read(penyediaLayananReservasiMeja).Ambil();
      if (mounted) {
        setState(() => _antrian = antrian);
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

  Future<void> _Hadir(ReservasiMejaPos reservasi) async {
    setState(() {
      _uuidDiproses = reservasi.uuid;
      _galat = null;
    });
    try {
      await ref
          .read(penyediaLayananReservasiMeja)
          .Hadir(reservasi, kasir: widget.kasir, uuidMeja: _mejaPilihan[reservasi.uuid]);
      await _Muat();
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

  void _Tagih(ReservasiMejaPos reservasi) {
    setState(() => _galat = null);
    try {
      final antrian = _antrian;
      final layanan = ref.read(penyediaLayananReservasiMeja);
      final draf = layanan.PasangUangMuka(
        ref.read(penyediaKeranjang),
        keranjangEfektif: ref.read(penyediaKeranjangEfektif),
        reservasi: reservasi,
        uuidMetode: antrian?.uuidMetodeUangMuka,
        namaMetode: antrian?.namaMetodeUangMuka,
      );
      ref.read(penyediaKeranjang.notifier).Ganti(draf);
      widget.saatDimuat();
    } on GalatKasir catch (galat) {
      setState(() => _galat = galat.pesan);
    }
  }

  Widget _PilihMeja(ReservasiMejaPos reservasi) {
    final meja = [
      for (final m in ref.watch(penyediaMeja).value ?? const <BarisMeja>[])
        if (m.Kapasitas >= reservasi.jumlahTamu) m,
    ];
    return DropdownButtonFormField<String>(
      key: ValueKey('MejaReservasi-${reservasi.uuid}'),
      initialValue: _mejaPilihan[reservasi.uuid],
      decoration: InputDecoration(labelText: 'Meja untuk ${reservasi.jumlahTamu} tamu'),
      items: [
        for (final m in meja) DropdownMenuItem(value: m.Uuid, child: Text('Meja ${m.Nama} | ${m.Kapasitas} kursi')),
      ],
      onChanged: (nilai) => setState(() {
        if (nilai == null) {
          _mejaPilihan.remove(reservasi.uuid);
        } else {
          _mejaPilihan[reservasi.uuid] = nilai;
        }
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final zona = ref.watch(penyediaKonteksPenjualan).value?.zonaWaktu ?? ZonaWaktuOutlet.bawaan;
    final daftar = _antrian?.reservasi ?? const <ReservasiMejaPos>[];
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Reservasi meja outlet ini hari ini, urut jam. Isi keranjang (atau buka pesanan mejanya) lebih dulu, lalu '
                'pilih "Tagih di keranjang" supaya uang muka tamu otomatis dipotong. Perlu koneksi internet; pembayaran tetap bisa offline.',
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
        if (_antrian != null && daftar.isEmpty)
          const KeadaanKosong(
            ikon: Icons.event_busy_outlined,
            ilustrasi: IlustrasiKosong.Kalender,
            ringkas: true,
            judul: 'Belum ada reservasi meja hari ini',
            keterangan: 'Reservasi meja untuk hari ini akan muncul di sini.',
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
                    Text(r.namaPemesan, style: teks.bodyLarge),
                    Text(
                      [
                        r.namaMeja == null ? 'Meja belum ditentukan' : 'Meja ${r.namaMeja}',
                        '${r.jumlahTamu} tamu',
                        if (r.noHp.isNotEmpty) r.noHp,
                      ].join(' | '),
                      style: teks.bodyMedium,
                    ),
                    if (r.catatan != null && r.catatan!.isNotEmpty)
                      Text('Catatan: ${r.catatan}', style: teks.bodySmall),
                    if (Uang.Dari(r.uangMuka.dicatat).Bandingkan(Uang.Nol()) > 0)
                      Text(
                        'Uang muka ${Uang.Dari(r.uangMuka.dicatat).FormatRupiah()} | sisa ${Uang.Dari(r.uangMuka.sisa).FormatRupiah()}',
                        style: teks.bodyMedium,
                      ),
                    if (r.BisaDilayani) ...[
                      if (r.BelumDatang && r.uuidMeja == null) ...[
                        const SizedBox(height: TokenJarak.jarak8),
                        _PilihMeja(r),
                      ],
                      const SizedBox(height: TokenJarak.jarak8),
                      Wrap(
                        alignment: WrapAlignment.end,
                        spacing: TokenJarak.jarak8,
                        runSpacing: TokenJarak.jarak8,
                        children: [
                          if (r.BelumDatang)
                            SizedBox(
                              height: TokenJarak.targetSentuh,
                              child: OutlinedButton(
                                onPressed: _uuidDiproses != null || (r.uuidMeja == null && _mejaPilihan[r.uuid] == null)
                                    ? null
                                    : () => unawaited(_Hadir(r)),
                                child: Text(_uuidDiproses == r.uuid ? 'Memproses…' : 'Tamu datang'),
                              ),
                            ),
                          SizedBox(
                            height: TokenJarak.targetSentuh,
                            child: FilledButton(
                              onPressed: _uuidDiproses != null ? null : () => _Tagih(r),
                              child: const Text('Tagih di keranjang'),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}
