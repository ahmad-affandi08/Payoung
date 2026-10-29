/// Model respons API POS (PRD §16.3). Key JSON = nama kolom PascalCase (§16.2); uang = string desimal.
library;

import 'UraiJson.dart';

Map<String, Object?> _Peta(Object? nilai) => nilai is Map<String, Object?> ? nilai : const <String, Object?>{};

List<Object?> _Daftar(Object? nilai) => nilai is List<Object?> ? nilai : const <Object?>[];

String _Teks(Object? nilai) => nilai is String ? nilai : '';

String? _TeksAtauNull(Object? nilai) => nilai is String ? nilai : null;

bool _Benar(Object? nilai) => nilai == true;

int _Bulat(Object? nilai) => nilai is int ? nilai : 0;

/// `POST /perangkat/aktivasi`.
class HasilAktivasi {
  const HasilAktivasi({
    required this.tokenPerangkat,
    required this.kunciPinOffline,
    required this.uuidPerangkat,
    required this.kodePerangkat,
    required this.namaPerangkat,
    required this.uuidOutlet,
    required this.namaOutlet,
    required this.namaUsaha,
    this.jenisPerangkat = 'Kasir',
  });

  final String tokenPerangkat;
  final String? kunciPinOffline;
  final String uuidPerangkat;
  final String kodePerangkat;
  final String namaPerangkat;
  final String uuidOutlet;
  final String namaOutlet;
  final String namaUsaha;

  /// Kasir/Pelayan/Kds/Gudang/Salesman (F-10b: perangkat `Kds` membuka layar dapur, bukan layar kasir). Teks bebas:
  /// jenis yang belum dikenal aplikasi (misal `Salesman` sebelum modulnya ada) diperlakukan seperti Kasir.
  final String jenisPerangkat;

  static HasilAktivasi DariJson(Map<String, Object?> json) {
    final perangkat = _Peta(json['Perangkat']);
    final outlet = _Peta(json['Outlet']);
    return HasilAktivasi(
      tokenPerangkat: _Teks(json['TokenPerangkat']),
      kunciPinOffline: _TeksAtauNull(json['KunciPinOffline']),
      uuidPerangkat: _Teks(perangkat['Uuid']),
      kodePerangkat: _Teks(perangkat['Kode']),
      namaPerangkat: _Teks(perangkat['Nama']),
      uuidOutlet: _Teks(outlet['Uuid']),
      namaOutlet: _Teks(outlet['Nama']),
      namaUsaha: _Teks(_Peta(json['Tenant'])['Nama']),
      jenisPerangkat: _TeksAtauNull(perangkat['Jenis']) ?? 'Kasir',
    );
  }
}

/// Verifier PIN offline yang dibungkus kunci perangkat (PRD §25.2 no. 3).
class PinTerbungkus {
  const PinTerbungkus({required this.garam, required this.nonce, required this.sandi});

  final String garam;
  final String nonce;
  final String sandi;

  static PinTerbungkus? DariJson(Object? json) {
    if (json is! Map<String, Object?>) {
      return null;
    }
    return PinTerbungkus(garam: _Teks(json['Garam']), nonce: _Teks(json['Nonce']), sandi: _Teks(json['Sandi']));
  }
}

class StafPos {
  const StafPos({
    required this.uuid,
    required this.nama,
    required this.pemilik,
    required this.izin,
    required this.pinDiatur,
    required this.pin,
  });

  final String uuid;
  final String nama;
  final bool pemilik;
  final List<String> izin;
  final bool pinDiatur;
  final PinTerbungkus? pin;

  bool PunyaIzin(String kunci) => pemilik || izin.contains(kunci);

  static StafPos DariJson(Map<String, Object?> json) => StafPos(
    uuid: _Teks(json['Uuid']),
    nama: _Teks(json['Nama']),
    pemilik: _Benar(json['Pemilik']),
    izin: _Daftar(json['Izin']).whereType<String>().toList(),
    pinDiatur: _Benar(json['PinDiatur']),
    pin: PinTerbungkus.DariJson(json['Pin']),
  );
}

class KategoriKasPos {
  const KategoriKasPos({required this.uuid, required this.nama, required this.jenis});

  final String uuid;
  final String nama;

  /// `Masuk` atau `Keluar`.
  final String jenis;

  static KategoriKasPos DariJson(Map<String, Object?> json) =>
      KategoriKasPos(uuid: _Teks(json['Uuid']), nama: _Teks(json['Nama']), jenis: _Teks(json['Jenis']));
}

class ParameterPin {
  const ParameterPin({
    required this.iterasi,
    required this.memoriKiB,
    required this.paralelisme,
    required this.panjang,
  });

  final int iterasi;
  final int memoriKiB;
  final int paralelisme;
  final int panjang;

  static ParameterPin DariJson(Map<String, Object?> json) => ParameterPin(
    iterasi: _Bulat(json['Iterasi']),
    memoriKiB: _Bulat(json['MemoriKiB']),
    paralelisme: _Bulat(json['Paralelisme']),
    panjang: _Bulat(json['Panjang']),
  );
}

