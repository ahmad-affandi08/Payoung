import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';

/// Bagian struk di layar selesai bayar (POS-11, PRD v1.79): mencetak otomatis sekali saat tampil (bila printer diatur
/// dan cetak otomatis aktif), menampilkan hasilnya, dan tombol cetak manual. Cetak kedua dan seterusnya bertanda
/// "CETAK ULANG". Gagal cetak tidak membatalkan transaksi.
class BagianCetakStruk extends ConsumerStatefulWidget {
  const BagianCetakStruk({
    super.key,
    required this.uuidPenjualan,
    this.namaPelanggan,
    this.labelPoin,
    this.diAtasMerek = false,
  });

  final String uuidPenjualan;
  final String? namaPelanggan;

  /// F-16c bagian 4a: baris poin berlipat di struk.
  final String? labelPoin;

  /// D-66: layar selesai berlatar warna merek, jadi pesan & tombolnya memakai warna terang.
  final bool diAtasMerek;

  @override
  ConsumerState<BagianCetakStruk> createState() => _BagianCetakStrukState();
}

class _BagianCetakStrukState extends ConsumerState<BagianCetakStruk> {
  var _jumlahCetak = 0;
  var _mencetak = false;
  String? _galat;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _CetakOtomatis());
  }

  Future<void> _CetakOtomatis() async {
    if (!await ref.read(penyediaLayananStruk).CekCetakOtomatis() || !mounted) {
      return;
    }
    setState(() => _mencetak = true);
    final hasil = await ref
        .read(penyediaPrinter.notifier)
        .CetakSetelahBayar(widget.uuidPenjualan, namaPelanggan: widget.namaPelanggan, labelPoin: widget.labelPoin);
    if (hasil.dicetak || hasil.galat != null) {
      _Selesai(hasil.galat);
    } else if (mounted) {
      // Sudah dicetak otomatis sebelumnya (layar dibangun ulang): cetak berikutnya bertanda CETAK ULANG.
      setState(() {
        _mencetak = false;
        _jumlahCetak = 1;
      });
    }
  }

  Future<void> _Cetak() async {
    setState(() {
      _mencetak = true;
      _galat = null;
    });
    final galat = await ref
        .read(penyediaPrinter.notifier)
        .CetakPenjualan(
          widget.uuidPenjualan,
          cetakUlang: _jumlahCetak > 0,
          namaPelanggan: widget.namaPelanggan,
          labelPoin: widget.labelPoin,
        );
    _Selesai(galat);
  }

  /// v1.97: cadangan lewat printer sistem bila printer thermal bermasalah.
  Future<void> _CetakLewatSistem() async {
    setState(() {
      _mencetak = true;
      _galat = null;
    });
    final galat = await ref
        .read(penyediaPrinter.notifier)
        .CetakPenjualanLewatSistem(
          widget.uuidPenjualan,
          cetakUlang: _jumlahCetak > 0,
          namaPelanggan: widget.namaPelanggan,
          labelPoin: widget.labelPoin,
        );
    _Selesai(galat);
  }

  void _Selesai(String? galat) {
    if (!mounted) {
      return;
    }
    setState(() {
      _mencetak = false;
      _galat = galat;
      if (galat == null) {
        _jumlahCetak++;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final printer = ref.watch(penyediaPrinter);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final belumDiatur = printer.keadaan == KeadaanPrinter.BelumDiatur && printer.profil == null;

    final putih = warna.permukaan;
    final String pesan;
    Color? warnaPesan;
    if (_mencetak) {
      pesan = 'Mencetak struk…';
    } else if (_galat != null) {
      pesan = _galat!;
      // Teks galat tetap putih penuh agar kontras; statusnya ditandai ikon galat berwarna aksen (bukan teks aksen).
      warnaPesan = widget.diAtasMerek ? putih : warna.bahaya;
    } else if (belumDiatur) {
      pesan = 'Printer struk belum diatur. Atur di menu Pengaturan agar struk tercetak.';
      warnaPesan = widget.diAtasMerek ? putih.withValues(alpha: 0.86) : null;
    } else if (_jumlahCetak > 0) {
      pesan = 'Struk sudah dicetak.';
      warnaPesan = widget.diAtasMerek ? putih.withValues(alpha: 0.86) : warna.sukses;
    } else {
      pesan = 'Transaksi tersimpan dan dikirim otomatis.';
      warnaPesan = widget.diAtasMerek ? putih.withValues(alpha: 0.8) : null;
    }
    final gayaTombol = widget.diAtasMerek
        ? OutlinedButton.styleFrom(
            foregroundColor: putih,
            disabledForegroundColor: putih.withValues(alpha: 0.5),
            side: BorderSide(color: warna.aksen, width: 2),
          )
        : null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Di layar selesai berlatar merek, peringatan printer tampil dengan ikon di atas tombol (D-66).
        if (!(widget.diAtasMerek && belumDiatur))
          if (widget.diAtasMerek && _galat != null)
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.error_outline, size: TokenJarak.ikonKecil, color: warna.aksen),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Text(pesan, style: teks.bodySmall?.copyWith(color: warnaPesan)),
                ),
              ],
            )
          else
            Text(pesan, style: teks.bodySmall?.copyWith(color: warnaPesan)),
        if (!belumDiatur) ...[
          const SizedBox(height: TokenJarak.jarak8),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: OutlinedButton.icon(
              onPressed: _mencetak ? null : _Cetak,
              style: gayaTombol,
              icon: const Icon(Icons.print_outlined),
              label: Text(
                _galat != null
                    ? 'Coba cetak lagi'
                    : _jumlahCetak > 0
                    ? 'Cetak ulang struk'
                    : 'Cetak struk',
              ),
            ),
          ),
          if (_galat != null && printer.profil?.jenis != JenisTransport.CetakSistem) ...[
            const SizedBox(height: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton.icon(
                onPressed: _mencetak ? null : _CetakLewatSistem,
                style: gayaTombol,
                icon: const Icon(Icons.picture_as_pdf_outlined),
                label: const Text('Cetak lewat printer sistem / PDF'),
              ),
            ),
          ],
        ],
      ],
    );
  }
}
