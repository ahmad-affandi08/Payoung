import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Jual/PanelLaundry.dart';

/// Laundry (§9.9) di aplikasi kasir: daftar cucian outlet (perlu online). Bawaan = cucian siap diambil; cari nomor nota,
/// nama, atau nomor HP untuk cucian yang masih diproses. Kasir memajukan status proses, menandai siap (pelanggan
/// dikabari WhatsApp), menandai diambil, atau mencetak ulang nota ber-QR.
class LembarCucian extends ConsumerStatefulWidget {
  const LembarCucian({super.key, required this.kasir});

  static const String judul = 'Cucian';

  final StafLokal kasir;

  static String LabelAksi(String status) => switch (status) {
    'Dicuci' => 'Mulai dicuci',
    'Dikeringkan' => 'Masuk pengeringan',
    'Disetrika' => 'Masuk setrika',
    'Siap' => 'Siap diambil',
    'Diambil' => 'Sudah diambil',
    _ => status,
  };

  @override
  ConsumerState<LembarCucian> createState() => _LembarCucianState();
}

class _LembarCucianState extends ConsumerState<LembarCucian> {
  final _kata = TextEditingController();
  List<TiketLaundryPos>? _daftar;
  String? _galat;
  String? _pesan;
  bool _sibuk = false;
  String? _uuidDiproses;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  @override
  void dispose() {
    _kata.dispose();
    super.dispose();
  }

  Future<void> _Muat() async {
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final daftar = await ref.read(penyediaLayananLaundry).Cari(kata: _kata.text);
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

  Future<void> _Ubah(TiketLaundryPos tiket, String status) async {
    setState(() {
      _uuidDiproses = tiket.uuid;
      _galat = null;
      _pesan = null;
    });
    try {
      final baru = await ref.read(penyediaLayananLaundry).UbahStatus(tiket, status, kasir: widget.kasir);
      if (mounted) {
        setState(() {
          final daftar = [...?_daftar];
          final i = daftar.indexWhere((t) => t.uuid == baru.uuid);
          if (baru.status == 'Diambil' && i >= 0) {
            daftar.removeAt(i);
          } else if (i >= 0) {
            daftar[i] = baru;
          }
          _daftar = daftar;
          _pesan = 'Cucian ${baru.nomor}: ${baru.labelStatus}.';
        });
      }
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

  Future<void> _Cetak(TiketLaundryPos tiket) async {
    final galat = await ref.read(penyediaPrinter.notifier).CetakDokumen((l) => l.CetakNotaLaundry(tiket));
    if (mounted) {
      setState(() => galat == null ? _pesan = 'Nota ${tiket.nomor} dicetak.' : _galat = galat);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final zona = ref.watch(penyediaKonteksPenjualan).value?.zonaWaktu ?? ZonaWaktuOutlet.bawaan;
    final daftar = _daftar ?? const <TiketLaundryPos>[];
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Tanpa kata cari: cucian yang siap diambil. Cari nomor nota, nama, atau nomor HP. Perlu koneksi internet.',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _kata,
                  textInputAction: TextInputAction.search,
                  onSubmitted: (_) => unawaited(_Muat()),
                  decoration: const InputDecoration(labelText: 'Cari cucian', border: OutlineInputBorder()),
                ),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: FilledButton(onPressed: _sibuk ? null : () => unawaited(_Muat()), child: const Text('Cari')),
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
          if (_pesan != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_pesan!, style: teks.bodyMedium),
            ),
          if (_daftar != null && daftar.isEmpty)
            KeadaanKosong(
              ikon: Icons.local_laundry_service_outlined,
              ilustrasi: _kata.text.trim().isEmpty ? IlustrasiKosong.Cucian : IlustrasiKosong.Cari,
              ringkas: true,
              judul: _kata.text.trim().isEmpty ? 'Belum ada cucian yang siap diambil' : 'Cucian tidak ditemukan',
              keterangan: _kata.text.trim().isEmpty ? 'Cucian yang selesai dicuci akan muncul di sini.' : null,
            ),
          for (final t in daftar)
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
                          TeksKode(t.nomor, gaya: teks.titleSmall),
                          Text(t.labelStatus, style: teks.labelMedium),
                          if (t.lewatEstimasi) Text('Lewat perkiraan', style: TextStyle(color: warna.bahaya)),
                        ],
                      ),
                      Text(t.namaPelanggan, style: teks.bodyLarge),
                      Text(
                        [
                          t.jenisLayanan,
                          if (t.berat != null) '${t.berat!.replaceAll(RegExp(r'\.?0+$'), '').replaceAll('.', ',')} kg',
                          for (final i in t.item) '${i.nama} ×${i.jumlah}',
                        ].join(' | '),
                        style: teks.bodyMedium,
                      ),
                      Text(
                        'Perkiraan selesai ${PanelLaundry.FormatWaktu(t.estimasiSelesaiPada, zona)}',
                        style: teks.bodySmall,
                      ),
                      const SizedBox(height: TokenJarak.jarak8),
                      Wrap(
                        spacing: TokenJarak.jarak8,
                        runSpacing: TokenJarak.jarak8,
                        alignment: WrapAlignment.end,
                        children: [
                          SizedBox(
                            height: TokenJarak.targetSentuh,
                            child: OutlinedButton(
                              onPressed: () => unawaited(_Cetak(t)),
                              child: const Text('Cetak nota'),
                            ),
                          ),
                          for (final s in [
                            if (t.statusBerikutnya.isNotEmpty &&
                                !['Siap', 'Diambil'].contains(t.statusBerikutnya.first))
                              t.statusBerikutnya.first,
                            if (t.statusBerikutnya.contains('Siap')) 'Siap',
                            if (t.statusBerikutnya.contains('Diambil')) 'Diambil',
                          ])
                            SizedBox(
                              height: TokenJarak.targetSentuh,
                              child: s == 'Siap' || s == 'Diambil'
                                  ? FilledButton(
                                      onPressed: _uuidDiproses != null ? null : () => unawaited(_Ubah(t, s)),
                                      child: Text(LembarCucian.LabelAksi(s)),
                                    )
                                  : OutlinedButton(
                                      onPressed: _uuidDiproses != null ? null : () => unawaited(_Ubah(t, s)),
                                      child: Text(LembarCucian.LabelAksi(s)),
                                    ),
                            ),
                        ],
                      ),
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