/// Pembulatan tunai tenant (BR-08.6): kelipatan Rupiah dan arah `Bawah`/`Atas`/`Terdekat`.
class PembulatanTunaiPos {
  const PembulatanTunaiPos({required this.kelipatan, required this.arah});

  final int kelipatan;
  final String arah;

  static PembulatanTunaiPos? DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    if (peta == null) {
      return null;
    }
    final kelipatan = UraiJson.AmbilBulat(peta['Kelipatan']);
    return kelipatan <= 0
        ? null
        : PembulatanTunaiPos(kelipatan: kelipatan, arah: UraiJson.AmbilTeks(peta['Arah'], 'Terdekat'));
  }
}

/// Barcode timbangan (§9.3, v3.55): EAN-13 `AA PPPPP NNNNN C` dengan awalan [awalan] (21–29), kode produk 5 digit, dan
/// nilai 5 digit berupa berat gram ([nilaiHarga] false) atau harga Rupiah ([nilaiHarga] true).
class BarcodeTimbanganPos {
  const BarcodeTimbanganPos({this.aktif = false, this.awalan = const [], this.nilaiHarga = false});

  final bool aktif;
  final List<String> awalan;
  final bool nilaiHarga;

  static BarcodeTimbanganPos DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    return peta == null
        ? const BarcodeTimbanganPos()
        : BarcodeTimbanganPos(
            aktif: UraiJson.AmbilBenar(peta['Aktif']),
            awalan: UraiJson.AmbilDaftarTeks(peta['Awalan']),
            nilaiHarga: peta['Nilai'] == 'Harga',
          );
  }

  Map<String, Object?> KeJson() => {'Aktif': aktif, 'Awalan': awalan, 'Nilai': nilaiHarga ? 'Harga' : 'Berat'};
}

/// Outlet perangkat (F-07b): kode dipakai di nomor penjualan `INV/{KodeOutlet}/...` (BR-07.1).
class OutletPos {
  const OutletPos({
    required this.uuid,
    required this.kode,
    required this.nama,
    required this.alamat,
    required this.telepon,
    this.adaLogo = false,
    this.jamTutupBuku,
    this.zonaWaktu,
    this.jenisPesanan = const [],
    this.jenisPesananBawaan,
    this.modeKasir = const [],
    this.modeKasirBawaan,
  });

  final String uuid;
  final String kode;
  final String nama;
  final String? alamat;
  final String? telepon;

  /// D-16: logo merek outlet tersedia untuk bilah atas aplikasi Kasir (`GET /api/pos/v1/logo-outlet`). Beda dari
  /// `StrukPos.adaLogo`: saklar tampil logo di struk tidak memengaruhinya. Server lama tidak mengirimnya = `false`.
  final bool adaLogo;

  /// `HH:mm` bila server mengirimnya (tanggal bisnis sebelum jam ini = hari sebelumnya); null = 00:00.
  final String? jamTutupBuku;

  /// Zona waktu IANA outlet (`Outlet.ZonaWaktu`, misal `Asia/Makassar`) untuk tanggal bisnis & `YYMMDD` nomor
  /// (PRD v1.46 (d)); null bila server lama tidak mengirimnya.
  final String? zonaWaktu;

  /// v3.51: jenis pesanan yang dipilih kasir per transaksi (`MakanDiTempat`/`BawaPulang`/`Antar`); kosong = tanpa
  /// pilihan (server lama juga tidak mengirimnya).
  final List<String> jenisPesanan;

  /// Jenis pesanan bawaan transaksi baru; null bila tidak ada pilihan.
  final String? jenisPesananBawaan;

  /// K-8: mode kasir template sektor (`Retail`/`Cepat`/`Meja`/`Layanan`/`Grosir`); kosong = belum ada (server lama).
  final List<String> modeKasir;

  /// Mode kasir bawaan outlet; null bila outlet belum punya mode kasir.
  final String? modeKasirBawaan;

  static OutletPos? DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    return peta == null
        ? null
        : OutletPos(
            uuid: UraiJson.AmbilTeks(peta['Uuid']),
            kode: UraiJson.AmbilTeks(peta['Kode']),
            nama: UraiJson.AmbilTeks(peta['Nama']),
            alamat: UraiJson.AmbilTeksAtauNull(peta['Alamat']),
            telepon: UraiJson.AmbilTeksAtauNull(peta['Telepon']),
            adaLogo: UraiJson.AmbilBenar(peta['AdaLogo']),
            jamTutupBuku: UraiJson.AmbilTeksAtauNull(peta['JamTutupBuku']),
            zonaWaktu: UraiJson.AmbilTeksAtauNull(peta['ZonaWaktu']),
            jenisPesanan: UraiJson.AmbilDaftarTeks(peta['JenisPesanan']),
            jenisPesananBawaan: UraiJson.AmbilTeksAtauNull(peta['JenisPesananBawaan']),
            modeKasir: UraiJson.AmbilDaftarTeks(peta['ModeKasir']),
            modeKasirBawaan: UraiJson.AmbilTeksAtauNull(peta['ModeKasirBawaan']),
          );
  }
}

