import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// D-301: kasir mengganti PIN miliknya dari aplikasi (perlu online). PIN lama diperiksa server dengan batas percobaan
/// yang sama dengan masuk PIN. Setelah berhasil data kasir disegarkan agar verifier PIN offline di perangkat ikut baru.
/// Dialog menutup dengan `true` bila PIN berhasil diganti.
class DialogUbahPin extends ConsumerStatefulWidget {
  const DialogUbahPin({super.key, required this.kasir});

  final StafLokal kasir;

  @override
  ConsumerState<DialogUbahPin> createState() => _DialogUbahPinState();
}

class _DialogUbahPinState extends ConsumerState<DialogUbahPin> {
  final _pinLama = TextEditingController();
  final _pinBaru = TextEditingController();
  final _ulangi = TextEditingController();
  bool _sibuk = false;
  String? _galat;

  @override
  void dispose() {
    _pinLama.dispose();
    _pinBaru.dispose();
    _ulangi.dispose();
    super.dispose();
  }

  /// Pemeriksaan di perangkat sebelum ke server: format, beda dari PIN lama, dan konfirmasi sama.
  String? _Periksa() {
    final sah = RegExp(r'^\d{6}$');
    if (!sah.hasMatch(_pinLama.text)) {
      return 'PIN lama harus 6 angka.';
    }
    if (!sah.hasMatch(_pinBaru.text)) {
      return 'PIN baru harus 6 angka.';
    }
    if (_pinBaru.text == _pinLama.text) {
      return 'PIN baru harus berbeda dari PIN lama.';
    }
    if (_ulangi.text != _pinBaru.text) {
      return 'Ulangi PIN baru belum sama.';
    }
    return null;
  }

  Future<void> _Simpan() async {
    final masalah = _Periksa();
    if (masalah != null) {
      setState(() => _galat = masalah);
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      await ref
          .read(penyediaKlienPos)
          .UbahPin(uuidPengguna: widget.kasir.uuid, pinLama: _pinLama.text, pinBaru: _pinBaru.text);
      // Verifier PIN offline di perangkat ini harus ikut baru, kalau tidak PIN baru ditolak saat offline.
      await ref.read(penyediaSesi.notifier).SegarkanData();
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on GalatApi catch (galat) {
      if (mounted) {
        setState(() {
          _sibuk = false;
          _galat = galat.pesan;
        });
      }
    } on GalatJaringan {
      if (mounted) {
        setState(() {
          _sibuk = false;
          _galat = 'Perlu tersambung ke internet untuk mengganti PIN. Coba lagi saat online.';
        });
      }
    }
  }

  Widget _Isian(Key kunci, TextEditingController pengendali, String label, {bool terakhir = false}) => TextField(
    key: kunci,
    controller: pengendali,
    enabled: !_sibuk,
    obscureText: true,
    keyboardType: TextInputType.number,
    inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
    textInputAction: terakhir ? TextInputAction.done : TextInputAction.next,
    onSubmitted: terakhir ? (_) => _Simpan() : null,
    decoration: InputDecoration(labelText: label, counterText: ''),
  );

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return AlertDialog(
      title: const Text('Ubah PIN'),
      content: SizedBox(
        width: 360,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'PIN ${widget.kasir.nama}: 6 angka, dipakai untuk masuk dan persetujuan.',
                style: tema.textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              _Isian(const ValueKey('PinLama'), _pinLama, 'PIN lama'),
              const SizedBox(height: 12),
              _Isian(const ValueKey('PinBaru'), _pinBaru, 'PIN baru'),
              const SizedBox(height: 12),
              _Isian(const ValueKey('PinUlangi'), _ulangi, 'Ulangi PIN baru', terakhir: true),
              if (_galat != null) ...[
                const SizedBox(height: 12),
                Text(
                  _galat!,
                  key: const ValueKey('GalatUbahPin'),
                  style: TextStyle(color: tema.colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _sibuk ? null : () => Navigator.of(context).pop(false), child: const Text('Batal')),
        FilledButton(
          key: const ValueKey('SimpanPin'),
          onPressed: _sibuk ? null : _Simpan,
          child: Text(_sibuk ? 'Menyimpan…' : 'Simpan PIN'),
        ),
      ],
    );
  }
}
