/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.3+16` = versi 1.0.3, build 16); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.3';
  static const int build = 16;

  /// Contoh `1.0.3+16`.
  static const String lengkap = '$versi+$build';
}
