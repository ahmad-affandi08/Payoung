import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';

/// F-12 bagian 2: cari pre-order yang siap diambil (perlu online) lalu muat ke keranjang: barang dengan harga saat
/// dipesan, pelanggan, dan sisa uang muka yang otomatis menjadi pembayaran pertama di panel Bayar.
class LembarAmbilPreOrder extends ConsumerStatefulWidget {
  const LembarAmbilPreOrder({super.key, required this.saatDimuat});

  static const String judul = 'Ambil pre-order';

  /// Dipanggil setelah keranjang terisi (pindah ke layar Jual).
  final VoidCallback saatDimuat;

  @override
  ConsumerState<LembarAmbilPreOrder> createState() => _LembarAmbilPreOrderState();
}

class _LembarAmbilPreOrderState extends ConsumerState<LembarAmbilPreOrder> {
  final _kata = TextEditingController();
  HasilCariPesananPenjualan? _hasil;
  String? _galat;
  bool _sibuk = false;

  @override
  void dispose() {
    _kata.dispose();
    super.dispose();
  }

  Future<void> _Cari() async {
    if (_kata.text.trim().length < 3) {
      setState(() => _galat = 'Ketik minimal 3 huruf nomor pre-order, nama, atau nomor HP pelanggan.');
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final hasil = await ref.read(penyediaLayananPreOrder).Cari(_kata.text);
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

  Future<void> _Ambil(PesananPenjualanPos pesanan) async {
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
      final keranjang = ref.read(penyediaLayananPreOrder).MuatKeKeranjang(pesanan, hasil, katalog, k);
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
    final pesanan = _hasil?.pesanan ?? const <PesananPenjualanPos>[];
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Cari dengan nomor pre-order, nama, atau nomor HP pelanggan. Perlu koneksi internet.',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _kata,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => unawaited(_Cari()),
            decoration: const InputDecoration(labelText: 'Cari pre-order', border: OutlineInputBorder()),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: FilledButton(
              onPressed: _sibuk ? null : () => unawaited(_Cari()),
              child: Text(_sibuk ? 'Mencari…' : 'Cari'),
            ),
          ),
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          if (_hasil != null && pesanan.isEmpty)
            KeadaanKosong(
              ikon: Icons.search_off,
              ilustrasi: IlustrasiKosong.Cari,
              ringkas: true,
              judul: 'Tidak ada pre-order yang siap diambil',
              keterangan: 'Tidak ada hasil untuk "${_kata.text.trim()}".',
            ),
          for (final p in pesanan)
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
                      TeksKode(p.nomor, gaya: teks.titleSmall),
                      Text(
                        [
                          '${p.pelanggan?['Nama'] ?? '-'}',
                          'ambil ${p.tanggalAmbil.substring(8, 10)}/${p.tanggalAmbil.substring(5, 7)}',
                          if (p.status == 'Siap') 'siap diambil' else 'masih dibuat',
                        ].join(' | '),
                        style: teks.bodySmall,
                      ),
                      Text(
                        '${p.baris.length} barang | DP ${Uang.Dari(p.sisaUangMuka).FormatRupiah()}',
                        style: teks.bodyMedium,
                      ),
                      const SizedBox(height: TokenJarak.jarak8),
                      Align(
                        alignment: Alignment.centerRight,
                        child: SizedBox(
                          height: TokenJarak.targetSentuh,
                          child: OutlinedButton(
                            onPressed: () => unawaited(_Ambil(p)),
                            child: Text('Ambil ${p.nomor.split('-').last}'),
                          ),
                        ),
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
