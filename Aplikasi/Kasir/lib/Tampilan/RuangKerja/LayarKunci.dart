import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Sesi/StafLokal.dart';
import 'JamRuangKerja.dart';

/// Layar kunci ruang kerja (PRD §17.2.7): nama outlet & jam, buka dengan PIN kasir yang sama atau ganti kasir. Juga
/// dipakai untuk "ganti kasir" dari bilah atas ([gantiKasir] = true, bisa dibatalkan). Shift tidak ditutup; isi area
/// kerja di bawahnya tetap utuh.
class LayarKunci extends ConsumerStatefulWidget {
  const LayarKunci({super.key, required this.kasir, this.gantiKasir = false});

  final StafLokal kasir;
  final bool gantiKasir;

  @override
  ConsumerState<LayarKunci> createState() => _LayarKunciState();
}

class _LayarKunciState extends ConsumerState<LayarKunci> {
  /// Staf yang PIN-nya sedang dimasukkan. Terkunci: bawaan kasir yang sama. Ganti kasir: dipilih dari daftar.
  StafLokal? _dipilih;
  late bool _pilihKasir = widget.gantiKasir;
  bool _sibuk = false;
  String? _galat;

  @override
  void initState() {
    super.initState();
    _dipilih = widget.gantiKasir ? null : widget.kasir;
  }

  Future<void> _Masuk(String pin) async {
    final staf = _dipilih;
    if (staf == null) {
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      // Pakai data staf terbaru (PIN & izin bisa berubah di back-office saat layar terkunci), bukan objek saat login.
      final segar = (await ref.read(penyediaStaf.future)).where((s) => s.uuid == staf.uuid).firstOrNull;
      if (segar == null) {
        throw GalatKasir(
          'StafTidakTerdaftar',
          '${staf.nama} tidak lagi terdaftar di perangkat ini. Minta pemilik memeriksa akunnya.',
        );
      }
      await ref.read(penyediaSesi.notifier).Masuk(segar, pin);
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

  void _TampilkanDaftar() => setState(() {
    _pilihKasir = true;
    _dipilih = null;
    _galat = null;
  });

  /// PIN kasir pilihan → daftar kasir; daftar kasir → PIN kasir sendiri (terkunci) atau batal (ganti kasir).
  void _Kembali() {
    if (_pilihKasir && _dipilih != null) {
      setState(() {
        _dipilih = null;
        _galat = null;
      });
      return;
    }
    if (widget.gantiKasir) {
      ref.read(penyediaSesi.notifier).BatalGantiKasir();
      return;
    }
    setState(() {
      _pilihKasir = false;
      _dipilih = widget.kasir;
      _galat = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final identitas = ref.watch(penyediaIdentitas).value;

    final Widget isi;
    if (_dipilih != null) {
      final kasirSama = _dipilih!.uuid == widget.kasir.uuid && !_pilihKasir;
      isi = Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(kasirSama ? 'Terkunci | ${_dipilih!.nama}' : 'PIN ${_dipilih!.nama}', style: teks.titleMedium),
          const SizedBox(height: TokenJarak.jarak4),
          Text(
            kasirSama ? 'Masukkan PIN untuk melanjutkan.' : 'Masukkan PIN untuk mulai bertugas di shift ini.',
            style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: TokenJarak.jarak8),
          PapanPin(saatSelesai: _Masuk, sibuk: _sibuk, pesanGalat: _galat),
          const SizedBox(height: TokenJarak.jarak8),
          if (kasirSama)
            TextButton(onPressed: _sibuk ? null : _TampilkanDaftar, child: const Text('Ganti kasir'))
          else
            TextButton(onPressed: _sibuk ? null : _Kembali, child: const Text('Kembali')),
        ],
      );
    } else {
      final staf = ref.watch(penyediaStaf);
      isi = Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('Siapa yang bertugas?', style: teks.titleMedium),
          const SizedBox(height: TokenJarak.jarak4),
          Text(
            'Shift tetap terbuka. Kasir berikutnya masuk dengan PIN-nya sendiri.',
            style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: TokenJarak.jarak16),
          staf.when(
            loading: () => const LinearProgressIndicator(),
            error: (galat, _) =>
                Text('Data kasir tidak bisa dimuat. Coba lagi.', style: TextStyle(color: warna.bahaya)),
            data: (daftar) => Wrap(
              spacing: TokenJarak.jarak12,
              runSpacing: TokenJarak.jarak12,
              alignment: WrapAlignment.center,
              children: [
                for (final s in daftar)
                  SizedBox(
                    width: 200,
                    height: 56,
                    child: OutlinedButton(
                      onPressed: () => setState(() {
                        _dipilih = s;
                        _galat = null;
                      }),
                      child: Text(s.nama, textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: TokenJarak.jarak16),
          TextButton(onPressed: _Kembali, child: Text(widget.gantiKasir ? 'Batal' : 'Kembali')),
        ],
      );
    }

    return Material(
      color: warna.latar,
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(TokenJarak.jarak24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const LogoMerek.ikon(tinggi: 40),
                  const SizedBox(height: TokenJarak.jarak12),
                  if (identitas != null && identitas.outlet.isNotEmpty)
                    Text(identitas.outlet, style: teks.titleMedium, textAlign: TextAlign.center),
                  JamRuangKerja(gaya: teks.displaySmall),
                  const SizedBox(height: TokenJarak.jarak24),
                  isi,
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
