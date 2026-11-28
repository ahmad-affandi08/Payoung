/// Versi aplikasi Kasir yang tampil di Pengaturan › Perangkat dan dikirim ke server (`X-Versi-Aplikasi`). Satu sumber
/// dengan `version:` di `pubspec.yaml` (`1.0.13+27` = versi 1.0.13, build 27); test `VersiAplikasi_test` menjaganya
/// supaya keduanya tidak menyimpang.
abstract final class VersiAplikasi {
  static const String versi = '1.0.13';
  static const int build = 27;

  /// Contoh `1.0.13+27`.
  static const String lengkap = '$versi+$build';
}
