import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Data/LayarPelanggan/ModulQr.dart';

/// Kode QR dari teks (QRIS dinamis, v2.05) digambar langsung di perangkat. Modul memakai token `teksUtama` di atas
/// `permukaan` dengan zona tenang 4 modul agar mudah dipindai kamera ponsel/aplikasi bank.
class GambarQr extends StatefulWidget {
  const GambarQr({super.key, required this.data, this.ukuran = 240, required this.label});

  final String data;
  final double ukuran;

  /// Label pembaca layar (misal "Kode QRIS Rp 25.000").
  final String label;

  @override
  State<GambarQr> createState() => _KeadaanGambarQr();
}

class _KeadaanGambarQr extends State<GambarQr> {
  // Modul disusun sekali per teks: dialog QRIS membangun ulang tiap detik (hitung mundur), dan menyusun ulang QR di
  // tiap bangun ulang memboroskan CPU perangkat murah serta membuat `shouldRepaint` selalu benar.
  late List<List<bool>> _modul = SusunModulQr(widget.data);

  @override
  void didUpdateWidget(GambarQr lama) {
    super.didUpdateWidget(lama);
    if (lama.data != widget.data) {
      _modul = SusunModulQr(widget.data);
    }
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return Semantics(
      label: widget.label,
      image: true,
      child: Container(
        color: warna.permukaan,
        width: widget.ukuran,
        height: widget.ukuran,
        child: CustomPaint(
          painter: _PelukisQr(modul: _modul, warnaModul: warna.teksUtama),
        ),
      ),
    );
  }
}

class _PelukisQr extends CustomPainter {
  _PelukisQr({required this.modul, required this.warnaModul});

  final List<List<bool>> modul;
  final Color warnaModul;

  static const int zonaTenang = 4;

  @override
  void paint(Canvas canvas, Size size) {
    final jumlah = modul.length + zonaTenang * 2;
    final sisi = size.shortestSide / jumlah;
    final kuas = Paint()..color = warnaModul;
    for (var r = 0; r < modul.length; r++) {
      for (var c = 0; c < modul[r].length; c++) {
        if (modul[r][c]) {
          // Dilebarkan sedikit agar tidak ada garis tipis antar modul karena pembulatan piksel.
          canvas.drawRect(
            Rect.fromLTWH((c + zonaTenang) * sisi, (r + zonaTenang) * sisi, sisi + 0.5, sisi + 0.5),
            kuas,
          );
        }
      }
    }
  }

  @override
  bool shouldRepaint(_PelukisQr lama) => lama.modul != modul || lama.warnaModul != warnaModul;
}
