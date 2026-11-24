/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.8+21` = versi 1.0.8, build 21); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.8';
  static const int build = 21;

  /// Contoh `1.0.8+21`.
  static const String lengkap = '$versi+$build';
}
