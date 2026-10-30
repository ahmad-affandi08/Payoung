import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import 'Aplikasi/AplikasiPemilik.dart';
import 'Aplikasi/KunciAplikasi.dart';
import 'Aplikasi/Lingkungan.dart';
import 'Aplikasi/Penyedia.dart';
import 'Aplikasi/TanpaCobaUlang.dart';
import 'Data/KameraNota.dart';
import 'Data/KunciPerangkat.dart';
import 'Data/NotifikasiPush.dart';
import 'Data/PenyediaMasukGoogle.dart';
import 'Data/PenyimpanSesi.dart';

/// Inisialisasi bersama semua flavor lalu menjalankan aplikasi (PRD §17.2.1).
Future<void> JalankanAplikasi(Lingkungan lingkungan) async {
  WidgetsFlutterBinding.ensureInitialized();
  final push = await NotifikasiPushFirebase.Buat();
  // D-35: server toko sendiri (edisi Lisensi) yang tersimpan saat masuk sebelumnya.
  final alamatServer = NormalkanAlamatServer(await PenyimpanSesiAman().Baca(PenyimpanSesi.kunciAlamatServer) ?? '');
  DaftarkanLisensiFont();
  runApp(
    ProviderScope(
      retry: TanpaCobaUlangOtomatis,
      overrides: [
        penyediaLingkungan.overrideWithValue(lingkungan),
        penyediaNotifikasiPush.overrideWithValue(push),
        penyediaMasukGoogle.overrideWithValue(PenyediaMasukGooglePlugin()),
        penyediaAlamatServerAwal.overrideWithValue(alamatServer),
        penyediaBiometrik.overrideWithValue(PenyediaBiometrikPerangkat()),
        penyediaKameraNota.overrideWithValue(KameraNotaPlatform()),
      ],
      child: AplikasiPemilik(lingkungan: lingkungan),
    ),
  );
}