class PerangkatPos {
  const PerangkatPos({
    required this.uuid,
    required this.kode,
    this.nomorUrutPenjualan = const {},
    this.nomorUrutRetur = const {},
    this.nomorUrutIsiDeposit = const {},
    this.awalanAntrian,
  });

  final String uuid;
  final String kode;

  /// K26: awalan huruf nomor antrian perangkat ini (`A` → `A-042`) agar dua kasir di satu outlet tidak memanggil nomor
  /// yang sama. Hanya 1–2 huruf; server lama atau nilai tidak valid → null (nomor polos `042`).
  final String? awalanAntrian;

  /// Nomor urut penjualan terakhir perangkat ini di server per tanggal `YYMMDD` (`Perangkat.NomorUrutPenjualan`,
  /// PRD v1.46 (e)), agar pemasangan ulang aplikasi tidak memakai nomor yang sama. Kunci absen/tidak valid diabaikan.
  final Map<String, int> nomorUrutPenjualan;

  /// Nomor urut retur (`RJ`) terakhir perangkat ini di server per tanggal `YYMMDD` (`Perangkat.NomorUrutRetur`, F-09),
  /// bentuk sama dengan [nomorUrutPenjualan]. Kunci absen/tidak valid diabaikan (server lama → kosong).
  final Map<String, int> nomorUrutRetur;

  /// F-16d: nomor urut isi deposit (`DEP`) terakhir perangkat ini per `YYMMDD` (`Perangkat.NomorUrutIsiDeposit`); server
  /// lama → kosong.
  final Map<String, int> nomorUrutIsiDeposit;

  static PerangkatPos? DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    return peta == null
        ? null
        : PerangkatPos(
            uuid: UraiJson.AmbilTeks(peta['Uuid']),
            kode: UraiJson.AmbilTeks(peta['Kode']),
            nomorUrutPenjualan: _AmbilNomorUrut(peta['NomorUrutPenjualan']),
            nomorUrutRetur: _AmbilNomorUrut(peta['NomorUrutRetur']),
            nomorUrutIsiDeposit: _AmbilNomorUrut(peta['NomorUrutIsiDeposit']),
            awalanAntrian: _AmbilAwalanAntrian(peta['AwalanAntrian']),
          );
  }

  static final RegExp _polaYymmdd = RegExp(r'^\d{6}$');
  static final RegExp _polaAwalanAntrian = RegExp(r'^[A-Za-z]{1,2}$');

  static String? _AmbilAwalanAntrian(Object? nilai) {
    final teks = UraiJson.AmbilTeksAtauNull(nilai)?.trim();
    return teks != null && _polaAwalanAntrian.hasMatch(teks) ? teks.toUpperCase() : null;
  }

  static Map<String, int> _AmbilNomorUrut(Object? nilai) {
    final hasil = <String, int>{};
    UraiJson.AmbilPeta(nilai).forEach((tanggal, urut) {
      final angka = UraiJson.AmbilBulatAtauNull(urut);
      if (_polaYymmdd.hasMatch(tanggal) && angka != null && angka > 0) {
        hasil[tanggal] = angka;
      }
    });
    return hasil;
  }
}

/// Profil pajak outlet (F-07b): PPN hanya bila PKP, PBJT makanan & minuman hanya bila memungut PBJT.
class ProfilPajakPos {
  const ProfilPajakPos({
    this.pkp = false,
    this.pungutPbjt = false,
    this.hargaTermasukPajak = false,
    this.biayaLayananAktif = false,
    this.persenBiayaLayanan = '0',
  });

  final bool pkp;
  final bool pungutPbjt;
  final bool hargaTermasukPajak;
  final bool biayaLayananAktif;
  final String persenBiayaLayanan;

  static ProfilPajakPos DariJson(Object? json) {
    final peta = UraiJson.AmbilPeta(json);
    final biaya = UraiJson.AmbilPeta(peta['BiayaLayanan']);
    return ProfilPajakPos(
      pkp: UraiJson.AmbilBenar(peta['Pkp']),
      pungutPbjt: UraiJson.AmbilBenar(peta['PungutPbjt']),
      hargaTermasukPajak: UraiJson.AmbilBenar(peta['HargaTermasukPajak']),
      biayaLayananAktif: UraiJson.AmbilBenar(biaya['Aktif']),
      persenBiayaLayanan: UraiJson.AmbilDesimal(biaya['Persen']),
    );
  }

  Map<String, Object?> KeJson() => {
    'Pkp': pkp,
    'PungutPbjt': pungutPbjt,
    'HargaTermasukPajak': hargaTermasukPajak,
    'BiayaLayanan': {'Aktif': biayaLayananAktif, 'Persen': persenBiayaLayanan},
  };
}

/// Tarif pajak terbit (nasional + wilayah outlet) beserta masa berlakunya (tanggal `YYYY-MM-DD`, CLAUDE.md #12).
class TarifPajakPos {
  const TarifPajakPos({
    required this.kodeJenisPajak,
    required this.tarif,
    required this.pengaliDppPembilang,
    required this.pengaliDppPenyebut,
    required this.berlakuMulai,
    required this.berlakuSampai,
  });

