import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../GalatKasir.dart';

/// Hasil cek gift card di kasir: saldo siap dipakai membayar, atau alasan kartu tidak bisa dipakai.
class KartuGiftCard {
  const KartuGiftCard({required this.kode, required this.saldo, required this.berlakuSampai, required this.alasan});

  /// Kode baku `XXXX-XXXX-XXXX` dari server; inilah yang dikirim sebagai `Referensi` pembayaran.
  final String kode;
  final Uang saldo;
  final String? berlakuSampai;

  /// Null = bisa dipakai; selain itu kalimat siap tampil (hangus, lewat masa berlaku, saldo habis).
  final String? alasan;

  bool get bisaDipakai => alasan == null;

  /// Empat karakter terakhir untuk label di panel, struk, dan galat (kode lengkap tidak ditampilkan ulang).
  String get samaran => 'GC-••••-••••-${kode.substring(kode.length - 4)}';
}

/// Gift card di aplikasi kasir (CRM-06, D-153): kartu dicek **online** sebelum dipakai membayar (saldonya bisa dipakai
/// perangkat lain, jadi tidak di-cache). Pemotongan saldo terjadi di server saat penjualan diterima; kasir hanya
/// membawa kode di `Referensi` pembayaran. Offline → `PerluOnline`.
class LayananGiftCard {
  LayananGiftCard({required this.klien});

  final KlienPos klien;

  Future<KartuGiftCard> Cek(String kode) async {
    final bersih = kode.trim();
    if (bersih.isEmpty) {
      throw const GalatKasir('KodeKosong', 'Ketik atau pindai kode gift card dulu.');
    }
    try {
      final hasil = await klien.CekGiftCard(bersih);
      return KartuGiftCard(
        kode: hasil.kode,
        saldo: Uang.Dari(hasil.saldo),
        berlakuSampai: hasil.berlakuSampai,
        alasan: hasil.bisaDipakai ? null : (hasil.alasan ?? 'Kartu ini tidak bisa dipakai.'),
      );
    } on GalatJaringan {
      throw const GalatKasir(
        'PerluOnline',
        'Gift card perlu dicek online. Coba lagi saat perangkat terhubung internet, atau bayar dengan metode lain.',
      );
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }
}
