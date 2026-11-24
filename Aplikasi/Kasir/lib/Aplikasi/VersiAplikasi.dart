/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.10+24` = versi 1.0.10, build 24); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.10';
  static const int build = 24;

  /// Contoh `1.0.10+24`.
  static const String lengkap = '$versi+$build';
}
