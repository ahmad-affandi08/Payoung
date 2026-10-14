import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KonfigurasiAplikasi;
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/KunciAplikasi.dart';
import '../Aplikasi/Penyedia.dart';
import 'BingkaiPemilik.dart';
import 'LayarKunci.dart';
import 'LayarMasuk.dart';
import 'LayarPilihTenant.dart';
import 'LayarWajibPerbarui.dart';

/// Menentukan layar menurut sesi: masuk (+ 2FA) → pilih usaha → kunci PIN (OWN-01) → bingkai Owner. Di bawah versi
/// minimal (P-10 PGL-18) bingkai Owner diganti layar "Perbarui aplikasi"; konfigurasi yang belum/gagal dimuat tidak
/// mengunci aplikasi. Kunci PIN menutup layar saat aplikasi dibuka dingin dan setelah di latar lebih dari semenit.
class GerbangPemilik extends ConsumerStatefulWidget {
  const GerbangPemilik({super.key});

  @override
  ConsumerState<GerbangPemilik> createState() => _GerbangPemilikState();
}

class _GerbangPemilikState extends ConsumerState<GerbangPemilik> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final kunci = ref.read(penyediaKunci.notifier);
    switch (state) {
      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
        kunci.CatatLatar();
      case AppLifecycleState.resumed:
        kunci.CatatDepan();
      case AppLifecycleState.inactive:
      case AppLifecycleState.detached:
        break;
    }
  }

  @override
  Widget build(BuildContext context) => switch (ref.watch(penyediaSesi).tahap) {
    TahapSesi.Memuat => const Scaffold(body: Center(child: TandaMuat(ukuran: 72))),
    TahapSesi.Keluar || TahapSesi.DuaFaktor => const LayarMasuk(),
    TahapSesi.PilihTenant => const LayarPilihTenant(),
    TahapSesi.Masuk => switch (ref.watch(penyediaKunci).tahap) {
      TahapKunci.Memuat => const Scaffold(body: Center(child: TandaMuat(ukuran: 72))),
      TahapKunci.Terkunci => const LayarKunci(),
      TahapKunci.TidakAktif || TahapKunci.Terbuka => _GerbangVersi(ref.watch(penyediaKonfigurasiAplikasi).value),
    },
  };
}

Widget _GerbangVersi(KonfigurasiAplikasi? konfigurasi) => konfigurasi != null && konfigurasi.wajibPembaruan
    ? LayarWajibPerbarui(konfigurasi: konfigurasi)
    : const BingkaiPemilik();
