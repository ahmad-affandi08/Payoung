import 'package:klien_api/KlienApi.dart';

/// P-04: kuota transaksi penjualan bulan ini **sebagaimana terlihat perangkat ini**: hitungan server ditambah penjualan
/// perangkat yang belum tercakup hitungan itu (dibuat sejak konfigurasi dibaca, atau masih di outbox saat itu). Dengan
/// begitu layar jual terkunci tepat waktu walau kasir bekerja offline, tanpa menunggu pembacaan konfigurasi berikutnya.
///
/// Penegakannya lunak: yang dikunci hanya transaksi **baru**. Penjualan yang sudah terjadi tetap disimpan dan dikirim
/// (K33, D-115).
class KuotaTransaksiEfektif {
  const KuotaTransaksiEfektif({required this.batas, required this.terpakai});

  final int batas;
  final int terpakai;

  /// Mulai peringatan "hampir habis" (persen). Sama dengan `KuotaTransaksiTenant::AMBANG_HAMPIR_HABIS_PERSEN` di server.
  static const int ambangHampirHabisPersen = 80;

  /// Selisih WIB terhadap UTC: bulan kuota dihitung menurut tanggal WIB seperti di server.
  static const Duration selisihWib = Duration(hours: 7);

  int get sisa => terpakai >= batas ? 0 : batas - terpakai;

  bool get habis => terpakai >= batas;

  bool get hampirHabis => !habis && terpakai * 100 >= batas * ambangHampirHabisPersen;

  /// Bulan kalender WIB `TTTT-BB` dari [sekarang].
  static String Periode(DateTime sekarang) {
    final wib = sekarang.toUtc().add(selisihWib);
    return '${wib.year.toString().padLeft(4, '0')}-${wib.month.toString().padLeft(2, '0')}';
  }

  /// Kuota efektif, atau null bila tidak ada yang perlu ditegakkan: server lama tanpa data kuota, paket tanpa batas, atau
  /// data kuota milik bulan lain (bulan berganti sebelum konfigurasi dibaca ulang; jangan mengunci dengan angka basi).
  static KuotaTransaksiEfektif? Hitung({
    required KuotaTransaksiAplikasi? kuota,
    required int penjualanBelumTercakup,
    required DateTime sekarang,
  }) {
    if (kuota == null || kuota.batas == null || kuota.periode != Periode(sekarang)) {
      return null;
    }
    return KuotaTransaksiEfektif(batas: kuota.batas!, terpakai: kuota.terpakai + penjualanBelumTercakup);
  }
}