  final String kodeJenisPajak;
  final String tarif;
  final int pengaliDppPembilang;
  final int pengaliDppPenyebut;
  final String berlakuMulai;
  final String? berlakuSampai;

  static TarifPajakPos DariJson(Map<String, Object?> json) => TarifPajakPos(
    kodeJenisPajak: UraiJson.AmbilTeks(json['KodeJenisPajak']),
    tarif: UraiJson.AmbilDesimal(json['Tarif']),
    pengaliDppPembilang: UraiJson.AmbilBulat(json['PengaliDppPembilang'], 1),
    pengaliDppPenyebut: UraiJson.AmbilBulat(json['PengaliDppPenyebut'], 1),
    berlakuMulai: _AmbilTanggal(json['BerlakuMulai']) ?? '0000-01-01',
    berlakuSampai: _AmbilTanggal(json['BerlakuSampai']),
  );

  static String? _AmbilTanggal(Object? nilai) {
    final teks = UraiJson.AmbilTeksAtauNull(nilai);
    return teks == null || teks.length < 10 ? null : teks.substring(0, 10);
  }
}

/// Metode pembayaran aktif outlet (jenis fase 1: `Tunai`, `QrisStatis`, `Edc`, `Transfer`, `Ewallet`; X8: `Marketplace`
/// ber-[kanal] platform).
class MetodePembayaranPos {
  const MetodePembayaranPos({
    required this.uuid,
    required this.jenis,
    required this.nama,
    required this.nomorRekening,
    required this.namaPemilikRekening,
    required this.adaGambarQris,
    required this.urutan,
    this.kanal,
    this.teksQris,
  });

  final String uuid;
  final String jenis;
  final String nama;
  final String? nomorRekening;
  final String? namaPemilikRekening;
  final bool adaGambarQris;
  final int urutan;

  /// D-189: payload QRIS statis (publik di gambar QR-nya) untuk menyusun QRIS bernominal di kasir; null = gambar saja
  /// atau server lama.
  final String? teksQris;

  /// X8: kanal platform metode `Marketplace` (`GoFood`, `GrabFood`, …); null untuk jenis lain dan server lama.
  final String? kanal;

  static MetodePembayaranPos DariJson(Map<String, Object?> json) => MetodePembayaranPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    jenis: UraiJson.AmbilTeks(json['Jenis']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    nomorRekening: UraiJson.AmbilTeksAtauNull(json['NomorRekening']),
    namaPemilikRekening: UraiJson.AmbilTeksAtauNull(json['NamaPemilikRekening']),
    adaGambarQris: UraiJson.AmbilBenar(json['AdaGambarQris']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
    kanal: UraiJson.AmbilTeksAtauNull(json['Kanal']),
    teksQris: UraiJson.AmbilTeksAtauNull(json['TeksQris']),
  );
}

/// `GET /data-awal` (F-06, ditambah F-07b & F-11). Kunci yang absen (server lama) memakai nilai bawaan agar
/// kompatibel mundur: batas diskon 10% / 30%, tanpa pembulatan tunai, profil pajak kosong, tanpa tarif & metode;
/// tutup shift buta aktif, toleransi selisih kas Rp 10.000, dan batas retur 7 hari.
/// Staf pelayan baris penjualan (F-18, data awal `Karyawan`).
class KaryawanPos {
  const KaryawanPos({required this.uuid, required this.nama, this.jabatan});

  final String uuid;
  final String nama;
  final String? jabatan;

  Map<String, Object?> KeJson() => {'Uuid': uuid, 'Nama': nama, 'Jabatan': jabatan};

  static KaryawanPos DariJson(Map<String, Object?> json) => KaryawanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    jabatan: UraiJson.AmbilTeksAtauNull(json['Jabatan']),
  );
}

class DataAwal {
  const DataAwal({
    required this.batasKasKeluar,
    required this.shiftBersama,
    required this.kategoriKas,
    required this.staf,
    required this.pinOfflineTersedia,
    required this.parameterPin,
    required this.batasSalahPin,
    required this.menitKunciPin,
    required this.waktuServer,
    this.batasDiskonManual = batasDiskonManualBawaan,
    this.batasDiskonPenyetuju = batasDiskonPenyetujuBawaan,
    this.pembulatanTunai,
    this.outlet,
    this.perangkat,
    this.profilPajak = const ProfilPajakPos(),
    this.tarifPajak = const [],
    this.metodePembayaran = const [],
    this.tutupShiftButa = true,
    this.toleransiSelisihKas = toleransiSelisihKasBawaan,
    this.batasHariRetur = batasHariReturBawaan,
    this.batasReturTanpaStrukHarian = batasReturTanpaStrukHarianBawaan,
    this.batasHariLewatJatuhTempo = 0,
    this.bukaLaciPerluPin = false,
    this.persetujuanJarakJauh = false,
    this.barcodeTimbangan = const BarcodeTimbanganPos(),
    this.karyawan = const [],
    this.struk,
    this.deposit = const DepositPos(),
    this.laundry = const LaundryPos(),
    this.tokoOnlineAktif = false,
    this.kodeSektor = const [],
  });

