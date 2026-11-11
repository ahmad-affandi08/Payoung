import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// Memuat font merek ke perender test supaya golden menampilkan teks sungguhan, bukan kotak pengganti.
///
/// `flutter test` tidak memuat font dari `pubspec.yaml`, jadi tanpa ini setiap golden hanya merekam tata letak.
/// Nama keluarganya harus persis seperti yang dipakai `ThemeData(fontFamily:, package:)`, yaitu berawalan
/// `packages/<paket>/`.
Future<void> MuatFontMerek() async {
  TestWidgetsFlutterBinding.ensureInitialized();
  const berkas = {
    'Lato': ['Lato-Regular.ttf', 'Lato-Bold.ttf', 'Lato-Italic.ttf'],
  };
  for (final keluarga in berkas.entries) {
    final pemuat = FontLoader('packages/sistem_desain/${keluarga.key}');
    for (final nama in keluarga.value) {
      pemuat.addFont(rootBundle.load('packages/sistem_desain/assets/fonts/$nama').then((b) => b.buffer.asByteData()));
    }
    await pemuat.load();
  }
  // Ikon Material ikut dimuat supaya golden menampilkan ikon, bukan kotak kosong. Berkasnya disediakan bundel
  // aset test karena `uses-material-design: true`.
  final ikon = FontLoader('MaterialIcons')
    ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf').then((b) => b.buffer.asByteData()));
  await ikon.load();
}
