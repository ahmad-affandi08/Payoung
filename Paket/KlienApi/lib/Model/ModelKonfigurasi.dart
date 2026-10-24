import 'UraiJson.dart';

/// Kunci flag fitur yang dihormati aplikasi (P-10 PGL-18). Flag `Global` bernilai mati = kill switch yang mematikan fitur
/// itu di semua tenant tanpa menunggu review store; kunci tanpa aturan dianggap hidup. Format kunci D-06
/// (huruf kecil, kebab-case, titik). Pembacaan: `KonfigurasiAplikasi.CekFlag`.
abstract final class KunciFlagFitur {
  /// Aplikasi Kasir: kasir mengubah harga satu baris (`pos.harga.timpa`).
  static const String kasirHargaManual = 'kasir.harga-manual';

  /// Aplikasi Kasir: retur tanpa struk (K28).
  static const String kasirReturTanpaStruk = 'kasir.retur-tanpa-struk';

  /// Aplikasi Kasir: pemindai barcode kamera di layar Jual (D-130).
  static const String kasirPindaiKamera = 'kasir.pindai-kamera';

  /// Aplikasi Owner: kartu & layar insight mingguan (OWN-11).
  static const String pemilikInsight = 'pemilik.insight';

  /// Aplikasi Owner: kartu & layar pantau karyawan (OWN-10).
  static const String pemilikKaryawan = 'pemilik.karyawan';

  /// Aplikasi Owner: persetujuan PO dan penyesuaian stok jarak jauh (OWN-04).
  static const String pemilikPersetujuanDokumen = 'pemilik.persetujuan-dokumen';
}

/// Isi `GET /api/pos/v1/konfigurasi-aplikasi` (dan `GET /api/pemilik/v1/konfigurasi-aplikasi`, bentuk sama) yang dipakai aplikasi (§14.6, P-10): versi terbaru & minimal untuk
/// perangkat ini, tautan unduh, catatan rilis ("Yang baru"), flag fitur tenant, dan pengumuman platform (v3.45).
class KonfigurasiAplikasi {
  const KonfigurasiAplikasi({
    required this.versiSaatIni,
    required this.versiTerbaru,
    required this.versiMinimal,
    required this.tautanUnduh,
    required this.catatanRilis,
    required this.adaPembaruan,
    required this.wajibPembaruan,
    required this.flagFitur,
    this.pengumuman = const [],
    this.persetujuanJarakJauh,
    this.logDiminta = false,
    this.sha256Unduhan,
    this.hanyaPerusahaan = false,
    this.sektor = const [],
    this.kuotaTransaksi,
    this.kuotaTransaksiHarian,
  });

  final String? versiSaatIni;
  final String? versiTerbaru;
  final String? versiMinimal;
  final String? tautanUnduh;

  /// POS-23: SHA-256 (hex 64) berkas di [tautanUnduh], dipakai pembaruan otomatis Windows untuk memeriksa unduhan sebelum
  /// dijalankan. Null = server lama / rilis tanpa checksum → pembaruan manual dari tautan.
  final String? sha256Unduhan;
  final String? catatanRilis;
  final bool adaPembaruan;

  /// Di bawah versi minimal: outbox tertunda tetap dikirim, layar jual dikunci sampai aplikasi diperbarui.
  final bool wajibPembaruan;

  /// Kunci → hidup/mati. Kunci tanpa aturan tidak dikirim (dianggap hidup).
  final Map<String, bool> flagFitur;

  /// P-10 PGL-19: banner pengumuman/pemeliharaan untuk perangkat ini, urut prioritas dari server (Penting dulu).
  /// Server lama tidak mengirimnya → kosong.
  final List<PengumumanAplikasi> pengumuman;

  /// Fitur paket `persetujuan.jarak-jauh` aktif untuk tenant ini sekarang. Null = server lama yang belum mengirimnya
  /// (jangan mengubah keadaan lokal).
  final bool? persetujuanJarakJauh;

  /// PGL-16 bagian 2: dukungan Payoung meminta perangkat ini mengunggah log lokalnya (`POST perangkat/log`). Server lama
  /// tanpa kunci = false.
  final bool logDiminta;

  /// D-183: usaha tanpa kasir (semua sektor `BIZ-`): Aplikasi Owner menyembunyikan tab Shift dan Perangkat. Server lama
  /// tanpa kunci = false (semua tab tampil).
  final bool hanyaPerusahaan;

  /// D-183: kode sektor usaha tenant (gabungan semua outlet). Kosong = belum diketahui atau server lama (semua fitur
  /// tampil).
  final List<String> sektor;