  static const String batasDiskonManualBawaan = '10';

  /// F-11: toleransi selisih kas bawaan Rp 10.000 (§19.2).
  static const String toleransiSelisihKasBawaan = '10000';
  static const String batasDiskonPenyetujuBawaan = '30';

  /// F-09: batas hari retur bawaan (inklusif, 0–365).
  static const int batasHariReturBawaan = 7;

  /// K28: batas Σ retur tanpa struk outlet per hari bawaan Rp 1.000.000.
  static const String batasReturTanpaStrukHarianBawaan = '1000000';

  final String batasKasKeluar;
  final bool shiftBersama;
  final List<KategoriKasPos> kategoriKas;
  final List<StafPos> staf;
  final bool pinOfflineTersedia;
  final ParameterPin parameterPin;
  final int batasSalahPin;
  final int menitKunciPin;
  final String waktuServer;

  /// Persen diskon manual tanpa persetujuan (BR-07.3).
  final String batasDiskonManual;

  /// Persen diskon maksimal dengan persetujuan penyetuju (Pemilik tanpa batas).
  final String batasDiskonPenyetuju;
  final PembulatanTunaiPos? pembulatanTunai;
  final OutletPos? outlet;
  final PerangkatPos? perangkat;
  final ProfilPajakPos profilPajak;
  final List<TarifPajakPos> tarifPajak;
  final List<MetodePembayaranPos> metodePembayaran;

  /// F-11: kas seharusnya disembunyikan sampai kasir menyimpan hitungan tutup shift.
  final bool tutupShiftButa;

  /// F-11: |selisih kas| di atas nilai ini wajib alasan + PIN penyetuju ber-izin `shift.selisih.setujui`.
  final String toleransiSelisihKas;

  /// F-09: retur paling lama sekian hari sejak tanggal bisnis penjualan (`Pengaturan.BatasHariRetur`).
  final int batasHariRetur;

  /// K28: Σ retur tanpa struk outlet per hari di atas nilai ini menjadi tinjauan di server; aplikasi memperingatkan
  /// (`Pengaturan.BatasReturTanpaStrukHarian`; server lama tanpa kunci ini = bawaan).
  final String batasReturTanpaStrukHarian;

  /// F-12 BR-12.1: penjualan tempo butuh penyetuju bila pelanggan punya piutang lewat jatuh tempo lebih dari sekian
  /// hari (`Pengaturan.BatasHariLewatJatuhTempo`; server lama tanpa kunci ini = 0).
  final int batasHariLewatJatuhTempo;

  /// Cetak struk bagian 4 (§19.2): buka laci manual tanpa transaksi wajib PIN supervisor `kas.keluar.setujui`
  /// (`Pengaturan.BukaLaciPerluPin`; server lama tanpa kunci ini = false).
  final bool bukaLaciPerluPin;

  /// X4: fitur paket persetujuan jarak jauh aktif (`Pengaturan.PersetujuanJarakJauh`; server lama = false).
  final bool persetujuanJarakJauh;

  /// v3.55 (§9.3): barcode timbangan EAN-13 (`Pengaturan.BarcodeTimbangan`; server lama = tidak aktif).
  final BarcodeTimbanganPos barcodeTimbangan;

  /// F-18: staf yang bisa dipilih sebagai pelayan baris (komisi); server lama = kosong.
  final List<KaryawanPos> karyawan;

  /// PRD v1.79: pengaturan & identitas struk; server lama = null (aplikasi memakai bawaan).
  final StrukPos? struk;

  /// F-16d bagian 1: deposit pelanggan; server lama = tidak berlaku.
  final DepositPos deposit;

  /// Laundry (§9.9): isian tiket laundry di kasir & awalan tautan lacak; server lama = tidak aktif.
  final LaundryPos laundry;

  /// F-17: toko online melayani outlet perangkat ini, jadi kasir menampilkan menu Pesanan toko online.
  /// Server lama tanpa kunci `TokoOnline` = tidak aktif.
  final bool tokoOnlineAktif;

  /// D-48: kode sektor outlet ini (template sektor + jenis usaha tambahan, misal `RTL-GEN`, `SVC-WRK`); kasir menyaring
  /// fitur khusus sektor (Servis, Reservasi) darinya. Server lama / kosong = sektor belum diketahui, semua tampil.
  final List<String> kodeSektor;

