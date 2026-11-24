/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.9+22` = versi 1.0.9, build 22); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.9';
  static const int build = 22;

  /// Contoh `1.0.9+22`.
  static const String lengkap = '$versi+$build';
}
