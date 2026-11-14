/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.2+15` = versi 1.0.2, build 15); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.2';
  static const int build = 15;

  /// Contoh `1.0.2+15`.
  static const String lengkap = '$versi+$build';
}
