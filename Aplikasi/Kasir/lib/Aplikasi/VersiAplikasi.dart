/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.12+26` = versi 1.0.12, build 26); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.12';
  static const int build = 26;

  /// Contoh `1.0.12+26`.
  static const String lengkap = '$versi+$build';
}
