import 'package:flutter/material.dart';

/// Token warna semantik (PRD §17.6.3). **Satu-satunya sumber warna** untuk seluruh aplikasi Flutter.
///
/// Palet merek Payoung (D-61, Muted Teal & Apricot; sumber `Spesifikasi/Merek`). Untuk mengganti warna,
/// ubah nilainya **di sini** dan **di `Aplikasi/Web/resources/js/Gaya/Aplikasi.css`** (token `--color-*`,
/// nama sama dalam kebab-case, mis. `garisInput` = `--color-garis-input`). Test `SumberWarna_test.dart`
/// memastikan keduanya sama.
///
/// Hanya ada satu palet terang: tidak ada mode gelap di aplikasi mana pun, termasuk KDS (D-14).
/// Kode fitur hanya memakai nama token, tidak pernah `Color(0x...)` atau `Colors.*` langsung
/// (dijaga oleh `test/Token/SumberWarna_test.dart`).
@immutable
final class TokenWarna extends ThemeExtension<TokenWarna> {
  const TokenWarna({
    required this.latar,
    required this.permukaan,
    required this.garis,
    required this.garisInput,
    required this.teksUtama,
    required this.teksSekunder,
    required this.brand,
    required this.brandGelap,
    required this.sukses,
    required this.peringatan,
    required this.bahaya,
    required this.info,
    required this.aksen,
    required this.brandTint,
    required this.ubin,
  });

  static const TokenWarna bawaan = TokenWarna(
    latar: Color(0xFFEBF0EC),
    permukaan: Color(0xFFFFFFFF),
    garis: Color(0xFFD2DCD6),
    garisInput: Color(0xFF7A8F90),
    teksUtama: Color(0xFF1F3335),
    teksSekunder: Color(0xFF4F6567),
    brand: Color(0xFF3B5B5D),
    brandGelap: Color(0xFF22383A),
    sukses: Color(0xFF2E7D32),
    peringatan: Color(0xFF9A5B00),
    bahaya: Color(0xFFB3261E),
    info: Color(0xFF1F5FAD),
    aksen: Color(0xFFF4A261),
    brandTint: Color(0xFFE4ECE8),
    ubin: Color(0xFFDFE8E2),
  );

  final Color latar;
  final Color permukaan;
  final Color garis;
  final Color garisInput;
  final Color teksUtama;
  final Color teksSekunder;
  final Color brand;
  final Color brandGelap;
  final Color sukses;
  final Color peringatan;
  final Color bahaya;
  final Color info;

  /// Aksen Apricot (D-61) sebagai token sejak D-25. Dipakai untuk sorotan merek: situs pemasaran,
  /// penanda navigasi (D-62), pil Rel Ruang Kerja (D-66), tombol sekunder di atas permukaan merek
  /// (layar berhasil bayar), ikon/garis status, dan avatar kasir. **Bukan warna teks status** — teks
  /// status memakai [sukses]/[peringatan]/[bahaya] atau teks putih penuh di atas [brand] agar kontras
  /// WCAG AA terpenuhi. Dijaga `SumberWarna_test.dart` agar palet web dan Flutter tetap satu sumber.
  final Color aksen;

  /// Nada brand lembut untuk penanda aktif, lencana jumlah, dan latar pilihan. Nilai tetap (bukan opasitas
  /// `brand` 12%) supaya batas kontrol tetap terbaca di atas kanvas.
  final Color brandTint;

  /// Latar area gambar produk di ubin katalog. Berbeda dari [latar] supaya ubin tidak menyatu dengan halaman
  /// ketika foto produk belum ada.
  final Color ubin;

  /// Token warna dari tema terdekat. Gagal keras bila tema belum memasang [TokenWarna].
  static TokenWarna AmbilDari(BuildContext context) =>
      Theme.of(context).extension<TokenWarna>() ??
      (throw StateError('TokenWarna belum dipasang di ThemeData. Pakai BuatTema().'));

  @override
  TokenWarna copyWith({
    Color? latar,
    Color? permukaan,
    Color? garis,
    Color? garisInput,
    Color? teksUtama,
    Color? teksSekunder,
    Color? brand,
    Color? brandGelap,
    Color? sukses,
    Color? peringatan,
    Color? bahaya,
    Color? info,
    Color? aksen,
    Color? brandTint,
    Color? ubin,
  }) => TokenWarna(
    latar: latar ?? this.latar,
    permukaan: permukaan ?? this.permukaan,
    garis: garis ?? this.garis,
    garisInput: garisInput ?? this.garisInput,
    teksUtama: teksUtama ?? this.teksUtama,
    teksSekunder: teksSekunder ?? this.teksSekunder,
    brand: brand ?? this.brand,
    brandGelap: brandGelap ?? this.brandGelap,
    sukses: sukses ?? this.sukses,
    peringatan: peringatan ?? this.peringatan,
    bahaya: bahaya ?? this.bahaya,
    info: info ?? this.info,
    aksen: aksen ?? this.aksen,
    brandTint: brandTint ?? this.brandTint,
    ubin: ubin ?? this.ubin,
  );

  @override
  TokenWarna lerp(covariant TokenWarna? other, double t) {
    if (other == null) {
      return this;
    }
    return TokenWarna(
      latar: Color.lerp(latar, other.latar, t)!,
      permukaan: Color.lerp(permukaan, other.permukaan, t)!,
      garis: Color.lerp(garis, other.garis, t)!,
      garisInput: Color.lerp(garisInput, other.garisInput, t)!,
      teksUtama: Color.lerp(teksUtama, other.teksUtama, t)!,
      teksSekunder: Color.lerp(teksSekunder, other.teksSekunder, t)!,
      brand: Color.lerp(brand, other.brand, t)!,
      brandGelap: Color.lerp(brandGelap, other.brandGelap, t)!,
      sukses: Color.lerp(sukses, other.sukses, t)!,
      peringatan: Color.lerp(peringatan, other.peringatan, t)!,
      bahaya: Color.lerp(bahaya, other.bahaya, t)!,
      info: Color.lerp(info, other.info, t)!,
      aksen: Color.lerp(aksen, other.aksen, t)!,
      brandTint: Color.lerp(brandTint, other.brandTint, t)!,
      ubin: Color.lerp(ubin, other.ubin, t)!,
    );
  }
}
