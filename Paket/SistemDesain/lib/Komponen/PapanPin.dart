import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../Token/TokenWarna.dart';

/// Papan angka PIN 6 digit, dipakai aplikasi Kasir (masuk kasir) dan Owner (kunci aplikasi, OWN-01) (target sentuh ≥ 48dp, PRD §17.2). PIN hanya ada di memori widget sampai dikirim ke
/// `saatSelesai`, tidak pernah disimpan atau dicatat. Keyboard fisik (Windows, tablet berkeyboard) juga bisa dipakai:
/// angka baris atas & numpad mengisi, Backspace menghapus satu angka (audit kemudahan pakai #9).
class PapanPin extends StatefulWidget {
  const PapanPin({
    super.key,
    required this.saatSelesai,
    this.sibuk = false,
    this.pesanGalat,
    this.aktif = true,
    this.petunjuk,
    this.simpulFokus,
  });

  final Future<void> Function(String pin) saatSelesai;
  final bool sibuk;
  final String? pesanGalat;

  /// False = papan terkunci (mis. nama kasir belum dipilih); tombol nonaktif dan [petunjuk] tampil di bawah titik.
  final bool aktif;
  final String? petunjuk;

  /// Simpul fokus papan: induk memintanya setelah memilih kasir supaya keyboard fisik langsung mengisi PIN.
  final FocusNode? simpulFokus;

  @override
  State<PapanPin> createState() => _PapanPinState();
}

class _PapanPinState extends State<PapanPin> {
  String _pin = '';

  Future<void> _Tekan(String angka) async {
    if (widget.sibuk || !widget.aktif || _pin.length >= 6) {
      return;
    }
    setState(() => _pin += angka);
    if (_pin.length == 6) {
      final pin = _pin;
      setState(() => _pin = '');
      await widget.saatSelesai(pin);
    }
  }

  void _Hapus() => setState(() => _pin = _pin.isEmpty ? '' : _pin.substring(0, _pin.length - 1));

  static final Map<LogicalKeyboardKey, String> _numpad = {
    LogicalKeyboardKey.numpad0: '0',
    LogicalKeyboardKey.numpad1: '1',
    LogicalKeyboardKey.numpad2: '2',
    LogicalKeyboardKey.numpad3: '3',
    LogicalKeyboardKey.numpad4: '4',
    LogicalKeyboardKey.numpad5: '5',
    LogicalKeyboardKey.numpad6: '6',
    LogicalKeyboardKey.numpad7: '7',
    LogicalKeyboardKey.numpad8: '8',
    LogicalKeyboardKey.numpad9: '9',
  };

  KeyEventResult _SaatTombol(FocusNode _, KeyEvent event) {
    if (event is KeyUpEvent) {
      return KeyEventResult.ignored;
    }
    if (event.logicalKey == LogicalKeyboardKey.backspace) {
      if (!widget.sibuk) {
        _Hapus();
      }
      return KeyEventResult.handled;
    }
    final karakter = event.character;
    final angka =
        _numpad[event.logicalKey] ??
        (karakter != null && karakter.length == 1 && '0123456789'.contains(karakter) ? karakter : null);
    if (angka == null || event is KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }
    unawaited(_Tekan(angka));
    return KeyEventResult.handled;
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    Widget Tombol(String label, VoidCallback? aksi, {String? semantik, IconData? ikon}) => SizedBox(
      width: 88,
      height: 64,
      child: Semantics(
        label: semantik,
        button: true,
        excludeSemantics: semantik != null,
        child: OutlinedButton(
          style: OutlinedButton.styleFrom(
            backgroundColor: warna.latar,
            foregroundColor: warna.teksUtama,
            side: BorderSide(color: warna.garis),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          ),
          onPressed: widget.sibuk || !widget.aktif ? null : aksi,
          child: ikon != null
              ? Icon(ikon, size: 28)
              : Text(label, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w600)),
        ),
      ),
    );

    return Focus(
      focusNode: widget.simpulFokus,
      autofocus: true,
      onKeyEvent: _SaatTombol,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Semantics(
            label: 'PIN terisi ${_pin.length} dari 6 angka',
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (var i = 0; i < 6; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 120),
                    margin: const EdgeInsets.all(7),
                    width: 18,
                    height: 18,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: i < _pin.length ? warna.brand : warna.latar,
                      border: Border.all(color: i < _pin.length ? warna.brand : warna.garisInput, width: 2),
                    ),
                  ),
              ],
            ),
          ),
          SizedBox(
            height: 48,
            child: Center(
              child: widget.sibuk
                  ? const Text('Memeriksa PIN…')
                  : widget.pesanGalat == null
                  ? (widget.petunjuk == null
                        ? null
                        : Text(widget.petunjuk!, style: TextStyle(color: warna.teksSekunder)))
                  : Text(
                      widget.pesanGalat!,
                      textAlign: TextAlign.center,
                      style: TextStyle(color: warna.bahaya),
                    ),
            ),
          ),
          for (final baris in const [
            ['1', '2', '3'],
            ['4', '5', '6'],
            ['7', '8', '9'],
          ])
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  for (final angka in baris)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Tombol(angka, () => _Tekan(angka)),
                    ),
                ],
              ),
            ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(width: 96),
              Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: Tombol('0', () => _Tekan('0'))),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Tombol('', _Hapus, semantik: 'Hapus satu angka', ikon: Icons.backspace_outlined),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
