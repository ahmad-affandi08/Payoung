import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/KunciAplikasi.dart';
import '../Aplikasi/Penyedia.dart';

/// Layar kunci Aplikasi Owner (OWN-01): PIN 6 angka, tombol biometrik bila diaktifkan, dan jalan keluar "Lupa PIN"
/// (keluar lalu masuk ulang dengan kata sandi atau Google). Lima kali salah memutus sesi.
class LayarKunci extends ConsumerStatefulWidget {
  const LayarKunci({super.key});

  @override
  ConsumerState<LayarKunci> createState() => _LayarKunciState();
}

class _LayarKunciState extends ConsumerState<LayarKunci> {
  bool _sudahMencobaBiometrik = false;

  @override
  void initState() {
    super.initState();
    // Biometrik ditawarkan otomatis sekali saat layar muncul; PIN tetap bisa diketik kapan saja.
    WidgetsBinding.instance.addPostFrameCallback((_) => _CobaBiometrik());
  }

  Future<void> _CobaBiometrik() async {
    final kunci = ref.read(penyediaKunci);
    if (_sudahMencobaBiometrik || !kunci.biometrikAktif || kunci.tahap != TahapKunci.Terkunci) return;
    _sudahMencobaBiometrik = true;
    await ref.read(penyediaKunci.notifier).BukaDenganBiometrik();
  }

  Future<void> _KonfirmasiLupa() async {
    final keluar = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: const Text('Lupa PIN?'),
        content: const Text(
          'Anda akan keluar dari aplikasi di ponsel ini. Masuk lagi dengan email dan kata sandi atau akun Google, lalu pasang PIN baru.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(konteks, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(konteks, true), child: const Text('Keluar')),
        ],
      ),
    );
    if (keluar == true) {
      await ref.read(penyediaSesi.notifier).Keluar();
    }
  }

  @override
  Widget build(BuildContext context) {
    final kunci = ref.watch(penyediaKunci);
    final sesi = ref.watch(penyediaSesi);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      backgroundColor: warna.latar,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const LogoMerek.ikon(tinggi: 56),
                const SizedBox(height: 16),
                Text('Aplikasi terkunci', style: teks.headlineSmall, textAlign: TextAlign.center),
                const SizedBox(height: 4),
                Text(
                  sesi.namaTenant == null ? 'Masukkan PIN Anda.' : 'Masukkan PIN untuk membuka ${sesi.namaTenant}.',
                  style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                PapanPin(
                  sibuk: kunci.sibuk,
                  pesanGalat: kunci.pesan,
                  saatSelesai: (pin) async {
                    await ref.read(penyediaKunci.notifier).Buka(pin);
                  },
                ),
                if (kunci.biometrikAktif)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: OutlinedButton.icon(
                      onPressed: () {
                        _sudahMencobaBiometrik = false;
                        unawaited(_CobaBiometrik());
                      },
                      icon: const Icon(Icons.fingerprint),
                      label: const Text('Buka dengan sidik jari atau wajah'),
                    ),
                  ),
                TextButton(onPressed: _KonfirmasiLupa, child: const Text('Lupa PIN?')),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