  /// D-183: sektor yang mengelola stok barang (menu Stok di Kasir, mode Stok di Owner). Jasa umum dan usaha jasa
  /// profesional/kontraktor tidak.
  static const List<String> sektorBerstok = [
    'FNB',
    'RTL',
    'WHS',
    'SVC-SLN',
    'SVC-LDR',
    'SVC-WRK',
    'SVC-FIT',
    'SVC-CWS',
    'BIZ-MFG',
  ];

  /// Awalan [awalan] cocok per segmen (`SVC` mencakup `SVC-WRK`); sektor belum diketahui = cocok (fail-open).
  bool CekSesuaiSektor(List<String> awalan) {
    if (sektor.isEmpty) {
      return true;
    }
    return awalan.any((a) => sektor.any((kode) => kode == a || kode.startsWith('$a-')));
  }

  /// Usaha ini mengelola stok barang (sektor berstok), atau sektornya belum diketahui.
  bool get mengelolaStok => CekSesuaiSektor(sektorBerstok);

  /// P-04: kuota transaksi penjualan bulan ini menurut server (`Kuota.Transaksi`). Null = server lama yang belum
  /// mengirimnya; aplikasi tidak menguncinya.
  final KuotaTransaksiAplikasi? kuotaTransaksi;

  /// P-04: kuota transaksi per hari bisnis outlet perangkat (`Kuota.TransaksiHarian`). Null = server lama.
  final KuotaTransaksiAplikasi? kuotaTransaksiHarian;

  /// Flag dengan kunci [kunci]; tanpa aturan = [bawaan].
  bool CekFlag(String kunci, {bool bawaan = true}) => flagFitur[kunci] ?? bawaan;

  static KonfigurasiAplikasi DariJson(Map<String, Object?> json) {
    final aplikasi = UraiJson.AmbilPeta(json['Aplikasi']);
    final flag = <String, bool>{};
    UraiJson.AmbilPeta(json['FlagFitur']).forEach((kunci, nilai) {
      if (nilai is bool) {
        flag[kunci] = nilai;
      }
    });
    final jarakJauh = UraiJson.AmbilPeta(json['FiturPaket'])['PersetujuanJarakJauh'];
    return KonfigurasiAplikasi(
      versiSaatIni: UraiJson.AmbilTeksAtauNull(aplikasi['VersiSaatIni']),
      versiTerbaru: UraiJson.AmbilTeksAtauNull(aplikasi['VersiTerbaru']),
      versiMinimal: UraiJson.AmbilTeksAtauNull(aplikasi['VersiMinimal']),
      tautanUnduh: UraiJson.AmbilTeksAtauNull(aplikasi['TautanUnduh']),
      sha256Unduhan: UraiJson.AmbilTeksAtauNull(aplikasi['Sha256Unduhan']),
      catatanRilis: UraiJson.AmbilTeksAtauNull(aplikasi['CatatanRilis']),
      adaPembaruan: UraiJson.AmbilBenar(aplikasi['AdaPembaruan']),
      wajibPembaruan: UraiJson.AmbilBenar(aplikasi['WajibPembaruan']),
      flagFitur: Map.unmodifiable(flag),
      pengumuman: List.unmodifiable(
        UraiJson.AmbilDaftarPeta(json['Pengumuman'])
            .map(PengumumanAplikasi.DariJson)
            .where((p) => p.uuid.isNotEmpty && p.judul.isNotEmpty),
      ),
      persetujuanJarakJauh: jarakJauh is bool ? jarakJauh : null,
      logDiminta: UraiJson.AmbilBenar(UraiJson.AmbilPeta(json['PermintaanLog'])['Diminta']),
      hanyaPerusahaan: UraiJson.AmbilBenar(json['HanyaPerusahaan']),
      sektor: List.unmodifiable(UraiJson.AmbilDaftarTeks(json['Sektor']).where((k) => k.isNotEmpty)),
      kuotaTransaksi: KuotaTransaksiAplikasi.DariJsonAtauNull(UraiJson.AmbilPeta(json['Kuota'])['Transaksi']),
      kuotaTransaksiHarian: KuotaTransaksiAplikasi.DariJsonAtauNull(
        UraiJson.AmbilPeta(json['Kuota'])['TransaksiHarian'],
        jangkaBawaan: JangkaKuotaTransaksi.Hari,
      ),
    );
  }
}

/// Keadaan kuota transaksi penjualan (P-04). Nilai tak dikenal dari server yang lebih baru diperlakukan sebagai
/// [Normal].
enum StatusKuotaTransaksi { TanpaBatas, Normal, HampirHabis, Habis }

/// Jangka kuota transaksi: per bulan kalender (WIB) atau per hari bisnis outlet. Nilai tak dikenal = [jangkaBawaan].
enum JangkaKuotaTransaksi { Bulan, Hari }

