import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/BagianTagihan.dart';

/// Tombol "Struk per tamu" (K-14, BR-08.2) di layar selesai bayar dan riwayat. Hanya tampil bila tagihan penjualan
/// ini dibagi ke dua tamu atau lebih. Memilih satu tamu atau semua tamu; tiap tamu mendapat struk sendiri berisi
/// bagiannya. Data dari perangkat ini, jadi tetap bisa dicetak ulang kapan saja dan saat offline.
class TombolStrukTamu extends ConsumerStatefulWidget {
  const TombolStrukTamu({super.key, required this.uuidPenjualan, this.cetakUlang = false});

  final String uuidPenjualan;

  /// true di riwayat (struk bertanda "CETAK ULANG").
  final bool cetakUlang;

  @override
  ConsumerState<TombolStrukTamu> createState() => _TombolStrukTamuState();
}

class _TombolStrukTamuState extends ConsumerState<TombolStrukTamu> {
  BagianTagihan? _bagian;
  var _mencetak = false;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    BagianTagihan? bagian;
    try {
      bagian = await ref.read(penyediaLayananStruk).AmbilBagianTagihan(widget.uuidPenjualan);
    } on Object {
      // Tombol ini hanya tambahan: data tidak terbaca = tidak ditampilkan, struk utama tidak terganggu.
      bagian = null;
    }
    if (mounted) {
      setState(() => _bagian = bagian);
    }
  }

  Future<void> _Pilih(BagianTagihan bagian) async {
    final pilihan = await showDialog<int>(
      context: context,
      builder: (konteks) => SimpleDialog(
        title: const Text('Cetak struk per tamu'),
        children: [
          SimpleDialogOption(
            key: const ValueKey('StrukSemuaTamu'),
            onPressed: () => Navigator.of(konteks).pop(0),
            child: Text('Semua tamu (${bagian.tamu.length} struk)'),
          ),
          for (var i = 0; i < bagian.tamu.length; i++)
            SimpleDialogOption(
              key: ValueKey('StrukTamu${i + 1}'),
              onPressed: () => Navigator.of(konteks).pop(i + 1),
              child: Text('Tamu ${i + 1} | ${bagian.tamu[i].porsi.FormatRupiah()} | ${bagian.tamu[i].namaMetode}'),
            ),
        ],
      ),
    );
    if (pilihan == null || !mounted) {
      return;
    }
    setState(() => _mencetak = true);
    String? galat;
    try {
      galat = await ref
          .read(penyediaPrinter.notifier)
          .CetakStrukTamu(widget.uuidPenjualan, tamuKe: pilihan == 0 ? null : pilihan, cetakUlang: widget.cetakUlang);
    } on GalatKasir catch (g) {
      galat = g.pesan;
    }
    if (!mounted) {
      return;
    }
    setState(() => _mencetak = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(galat ?? (pilihan == 0 ? 'Struk semua tamu dicetak.' : 'Struk tamu $pilihan dicetak.'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bagian = _bagian;
    if (bagian == null) {
      return const SizedBox.shrink();
    }
    return SizedBox(
      height: TokenJarak.targetSentuh,
      child: OutlinedButton.icon(
        key: const ValueKey('TombolStrukTamu'),
        onPressed: _mencetak ? null : () => _Pilih(bagian),
        icon: const Icon(Icons.receipt_long_outlined),
        label: Text(_mencetak ? 'Mencetak…' : 'Struk per tamu'),
      ),
    );
  }
}
