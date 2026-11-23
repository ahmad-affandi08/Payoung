/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.5+18` = versi 1.0.5, build 18); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.5';
  static const int build = 18;

  /// Contoh `1.0.5+18`.
  static const String lengkap = '$versi+$build';
}
