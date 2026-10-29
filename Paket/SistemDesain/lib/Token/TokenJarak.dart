/// Token jarak, radius, dan ukuran sentuh (PRD §17.6.4, §17.2.7 prinsip 3). Kode tampilan memakai nama token ini,
/// bukan angka lepas, agar ritme kisi 8dp sama di semua layar.
abstract final class TokenJarak {
  /// Kelipatan 4 (4, 8, 12, 16, 24, 32). Kisi utama 8dp.
  static const double jarak4 = 4;
  static const double jarak8 = 8;
  static const double jarak12 = 12;
  static const double jarak16 = 16;
  static const double jarak24 = 24;
  static const double jarak32 = 32;

  /// Radius 6 untuk tombol, input, lencana; 8 untuk panel & dialog.
  static const double radiusKontrol = 6;
  static const double radiusPanel = 8;

  /// Bahasa bentuk modern Aplikasi Owner (D-231): kartu besar membulat, kartu sorotan, tombol, dan kapsul.
  static const double radiusKartu = 24;
  static const double radiusKartuUtama = 28;
  static const double radiusTombol = 16;
  static const double radiusKapsul = 999;

  /// Target sentuh minimum mode Nyaman.
  static const double targetSentuh = 48;

  /// Ukuran ikon yang diizinkan (16/20/24).
  static const double ikonKecil = 16;
  static const double ikonSedang = 20;
  static const double ikonBesar = 24;

  /// Tebal garis pemisah.
  static const double tebalGaris = 1;
}
