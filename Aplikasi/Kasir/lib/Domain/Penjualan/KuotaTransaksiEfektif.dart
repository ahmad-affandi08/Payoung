import 'package:klien_api/KlienApi.dart';

/// P-04: kuota transaksi penjualan (per bulan atau per hari bisnis) **sebagaimana terlihat perangkat ini**: hitungan
/// server ditambah penjualan perangkat yang belum tercakup hitungan itu (dibuat sejak konfigurasi dibaca, atau masih di
/// outbox saat itu). Dengan begitu layar jual terkunci tepat waktu walau kasir bekerja offline, tanpa menunggu
/// pembacaan konfigurasi berikutnya.
///
/// Penegakannya lunak: yang dikunci hanya transaksi **baru**. Penjualan yang sudah terjadi tetap disimpan dan dikirim
/// (K33, D-115).
class KuotaTransaksiEfektif {
  const KuotaTransaksiEfektif({required this.batas, required this.terpakai, this.jangka = JangkaKuotaTransaksi.Bulan});

  final int batas;
  final int terpakai;
  final JangkaKuotaTransaksi jangka;

  /// Mulai peringatan "hampir habis" (persen). Sama dengan `KuotaTransaksiTenant::AMBANG_HAMPIR_HABIS_PERSEN` di server.
  static const int ambangHampirHabisPersen = 80;

  /// Selisih WIB terhadap UTC: bulan kuota dihitung menurut tanggal WIB seperti di server.
  static const Duration selisihWib = Duration(hours: 7);

  int get sisa => terpakai >= batas ? 0 : batas - terpakai;

  bool get habis => terpakai >= batas;

  bool get hampirHabis => !habis && terpakai * 100 >= batas * ambangHampirHabisPersen;

  /// "bulan ini" / "hari ini" untuk kalimat ke kasir.
  String get sebutan => jangka == JangkaKuotaTransaksi.Hari ? 'hari ini' : 'bulan ini';

  /// "per bulan" / "per hari".
  String get satuan => jangka == JangkaKuotaTransaksi.Hari ? 'per hari' : 'per bulan';

  /// Bulan kalender WIB `TTTT-BB` dari [sekarang].
  static String Periode(DateTime sekarang) {
    final wib = sekarang.toUtc().add(selisihWib);
    return '${wib.year.toString().padLeft(4, '0')}-${wib.month.toString().padLeft(2, '0')}';
  }

  /// Tanggal WIB `TTTT-BB-HH` dari [sekarang] (dipakai hanya bila server tidak mengirim saat hari berakhir).
  static String TanggalWib(DateTime sekarang) {
    final wib = sekarang.toUtc().add(selisihWib);
    return '${Periode(sekarang)}-${wib.day.toString().padLeft(2, '0')}';
  }

  /// Apakah angka server masih milik jangka yang sedang berjalan. Bulan: periodenya bulan WIB sekarang. Hari: hari
  /// bisnis outlet belum berakhir ([KuotaTransaksiAplikasi.berakhirPada]); tanpa saat berakhir, tanggal WIB yang dipakai.
  static bool MasihBerlaku(KuotaTransaksiAplikasi kuota, DateTime sekarang) {
    if (kuota.jangka == JangkaKuotaTransaksi.Bulan) {
      return kuota.periode == Periode(sekarang);
    }
    final berakhir = kuota.berakhirPada;
    return berakhir != null ? sekarang.toUtc().isBefore(berakhir) : kuota.periode == TanggalWib(sekarang);
  }

  /// Rentang `TanggalBisnis` lokal (`awal`, `akhir`, keduanya inklusif) yang tercakup hitungan [kuota]; dipakai untuk
  /// tidak menghitung penjualan tertunda milik bulan/hari lain.
  static (String awal, String akhir) RentangTanggalBisnis(KuotaTransaksiAplikasi kuota) =>
      kuota.jangka == JangkaKuotaTransaksi.Hari
      ? (kuota.periode, kuota.periode)
      : ('${kuota.periode}-01', '${kuota.periode}-31');

  /// Kuota efektif, atau null bila tidak ada yang perlu ditegakkan: server lama tanpa data kuota, paket tanpa batas, atau
  /// data kuota milik bulan/hari lain (jangka berganti sebelum konfigurasi dibaca ulang; jangan mengunci dengan angka
  /// basi).
  static KuotaTransaksiEfektif? Hitung({
    required KuotaTransaksiAplikasi? kuota,
    required int penjualanBelumTercakup,
    required DateTime sekarang,
  }) {
    if (kuota == null || kuota.batas == null || !MasihBerlaku(kuota, sekarang)) {
      return null;
    }
    return KuotaTransaksiEfektif(
      batas: kuota.batas!,
      terpakai: kuota.terpakai + penjualanBelumTercakup,
      jangka: kuota.jangka,
    );
  }

  /// Yang paling mengikat dari beberapa kuota (bulan dan hari): yang habis lebih dulu, lalu yang hampir habis, lalu yang
  /// sisanya paling sedikit. Null bila tidak ada satu pun yang ditegakkan.
  static KuotaTransaksiEfektif? PilihPalingMengikat(Iterable<KuotaTransaksiEfektif?> kuota) {
    KuotaTransaksiEfektif? terpilih;
    int Peringkat(KuotaTransaksiEfektif k) => k.habis ? 0 : (k.hampirHabis ? 1 : 2);
    for (final k in kuota.whereType<KuotaTransaksiEfektif>()) {
      if (terpilih == null) {
        terpilih = k;
        continue;
      }
      final a = Peringkat(k);
      final b = Peringkat(terpilih);
      if (a < b || (a == b && k.sisa < terpilih.sisa)) {
        terpilih = k;
      }
    }
    return terpilih;
  }
}