/// Kuota transaksi penjualan satu [jangka] menurut server: [terpakai] dari [batas] (null = tak terbatas),
/// dihitung dari penjualan yang sudah tersimpan di server. Penjualan yang belum terkirim dari perangkat ini
/// ditambahkan aplikasi sendiri (`KuotaTransaksiEfektif`).
class KuotaTransaksiAplikasi {
  const KuotaTransaksiAplikasi({
    required this.periode,
    required this.terpakai,
    required this.batas,
    required this.status,
    this.jangka = JangkaKuotaTransaksi.Bulan,
    this.berakhirPada,
  });

  final JangkaKuotaTransaksi jangka;

  /// Jangka Bulan: `TTTT-BB`. Jangka Hari: tanggal bisnis `TTTT-BB-HH`.
  final String periode;

  /// Jangka Hari: saat hari bisnis itu berakhir (UTC). Sesudahnya angka server tidak berlaku lagi (hari baru) sampai
  /// konfigurasi dibaca ulang. Null untuk jangka Bulan atau server tanpa outlet acuan.
  final DateTime? berakhirPada;
  final int terpakai;
  final int? batas;
  final StatusKuotaTransaksi status;

  static KuotaTransaksiAplikasi? DariJsonAtauNull(
    Object? nilai, {
    JangkaKuotaTransaksi jangkaBawaan = JangkaKuotaTransaksi.Bulan,
  }) {
    final json = UraiJson.AmbilPetaAtauNull(nilai);
    final periode = UraiJson.AmbilTeks(json?['Periode']);
    if (json == null || periode.isEmpty) {
      return null;
    }
    final berakhir = UraiJson.AmbilTeks(json['BerakhirPada']);
    return KuotaTransaksiAplikasi(
      jangka: JangkaKuotaTransaksi.values.firstWhere(
        (j) => j.name == UraiJson.AmbilTeks(json['Jangka']),
        orElse: () => jangkaBawaan,
      ),
      berakhirPada: berakhir.isEmpty ? null : DateTime.tryParse(berakhir)?.toUtc(),
      periode: periode,
      terpakai: UraiJson.AmbilBulat(json['Terpakai']),
      batas: UraiJson.AmbilBulatAtauNull(json['Batas']),
      status: StatusKuotaTransaksi.values.firstWhere(
        (s) => s.name == UraiJson.AmbilTeks(json['Status']),
        orElse: () => StatusKuotaTransaksi.Normal,
      ),
    );
  }
}

/// Jenis pengumuman platform. Nilai tak dikenal dari server yang lebih baru diperlakukan sebagai [Info].
enum JenisPengumuman { Info, YangBaru, Pemeliharaan, Penting }

/// Satu pengumuman platform (P-10 PGL-19). [bolehDitutup] false untuk Penting & Pemeliharaan.
class PengumumanAplikasi {
  const PengumumanAplikasi({
    required this.uuid,
    required this.judul,
    required this.isi,
    required this.jenis,
    required this.labelJenis,
    required this.bolehDitutup,
    this.tautan,
    this.pemeliharaanMulai,
    this.pemeliharaanSelesai,
  });

  final String uuid;
  final String judul;
  final String isi;
  final JenisPengumuman jenis;
  final String labelJenis;
  final bool bolehDitutup;
  final String? tautan;
  final DateTime? pemeliharaanMulai;
  final DateTime? pemeliharaanSelesai;

  static PengumumanAplikasi DariJson(Map<String, Object?> json) {
    final jenis = JenisPengumuman.values.firstWhere(
      (j) => j.name == UraiJson.AmbilTeks(json['Jenis']),
      orElse: () => JenisPengumuman.Info,
    );
    DateTime? UraiWaktu(Object? nilai) => nilai is String ? DateTime.tryParse(nilai)?.toLocal() : null;
    return PengumumanAplikasi(
      uuid: UraiJson.AmbilTeks(json['Uuid']),
      judul: UraiJson.AmbilTeks(json['Judul']),
      isi: UraiJson.AmbilTeks(json['Isi']),
      jenis: jenis,
      labelJenis: UraiJson.AmbilTeks(json['LabelJenis'], 'Info'),
      bolehDitutup: UraiJson.AmbilBenar(
        json['BolehDitutup'],
        jenis == JenisPengumuman.Info || jenis == JenisPengumuman.YangBaru,
      ),
      tautan: UraiJson.AmbilTeksAtauNull(json['Tautan']),
      pemeliharaanMulai: UraiWaktu(json['PemeliharaanMulai']),
      pemeliharaanSelesai: UraiWaktu(json['PemeliharaanSelesai']),
    );
  }
}
