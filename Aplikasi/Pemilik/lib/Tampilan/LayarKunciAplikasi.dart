import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/KunciAplikasi.dart';

/// Pengaturan kunci aplikasi Owner (OWN-01): pasang atau matikan PIN, biometrik, dan kunci sekarang.
class LayarKunciAplikasi extends ConsumerWidget {
  const LayarKunciAplikasi({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final kunci = ref.watch(penyediaKunci);
    final pengendali = ref.read(penyediaKunci.notifier);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Kunci aplikasi')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Pasang PIN supaya orang yang meminjam ponsel Anda tidak bisa melihat omzet atau menyetujui permintaan. '
            'Aplikasi terkunci lagi bila ditinggalkan lebih dari satu menit.',
            style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: 16),
          if (!kunci.pinAktif)
            FilledButton.icon(
              onPressed: () => unawaited(BukaFormPin(context, ref)),
              icon: const Icon(Icons.lock_outline),
              label: const Text('Pasang PIN'),
            )
          else ...[
            const ListTile(
              contentPadding: EdgeInsets.zero,
              leading: Icon(Icons.lock),
              title: Text('PIN aktif'),
              subtitle: Text('Aplikasi meminta PIN saat dibuka.'),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              secondary: const Icon(Icons.fingerprint),
              title: const Text('Buka dengan sidik jari atau wajah'),
              subtitle: Text(
                kunci.biometrikTersedia
                    ? 'PIN tetap bisa dipakai kapan saja.'
                    : 'Belum ada sidik jari atau wajah yang terdaftar di ponsel ini.',
              ),
              value: kunci.biometrikAktif,
              onChanged: kunci.biometrikTersedia ? (nyala) => unawaited(pengendali.AturBiometrik(nyala)) : null,
            ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: pengendali.KunciSekarang,
              icon: const Icon(Icons.lock_clock),
              label: const Text('Kunci sekarang'),
            ),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: () => unawaited(_Matikan(context, ref)),
              icon: Icon(Icons.lock_open, color: warna.bahaya),
              label: Text('Matikan PIN', style: TextStyle(color: warna.bahaya)),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _Matikan(BuildContext context, WidgetRef ref) async {
    final pin = await MintaPin(context, judul: 'Matikan PIN', petunjuk: 'Masukkan PIN saat ini.');
    if (pin == null) return;
    final berhasil = await ref.read(penyediaKunci.notifier).Nonaktifkan(pin);
    if (!berhasil && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('PIN salah. Kunci tidak dimatikan.')));
    }
  }
}

/// Meminta satu PIN 6 angka lewat halaman penuh (papan PIN selebar 288dp tidak muat di dalam dialog 360dp); null bila
/// dibatalkan.
Future<String?> MintaPin(BuildContext context, {required String judul, String? petunjuk}) =>
    Navigator.of(context).push<String>(
      MaterialPageRoute<String>(
        fullscreenDialog: true,
        builder: (konteks) => Scaffold(
          appBar: AppBar(title: Text(judul)),
          body: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: PapanPin(petunjuk: petunjuk, saatSelesai: (pin) async => Navigator.pop(konteks, pin)),
              ),
            ),
          ),
        ),
      ),
    );

/// Memasang PIN baru: dua kali ketik harus sama. Mengembalikan true bila terpasang.
Future<bool> BukaFormPin(BuildContext context, WidgetRef ref) async {
  final pertama = await MintaPin(context, judul: 'PIN baru', petunjuk: 'Pilih 6 angka yang mudah Anda ingat.');
  if (pertama == null || !context.mounted) return false;
  final kedua = await MintaPin(context, judul: 'Ulangi PIN', petunjuk: 'Ketik PIN yang sama sekali lagi.');
  if (kedua == null || !context.mounted) return false;
  if (kedua != pertama) {
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Kedua PIN tidak sama. Coba lagi.')));
    return false;
  }
  return ref.read(penyediaKunci.notifier).AturPin(pertama);
}

/// Kartu ajakan di Beranda untuk pemilik yang belum memasang PIN. "Nanti" menyembunyikannya selamanya di perangkat ini
/// (PIN tetap bisa dipasang dari menu akun).
class KartuAjakanKunci extends ConsumerWidget {
  const KartuAjakanKunci({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final kunci = ref.watch(penyediaKunci);
    if (kunci.tahap != TahapKunci.TidakAktif || kunci.tawaranDitolak) {
      return const SizedBox.shrink();
    }
    final warna = TokenWarna.AmbilDari(context);
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.lock_outline, color: warna.brand),
                const SizedBox(width: 8),
                Expanded(child: Text('Amankan aplikasi dengan PIN', style: Theme.of(context).textTheme.titleMedium)),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'Omzet dan persetujuan Anda tidak terbuka bagi orang yang meminjam ponsel.',
              style: TextStyle(color: warna.teksSekunder),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                FilledButton(onPressed: () => unawaited(BukaFormPin(context, ref)), child: const Text('Pasang PIN')),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: () => unawaited(ref.read(penyediaKunci.notifier).TolakTawaran()),
                  child: const Text('Nanti'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
