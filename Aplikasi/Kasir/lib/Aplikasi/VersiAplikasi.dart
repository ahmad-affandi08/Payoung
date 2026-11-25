/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.11+25` = versi 1.0.11, build 25); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.11';
  static const int build = 25;

  /// Contoh `1.0.11+25`.
  static const String lengkap = '$versi+$build';
}