  static DataAwal DariJson(Map<String, Object?> json) {
    final pengaturan = _Peta(json['Pengaturan']);
    final pin = _Peta(json['PinOffline']);
    return DataAwal(
      batasKasKeluar: _Teks(pengaturan['BatasKasKeluar']),
      shiftBersama: _Benar(pengaturan['ShiftBersama']),
      kategoriKas: _Daftar(json['KategoriKas']).map((e) => KategoriKasPos.DariJson(_Peta(e))).toList(),
      staf: _Daftar(json['Staf']).map((e) => StafPos.DariJson(_Peta(e))).toList(),
      pinOfflineTersedia: _Benar(pin['Tersedia']),
      parameterPin: ParameterPin.DariJson(_Peta(pin['Parameter'])),
      batasSalahPin: _Bulat(pin['BatasSalah']),
      menitKunciPin: _Bulat(pin['MenitKunci']),
      waktuServer: _Teks(json['WaktuServer']),
      batasDiskonManual: UraiJson.AmbilDesimal(pengaturan['BatasDiskonManual'], batasDiskonManualBawaan),
      batasDiskonPenyetuju: UraiJson.AmbilDesimal(pengaturan['BatasDiskonPenyetuju'], batasDiskonPenyetujuBawaan),
      pembulatanTunai: PembulatanTunaiPos.DariJson(pengaturan['PembulatanTunai']),
      outlet: OutletPos.DariJson(json['Outlet']),
      perangkat: PerangkatPos.DariJson(json['Perangkat']),
      profilPajak: ProfilPajakPos.DariJson(json['ProfilPajak']),
      tarifPajak: UraiJson.AmbilDaftarPeta(json['TarifPajak']).map(TarifPajakPos.DariJson).toList(),
      metodePembayaran: UraiJson.AmbilDaftarPeta(json['MetodePembayaran']).map(MetodePembayaranPos.DariJson).toList(),
      tutupShiftButa: UraiJson.AmbilBenar(pengaturan['TutupShiftButa'], true),
      toleransiSelisihKas: UraiJson.AmbilDesimal(pengaturan['ToleransiSelisihKas'], toleransiSelisihKasBawaan),
      batasHariRetur: UraiJson.AmbilBulat(pengaturan['BatasHariRetur'], batasHariReturBawaan),
      batasReturTanpaStrukHarian: UraiJson.AmbilDesimal(
        pengaturan['BatasReturTanpaStrukHarian'],
        batasReturTanpaStrukHarianBawaan,
      ),
      batasHariLewatJatuhTempo: UraiJson.AmbilBulat(pengaturan['BatasHariLewatJatuhTempo']),
      bukaLaciPerluPin: UraiJson.AmbilBenar(pengaturan['BukaLaciPerluPin']),
      persetujuanJarakJauh: UraiJson.AmbilBenar(pengaturan['PersetujuanJarakJauh']),
      barcodeTimbangan: BarcodeTimbanganPos.DariJson(pengaturan['BarcodeTimbangan']),
      karyawan: UraiJson.AmbilDaftarPeta(json['Karyawan']).map(KaryawanPos.DariJson).toList(),
      struk: StrukPos.DariJson(json['Struk']),
      deposit: DepositPos.DariJson(json['Deposit']),
      laundry: LaundryPos.DariJson(json['Laundry']),
      tokoOnlineAktif: UraiJson.AmbilBenar(UraiJson.AmbilPeta(json['TokoOnline'])['Aktif']),
      kodeSektor: UraiJson.AmbilDaftarTeks(json['KodeSektor']),
    );
  }
}

/// Laundry (`data-awal` → `Laundry`, §9.9): isian tiket di kasir aktif, durasi reguler/express (jam) untuk estimasi
/// selesai, pilihan parfum, dan awalan tautan lacak `/s/{kode}` untuk QR nota.
class LaundryPos {
  const LaundryPos({
    this.aktif = false,
    this.jamReguler = 48,
    this.jamExpress = 24,
    this.parfum = const [],
    this.awalanLacak,
  });

  final bool aktif;
  final int jamReguler;
  final int jamExpress;
  final List<String> parfum;
  final String? awalanLacak;

  static LaundryPos DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    return peta == null
        ? const LaundryPos()
        : LaundryPos(
            aktif: UraiJson.AmbilBenar(peta['Aktif']),
            jamReguler: UraiJson.AmbilBulat(peta['JamReguler'], 48),
            jamExpress: UraiJson.AmbilBulat(peta['JamExpress'], 24),
            parfum: UraiJson.AmbilDaftarTeks(peta['Parfum']),
            awalanLacak: UraiJson.AmbilTeksAtauNull(peta['AwalanLacak']),
          );
  }

  Map<String, Object?> KeJson() => {
    'Aktif': aktif,
    'JamReguler': jamReguler,
    'JamExpress': jamExpress,
    'Parfum': parfum,
    'AwalanLacak': awalanLacak,
  };
}

/// Deposit pelanggan (`data-awal` → `Deposit`, F-16d bagian 1): berlaku bila paket punya fitur `pelanggan.deposit`;
/// batas isi per transaksi (Rupiah bulat).
class DepositPos {
  const DepositPos({this.berlaku = false, this.minimalIsi = minimalIsiBawaan, this.maksimalIsi = maksimalIsiBawaan});

  static const String minimalIsiBawaan = '1000.00';
  static const String maksimalIsiBawaan = '10000000.00';

  final bool berlaku;
  final String minimalIsi;
  final String maksimalIsi;

