import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KonfigurasiAplikasi;
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'BingkaiPemilik.dart';
import 'LayarMasuk.dart';
import 'LayarPilihTenant.dart';
import 'LayarWajibPerbarui.dart';

/// Menentukan layar menurut sesi: masuk (+ 2FA) → pilih usaha → bingkai Owner. Di bawah versi minimal (P-10 PGL-18)
/// bingkai Owner diganti layar "Perbarui aplikasi"; konfigurasi yang belum/gagal dimuat tidak mengunci aplikasi.
class GerbangPemilik extends ConsumerWidget {
  const GerbangPemilik({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => switch (ref.watch(penyediaSesi).tahap) {
    TahapSesi.Memuat => const Scaffold(body: Center(child: TandaMuat(ukuran: 72))),
    TahapSesi.Keluar || TahapSesi.DuaFaktor => const LayarMasuk(),
    TahapSesi.PilihTenant => const LayarPilihTenant(),
    TahapSesi.Masuk => _GerbangVersi(ref.watch(penyediaKonfigurasiAplikasi).value),
  };
}

Widget _GerbangVersi(KonfigurasiAplikasi? konfigurasi) =>
    konfigurasi != null && konfigurasi.wajibPembaruan
    ? LayarWajibPerbarui(konfigurasi: konfigurasi)
    : const BingkaiPemilik();
