/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.6+19` = versi 1.0.6, build 19); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.6';
  static const int build = 19;

  /// Contoh `1.0.6+19`.
  static const String lengkap = '$versi+$build';
}