  static DepositPos DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    return peta == null
        ? const DepositPos()
        : DepositPos(
            berlaku: UraiJson.AmbilBenar(peta['Berlaku']),
            minimalIsi: UraiJson.AmbilDesimal(peta['MinimalIsi'], minimalIsiBawaan),
            maksimalIsi: UraiJson.AmbilDesimal(peta['MaksimalIsi'], maksimalIsiBawaan),
          );
  }

  Map<String, Object?> KeJson() => {'Berlaku': berlaku, 'MinimalIsi': minimalIsi, 'MaksimalIsi': maksimalIsi};
}

/// Pengaturan struk tenant + identitas usaha (`data-awal` → `Struk`, PRD v1.79). Teks null = bawaan aplikasi.
class StrukPos {
  const StrukPos({
    this.namaUsaha = '',
    this.npwp,
    this.adaLogo = false,
    this.tandaAir = false,
    this.namaDicetak,
    this.teksKepala = const [],
    this.tampilkanAlamat = true,
    this.tampilkanTelepon = true,
    this.tampilkanNpwp = true,
    this.tampilkanKasir = true,
    this.tampilkanPelanggan = true,
    this.tampilkanHemat = true,
    this.catatanKaki,
    this.teksPenutup,
    this.awalanStrukDigital,
  });

  final String namaUsaha;

  /// Hanya terisi bila outlet PKP.
  final String? npwp;

  /// Logo tersedia & ditampilkan (unduh lewat `GET /logo-struk`).
  final bool adaLogo;

  /// Paket tanpa fitur `struk.tanpa-watermark`: cetak "Dibuat dengan Payoung".
  final bool tandaAir;
  final String? namaDicetak;
  final List<String> teksKepala;
  final bool tampilkanAlamat;
  final bool tampilkanTelepon;
  final bool tampilkanNpwp;
  final bool tampilkanKasir;
  final bool tampilkanPelanggan;
  final bool tampilkanHemat;
  final String? catatanKaki;
  final String? teksPenutup;

  /// POS-11: awalan tautan struk digital (`https://…/s/{tenant}.`); tautan = awalan + Uuid penjualan. Null = mati.
  final String? awalanStrukDigital;

  static StrukPos? DariJson(Object? json) {
    final peta = UraiJson.AmbilPetaAtauNull(json);
    if (peta == null) {
      return null;
    }
    return StrukPos(
      namaUsaha: UraiJson.AmbilTeks(peta['NamaUsaha']),
      npwp: UraiJson.AmbilTeksAtauNull(peta['Npwp']),
      adaLogo: UraiJson.AmbilBenar(peta['AdaLogo']),
      tandaAir: UraiJson.AmbilBenar(peta['TandaAir']),
      namaDicetak: UraiJson.AmbilTeksAtauNull(peta['NamaDicetak']),
      teksKepala: _Daftar(peta['TeksKepala']).whereType<String>().toList(),
      tampilkanAlamat: UraiJson.AmbilBenar(peta['TampilkanAlamat'], true),
      tampilkanTelepon: UraiJson.AmbilBenar(peta['TampilkanTelepon'], true),
      tampilkanNpwp: UraiJson.AmbilBenar(peta['TampilkanNpwp'], true),
      tampilkanKasir: UraiJson.AmbilBenar(peta['TampilkanKasir'], true),
      tampilkanPelanggan: UraiJson.AmbilBenar(peta['TampilkanPelanggan'], true),
      tampilkanHemat: UraiJson.AmbilBenar(peta['TampilkanHemat'], true),
      catatanKaki: UraiJson.AmbilTeksAtauNull(peta['CatatanKaki']),
      teksPenutup: UraiJson.AmbilTeksAtauNull(peta['TeksPenutup']),
      awalanStrukDigital: UraiJson.AmbilTeksAtauNull(peta['AwalanStrukDigital']),
    );
  }

  Map<String, Object?> KeJson() => {
    'NamaUsaha': namaUsaha,
    'Npwp': npwp,
    'AdaLogo': adaLogo,
    'TandaAir': tandaAir,
    'NamaDicetak': namaDicetak,
    'TeksKepala': teksKepala,
    'TampilkanAlamat': tampilkanAlamat,
    'TampilkanTelepon': tampilkanTelepon,
    'TampilkanNpwp': tampilkanNpwp,
    'TampilkanKasir': tampilkanKasir,
    'TampilkanPelanggan': tampilkanPelanggan,
    'TampilkanHemat': tampilkanHemat,
    'CatatanKaki': catatanKaki,
    'TeksPenutup': teksPenutup,
    'AwalanStrukDigital': awalanStrukDigital,
  };
}

/// `POST /kasir/masuk-pin`.
class HasilMasukPin {
  const HasilMasukPin({required this.uuid, required this.nama, required this.pemilik, required this.izin});

  final String uuid;
  final String nama;
  final bool pemilik;
  final List<String> izin;

  static HasilMasukPin DariJson(Map<String, Object?> json) {
    final pengguna = _Peta(json['Pengguna']);
    return HasilMasukPin(
      uuid: _Teks(pengguna['Uuid']),
      nama: _Teks(pengguna['Nama']),
      pemilik: _Benar(json['Pemilik']),
      izin: _Daftar(json['Izin']).whereType<String>().toList(),
    );
  }
}

