/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.4+17` = versi 1.0.4, build 17); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.4';
  static const int build = 17;

  /// Contoh `1.0.4+17`.
  static const String lengkap = '$versi+$build';
}
