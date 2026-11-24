/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.7+20` = versi 1.0.7, build 20); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.7';
  static const int build = 20;

  /// Contoh `1.0.7+20`.
  static const String lengkap = '$versi+$build';
}
