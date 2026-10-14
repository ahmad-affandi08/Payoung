import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Sesi/StafLokal.dart';
import 'Komponen/AvatarKasir.dart';
import 'Komponen/BingkaiLogin.dart';
import 'LayarAbsensi.dart';

/// F-06 langkah 1: pilih nama kasir lalu masukkan PIN (bisa tanpa internet).
class LayarPilihKasir extends ConsumerStatefulWidget {
  const LayarPilihKasir({super.key});

  @override
  ConsumerState<LayarPilihKasir> createState() => _LayarPilihKasirState();
}

class _LayarPilihKasirState extends ConsumerState<LayarPilihKasir> {
  StafLokal? _dipilih;
  final FocusNode _fokusPin = FocusNode(debugLabel: 'PapanPin');
  bool _sibuk = false;
  String? _galat;

  @override
  void dispose() {
    _fokusPin.dispose();
    super.dispose();
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
      // Data staf terbaru (PIN & izin bisa berubah di back-office sejak daftar ini dipilih).
      final segar = (await ref.read(penyediaStaf.future)).where((s) => s.uuid == staf.uuid).firstOrNull;
      if (segar == null) {
        throw GalatKasir(
          'StafTidakTerdaftar',
          '${staf.nama} tidak lagi terdaftar di perangkat ini. Perbarui data kasir.',
        );
      }
      await ref.read(penyediaSesi.notifier).Masuk(segar, pin, sebelumMasuk: _TawarkanAbsenMasuk);
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

  /// Audit kemudahan pakai #31: toko yang memakai data karyawan ditawari absen masuk sekali per hari tepat setelah
  /// PIN kasir benar (PIN tidak diminta dua kali). "Nanti" = langsung bekerja; absen tetap bisa dari tombol Absen.
  Future<void> _TawarkanAbsenMasuk(StafLokal kasir) async {
    // Langkah opsional: galat apa pun (izin kamera ditolak, basis data) tidak boleh menggagalkan masuknya kasir.
    try {
      final layanan = ref.read(penyediaLayananAbsensi);
      final pakaiKaryawan = (await ref.read(penyediaKaryawanPos.future)).isNotEmpty;
      if (!pakaiKaryawan || !await layanan.CekPerluTawaranMasuk(kasir) || !mounted) {
        return;
      }
      final absen = await showDialog<bool>(
        context: context,
        builder: (konteks) => AlertDialog(
          title: const Text('Absen masuk sekarang?'),
          content: Text('${kasir.nama} belum absen masuk hari ini.'),
          actions: [
            TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Nanti')),
            FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Absen masuk')),
          ],
        ),
      );
      if (absen != true) {
        return;
      }
      await layanan.Catat(kasir, await layanan.AmbilSwafoto());
    } on Object catch (galat) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              galat is GalatKasir ? galat.pesan : 'Absen masuk belum tercatat. Coba lagi lewat tombol Absen.',
            ),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final staf = ref.watch(penyediaStaf);
    final terpilih = _dipilih;

    return BingkaiLogin(
      aksi: [
        TextButton(
          onPressed: _sibuk ? null : () => ref.read(penyediaSesi.notifier).SegarkanData(),
          child: const Text('Perbarui data kasir'),
        ),
        // F-18: absen masuk/keluar staf tanpa membuka sesi kasir.
        OutlinedButton.icon(
          onPressed: _sibuk
              ? null
              : () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const LayarAbsensi())),
          icon: const Icon(Icons.badge_outlined),
          label: const Text('Absen masuk/keluar'),
        ),
      ],
      isi: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Masuk ke kasir', style: teks.headlineMedium),
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            'Pilih nama Anda, lalu masukkan PIN. Shift dibuka setelah Anda masuk.',
            style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak24),
          staf.when(
            loading: () => const Center(child: TandaMuat(ukuran: 48)),
            error: (galat, _) =>
                Text('Data kasir tidak bisa dimuat: $galat', style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
            data: (daftar) => daftar.isEmpty
                ? const KeadaanKosong(
                    ikon: Icons.person_off_outlined,
                    ilustrasi: IlustrasiKosong.Pelanggan,
                    judul: 'Belum ada kasir untuk outlet ini',
                    keterangan: 'Tambahkan pengguna di back-office, lalu ketuk "Perbarui data kasir".',
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      DropdownButtonFormField<StafLokal>(
                        key: const ValueKey('PilihKasir'),
                        // Selalu cocokkan dengan objek di daftar terbaru (data bisa dimuat ulang saat layar terbuka).
                        initialValue: daftar.where((s) => s.uuid == terpilih?.uuid).firstOrNull,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Nama kasir',
                          prefixIcon: Icon(Icons.person_outline),
                        ),
                        hint: const Text('Siapa yang bertugas?'),
                        items: [
                          for (final s in daftar)
                            DropdownMenuItem<StafLokal>(
                              value: s,
                              child: Row(
                                children: [
                                  AvatarKasir(nama: s.nama, ukuran: 28),
                                  const SizedBox(width: TokenJarak.jarak12),
                                  Flexible(child: Text(s.nama, overflow: TextOverflow.ellipsis)),
                                ],
                              ),
                            ),
                        ],
                        onChanged: _sibuk
                            ? null
                            : (nilai) {
                                setState(() {
                                  _dipilih = nilai;
                                  _galat = null;
                                });
                                // Setelah memilih nama, fokus pindah ke papan PIN agar keyboard fisik langsung bisa dipakai.
                                WidgetsBinding.instance.addPostFrameCallback((_) => _fokusPin.requestFocus());
                              },
                      ),
                      const SizedBox(height: TokenJarak.jarak16),
                      Text('PIN', style: teks.labelLarge, textAlign: TextAlign.center),
                      const SizedBox(height: TokenJarak.jarak4),
                      PapanPin(
                        // Kunci per kasir: PIN yang sedang diketik hilang saat kasir diganti.
                        key: ValueKey('Pin${terpilih?.uuid}'),
                        aktif: terpilih != null,
                        petunjuk: terpilih == null ? 'Pilih nama dulu' : null,
                        simpulFokus: _fokusPin,
                        saatSelesai: _Masuk,
                        sibuk: _sibuk,
                        pesanGalat: _galat,
                      ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