/// Satu item outbox yang dikirim ke `POST /sinkron/kirim`.
class ItemOutbox {
  const ItemOutbox({required this.jenis, required this.uuid, required this.data, this.uuidPerangkatAsal});

  final String jenis;
  final String uuid;
  final Map<String, Object?> data;

  /// Perangkat yang membuat item (audit P0 F-01). Server mengkreditkan item ke perangkat ini, bukan ke pengirim.
  final String? uuidPerangkatAsal;

  Map<String, Object?> toJson() => {
    'Jenis': jenis,
    'Uuid': uuid,
    'Data': data,
    if (uuidPerangkatAsal != null) 'UuidPerangkatAsal': uuidPerangkatAsal,
  };
}

/// Jawaban `sinkron/kirim`: hasil per item + `PerangkatDicabut` (perangkat pengirim sudah dicabut, masa pemulihan:
/// kosongkan outbox lalu hapus token).
class JawabanSinkron {
  const JawabanSinkron({
    required this.hasil,
    this.perangkatDicabut = false,
    this.waktuServer,
    this.perluTinjauan = const [],
  });

  final List<HasilItemSinkron> hasil;
  final bool perangkatDicabut;

  /// K-17: jam server saat menjawab (untuk peringatan jam perangkat salah).
  final DateTime? waktuServer;

  /// K-17: Uuid `Penjualan.Buat` di batch ini yang diterima dengan tanda tinjauan back-office (server lama = kosong).
  final List<String> perluTinjauan;
}

enum StatusItemSinkron { Diterima, Duplikat, Ditolak }

class HasilItemSinkron {
  const HasilItemSinkron({
    required this.uuid,
    required this.jenis,
    required this.status,
    this.kodeGalat,
    this.pesanGalat,
  });

  final String uuid;
  final String jenis;
  final StatusItemSinkron status;
  final String? kodeGalat;
  final String? pesanGalat;

  static HasilItemSinkron DariJson(Map<String, Object?> json) {
    final galat = _Peta(json['Galat']);
    return HasilItemSinkron(
      uuid: _Teks(json['Uuid']),
      jenis: _Teks(json['Jenis']),
      status: StatusItemSinkron.values.firstWhere(
        (s) => s.name == json['Status'],
        orElse: () => StatusItemSinkron.Ditolak,
      ),
      kodeGalat: _TeksAtauNull(galat['Kode']),
      pesanGalat: _TeksAtauNull(galat['Pesan']),
    );
  }
}

/// K-24 (`GET /api/pos/v1/ringkasan-harian`): ringkasan akhir hari outlet (semua perangkat). Uang string desimal.
class RingkasanHarianPos {
  const RingkasanHarianPos({
    required this.tanggal,
    required this.jumlahTransaksi,
    required this.jumlahVoid,
    required this.jumlahRetur,
    required this.kotor,
    required this.diskon,
    required this.retur,
    required this.bersih,
    required this.pajak,
    required this.perMetodeBayar,
    required this.perKasir,
  });

  final String tanggal;
  final int jumlahTransaksi;
  final int jumlahVoid;
  final int jumlahRetur;
  final String kotor;
  final String diskon;
  final String retur;
  final String bersih;
  final String pajak;
  final List<({String jenis, String nama, String jumlah})> perMetodeBayar;
  final List<({String nama, int jumlahTransaksi, String bersih})> perKasir;

  static RingkasanHarianPos DariJson(Map<String, Object?> json) => RingkasanHarianPos(
    tanggal: UraiJson.AmbilTeks(json['Tanggal']),
    jumlahTransaksi: UraiJson.AmbilBulat(json['JumlahTransaksi']),
    jumlahVoid: UraiJson.AmbilBulat(json['JumlahVoid']),
    jumlahRetur: UraiJson.AmbilBulat(json['JumlahRetur']),
    kotor: UraiJson.AmbilDesimal(json['Kotor']),
    diskon: UraiJson.AmbilDesimal(json['Diskon']),
    retur: UraiJson.AmbilDesimal(json['Retur']),
    bersih: UraiJson.AmbilDesimal(json['Bersih']),
    pajak: UraiJson.AmbilDesimal(json['Pajak']),
    perMetodeBayar: [
      for (final m in UraiJson.AmbilDaftarPeta(json['PerMetodeBayar']))
        (
          jenis: UraiJson.AmbilTeks(m['Jenis']),
          nama: UraiJson.AmbilTeks(m['Nama']),
          jumlah: UraiJson.AmbilDesimal(m['Jumlah']),
        ),
    ],
    perKasir: [
      for (final k in UraiJson.AmbilDaftarPeta(json['PerKasir']))
        (
          nama: UraiJson.AmbilTeks(k['Nama']),
          jumlahTransaksi: UraiJson.AmbilBulat(k['JumlahTransaksi']),
          bersih: UraiJson.AmbilDesimal(k['Bersih']),
        ),
    ],
  );
}
