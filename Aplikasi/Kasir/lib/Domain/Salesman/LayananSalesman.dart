import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriSalesman.dart';
import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Penjualan/Keranjang.dart';
import '../Perangkat/PenentuLokasi.dart';
import '../Sesi/StafLokal.dart';

/// Izin & jenis perangkat mode Salesman (sama dengan `IzinTenant::SalesmanKunjungan` & `JenisPerangkat::Salesman`).
abstract final class IzinSalesman {
  static const String kunjungan = 'salesman.kunjungan';
  static const String jenisPerangkat = 'Salesman';
}

/// Hasil kunjungan (sama dengan enum `HasilKunjungan` server).
enum HasilKunjungan {
  PesananDibuat('Pesanan dibuat'),
  TidakPesan('Tidak pesan'),
  TokoTutup('Toko tutup'),
  Lainnya('Lainnya');

  const HasilKunjungan(this.label);

  final String label;

  static HasilKunjungan? Cari(String? kode) => values.where((h) => h.name == kode).firstOrNull;
}

/// Pelanggan dari cache salesman, dengan uang sudah berupa [Uang].
class PelangganSalesman {
  const PelangganSalesman({
    required this.uuid,
    required this.nama,
    this.noHp,
    this.alamat,
    this.kodeTier,
    this.namaTier,
    this.limitKredit,
    this.terminHari = 0,
    required this.sisaPiutang,
    required this.jumlahPiutangJatuhTempo,
    this.hariLewatJatuhTempo = 0,
    this.terakhirDikunjungiPada,
  });

  final String uuid;
  final String nama;
  final String? noHp;
  final String? alamat;
  final String? kodeTier;
  final String? namaTier;
  final Uang? limitKredit;
  final int terminHari;
  final Uang sisaPiutang;
  final Uang jumlahPiutangJatuhTempo;
  final int hariLewatJatuhTempo;
  final DateTime? terakhirDikunjungiPada;

  /// Ada piutang yang jatuh temponya sudah lewat.
  bool get adaLewatJatuhTempo => hariLewatJatuhTempo > 0 && !jumlahPiutangJatuhTempo.BernilaiNol();

  /// Sisa limit kredit (boleh negatif bila piutang melebihi limit); null = tanpa limit.
  Uang? get sisaLimit => limitKredit?.Kurangi(sisaPiutang);

  /// [kunjunganLokal] = kunjungan terakhir dari perangkat ini (bisa lebih baru dari data server di cache).
  static PelangganSalesman DariBaris(BarisPelangganSalesmanLokal b, {DateTime? kunjunganLokal}) {
    final server = b.TerakhirDikunjungiPada?.toUtc();
    return PelangganSalesman(
      uuid: b.Uuid,
      nama: b.Nama,
      noHp: b.NoHp,
      alamat: b.Alamat,
      kodeTier: b.KodeTier,
      namaTier: b.NamaTier,
      limitKredit: b.LimitKredit == null ? null : Uang.Dari(b.LimitKredit!),
      terminHari: b.TerminHari,
      sisaPiutang: Uang.Dari(b.SisaPiutang),
      jumlahPiutangJatuhTempo: Uang.Dari(b.JumlahPiutangJatuhTempo),
      hariLewatJatuhTempo: b.HariLewatJatuhTempo,
      terakhirDikunjungiPada: server == null || (kunjunganLokal != null && kunjunganLokal.isAfter(server))
          ? kunjunganLokal
          : server,
    );
  }
}

/// Satu baris pesanan yang sedang disusun salesman: [satuan] selalu satuan produk (`ProdukSatuan`), [jumlah] dalam
/// satuan itu.
class BarisPesananSalesman {
  const BarisPesananSalesman({required this.produk, required this.satuan, required this.jumlah});

  final ProdukJual produk;
  final SatuanJual satuan;
  final Kuantitas jumlah;

  /// Jumlah dalam satuan dasar (untuk dibandingkan dengan stok kantor).
  Kuantitas AmbilJumlahDasar() => jumlah.Kali(Decimal.tryParse(satuan.konversiKeDasar) ?? Decimal.one);
}

/// Perkiraan nilai pesanan dari harga katalog lokal. **Bukan harga final**: server menentukan harga (price engine &
/// tier pelanggan) saat pesanan diterima.
class PerkiraanPesanan {
  const PerkiraanPesanan({required this.total, required this.hargaSatuan, required this.adaTanpaHarga});

  final Uang total;

  /// Harga satuan per baris (urutan sama dengan baris); null = belum ada harga di katalog perangkat.
  final List<Uang?> hargaSatuan;
  final bool adaTanpaHarga;
}

/// Snapshot stok kantor (lokasi Toko outlet) terakhir yang diunduh; petunjuk saja.
class StokKantor {
  const StokKantor({required this.stok, required this.diambilPada});

  static const StokKantor kosong = StokKantor(stok: {}, diambilPada: null);

  final Map<String, Kuantitas> stok;
  final DateTime? diambilPada;

  Kuantitas? Ambil(String uuidProduk) => stok[uuidProduk];

  static StokKantor DariJson(String? teks) {
    if (teks == null || teks.isEmpty) {
      return kosong;
    }
    try {
      final json = jsonDecode(teks);
      if (json is! Map<String, Object?>) {
        return kosong;
      }
      final peta = json['Stok'];
      return StokKantor(
        stok: {
          if (peta is Map<String, Object?>)
            for (final e in peta.entries)
              if (e.value is String && RegExp(r'^-?\d+(\.\d+)?$').hasMatch(e.value! as String))
                e.key: Kuantitas.Dari(e.value! as String),
        },
        diambilPada: json['DiambilPada'] is String ? DateTime.tryParse(json['DiambilPada']! as String)?.toUtc() : null,
      );
    } on FormatException {
      return kosong;
    }
  }
}

/// Modul Salesman di aplikasi POS (bagian 2, §9.7, SLS-11; server: Modul Salesman bagian 1).
///
/// - **Pelanggan** (offline): cache seluruh pelanggan aktif dari `GET salesman/pelanggan`, diunduh halaman demi halaman
///   saat online dan diganti utuh. Piutang per pelanggan dibaca online.
/// - **Kunjungan** (offline): mulai = catat `MasukPada` + coba lokasi sekali (gagal/ditolak tidak menahan kunjungan);
///   selesai = hasil + catatan, lalu baris lokal + item outbox `Kunjungan.Catat` dalam satu transaksi.
/// - **Pesanan** (offline): produk berstok biasa (jenis `Stok`, tanpa batch/seri) dari katalog perangkat, jumlah
///   desimal, **tanpa harga** di outbox `PesananGrosir.Buat` — harga ditentukan server dan pesanan masuk Draf untuk
///   dikonfirmasi back-office. Aplikasi hanya menampilkan perkiraan dari harga katalog lokal.
/// - Urutan kirim: pesanan selama kunjungan selalu masuk outbox **sebelum** kunjungannya selesai, sehingga di batch
///   sinkron FIFO pesanan diterima lebih dulu dan server menautkan kunjungan ke pesanan itu.
class LayananSalesman {
  LayananSalesman({
    required this.klien,
    required this.repositori,
    this.penentuLokasi = const PenentuLokasiTidakAda(),
    PembuatUlid? ulid,
    DateTime Function()? jam,
  }) : _jam = jam ?? DateTime.now,
       _ulid = ulid ?? PembuatUlid(jam: jam);

  static const String jenisOutboxPesanan = 'PesananGrosir.Buat';
  static const String jenisOutboxKunjungan = 'Kunjungan.Catat';

  /// Batas server (`PenanganSinkronBuatPesananGrosir::MAKS_BARIS`).
  static const int barisMaksimal = 200;
  static const int panjangCatatanPesanan = 500;
  static const int panjangCatatanKunjungan = 255;

  /// Batas digit bulat jumlah (kolom `DECIMAL(18,4)` server, pola `^\d{1,14}(\.\d{1,4})?$`).
  static const int digitBulatMaksimal = 14;

  /// Batas tampilan hasil cari produk (katalog besar tetap ringan).
  static const int batasHasilCari = 50;

  /// Pengaman unduhan halaman pelanggan (50 per halaman → 20.000 pelanggan).
  static const int halamanMaksimal = 400;

  static const String pesanPerluOnline = 'Perlu koneksi internet. Coba lagi saat online.';

  final KlienPos klien;
  final RepositoriSalesman repositori;
  final PenentuLokasi penentuLokasi;
  final PembuatUlid _ulid;
  final DateTime Function() _jam;

  // Izin & mode -----------------------------------------------------------------------------------------------------

  static bool CekBoleh(StafLokal staf) => staf.PunyaIzin(IzinSalesman.kunjungan);

  /// Pengguna yang izin POS-nya hanya salesman (seperti peran bawaan `Salesman`): ruang kerja dibuka langsung di modul
  /// Salesman tanpa shift kas. Pemilik & staf berizin jual/catat pesanan meja tetap memakai alur kasir biasa.
  static bool CekHanyaSalesman(StafLokal staf) =>
      !staf.pemilik &&
      staf.PunyaIzin(IzinSalesman.kunjungan) &&
      !staf.PunyaIzin(IzinKasir.penjualanBuat) &&
      !staf.PunyaIzin(IzinKasir.pesananMejaCatat);

  /// Ruang kerja mode Salesman: perangkat berjenis `Salesman`, atau pengguna yang hanya berizin salesman.
  static bool CekModeSalesman(StafLokal staf, String? jenisPerangkat) =>
      jenisPerangkat == IzinSalesman.jenisPerangkat || CekHanyaSalesman(staf);

  static void _PastikanBoleh(StafLokal staf) {
    if (!CekBoleh(staf)) {
      throw GalatKasir('TanpaIzin', '${staf.nama} tidak punya izin salesman.');
    }
  }

  // Pelanggan -------------------------------------------------------------------------------------------------------

  /// Saring pelanggan dengan [kata]: nama (tanpa beda huruf besar/kecil) atau nomor HP (angka saja; `0812…`,
  /// `62812…`, dan `+62 812…` dianggap sama).
  static List<PelangganSalesman> SaringPelanggan(List<PelangganSalesman> daftar, String kata) {
    final kunci = kata.trim().toLowerCase();
    if (kunci.isEmpty) {
      return daftar;
    }
    final angka = _NormalkanHp(kunci);
    final cariHp = angka.length >= 3 && RegExp(r'^[0-9+() .-]+$').hasMatch(kunci);
    return [
      for (final p in daftar)
        if (p.nama.toLowerCase().contains(kunci) || (cariHp && _NormalkanHp(p.noHp ?? '').contains(angka))) p,
    ];
  }

  static String _NormalkanHp(String teks) {
    final angka = teks.replaceAll(RegExp(r'\D'), '');
    return angka.startsWith('0') ? '62${angka.substring(1)}' : angka;
  }

  /// Unduh seluruh pelanggan (halaman demi halaman) lalu ganti cache. Hasil: jumlah pelanggan. Offline → galat
  /// `PerluOnline` dan cache lama tetap dipakai.
  Future<int> PerbaruiPelanggan(StafLokal staf) async {
    _PastikanBoleh(staf);
    final semua = <PelangganSalesmanPos>[];
    var halaman = 1;
    while (true) {
      final hasil = await _Online(() => klien.AmbilPelangganSalesman(uuidPengguna: staf.uuid, halaman: halaman));
      semua.addAll(hasil.pelanggan);
      if (!hasil.adaBerikutnya || hasil.pelanggan.isEmpty || halaman >= halamanMaksimal) {
        break;
      }
      halaman++;
    }
    await repositori.GantiPelanggan(semua, _jam());
    return semua.length;
  }

  /// Unduh snapshot stok kantor.
  Future<void> PerbaruiStok(StafLokal staf) async {
    _PastikanBoleh(staf);
    final stok = await _Online(() => klien.AmbilStokSalesman(uuidPengguna: staf.uuid));
    await repositori.SimpanStok(stok, _jam());
  }

  /// Piutang terbuka pelanggan (wajib online).
  Future<List<PiutangSalesmanPos>> AmbilPiutang(String uuidPelanggan, StafLokal staf) async {
    _PastikanBoleh(staf);
    return _Online(() => klien.AmbilPiutangSalesman(uuidPelanggan, uuidPengguna: staf.uuid));
  }

  /// Kunjungan [staf] yang sudah tercatat di server pada [tanggal] (`YYYY-MM-DD`; bawaan hari ini).
  Future<DaftarKunjunganSalesman> AmbilKunjunganServer(StafLokal staf, {String? tanggal}) async {
    _PastikanBoleh(staf);
    return _Online(() => klien.AmbilKunjunganSalesman(uuidPengguna: staf.uuid, tanggal: tanggal));
  }

  Future<T> _Online<T>(Future<T> Function() kerja) async {
    try {
      return await kerja();
    } on GalatJaringan {
      throw const GalatKasir('PerluOnline', pesanPerluOnline);
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }

  // Kunjungan -------------------------------------------------------------------------------------------------------

  /// Mulai kunjungan ke [pelanggan]: catat `MasukPada` sekarang (sebelum lokasi dicari, supaya aplikasi yang tertutup
  /// saat mencari lokasi tidak kehilangan kunjungan). Hasil: Uuid kunjungan. Lokasi dicari terpisah lewat
  /// [CatatLokasi]. Satu kunjungan berjalan per pengguna.
  Future<String> MulaiKunjungan(PelangganSalesman pelanggan, StafLokal staf) async {
    _PastikanBoleh(staf);
    final berjalan = await repositori.AmbilKunjunganBerjalan(staf.uuid);
    if (berjalan != null) {
      throw GalatKasir(
        'KunjunganBerjalan',
        'Masih berkunjung ke ${berjalan.NamaPelanggan}. Selesaikan kunjungan itu dulu.',
      );
    }
    final uuid = _ulid.Buat();
    await repositori.MulaiKunjungan(
      KunjunganSalesLokalCompanion.insert(
        Uuid: uuid,
        UuidPelanggan: pelanggan.uuid,
        NamaPelanggan: pelanggan.nama,
        UuidPengguna: staf.uuid,
        NamaPengguna: staf.nama,
        MasukPada: _jam().toUtc(),
      ),
      staf.uuid,
    );
    return uuid;
  }

  /// Coba ambil lokasi sekali dan simpan ke kunjungan berjalan [uuidKunjungan]. Null = lokasi tidak tersedia (izin
  /// ditolak, layanan mati, waktu habis); kunjungan tetap berjalan tanpa koordinat.
  Future<LokasiPerangkat?> CatatLokasi(String uuidKunjungan) async {
    LokasiPerangkat? lokasi;
    try {
      lokasi = await penentuLokasi.Ambil();
    } on Object {
      lokasi = null;
    }
    if (lokasi != null) {
      await repositori.SimpanLokasiKunjungan(
        uuidKunjungan,
        latitude: lokasi.latitude,
        longitude: lokasi.longitude,
        akurasiMeter: lokasi.akurasiMeter,
      );
    }
    return lokasi;
  }

  /// Hasil bawaan saat menyelesaikan [kunjungan]: Pesanan dibuat bila ada pesanan selama kunjungan.
  static HasilKunjungan AmbilHasilBawaan(BarisKunjunganSalesLokal kunjungan) =>
      kunjungan.UuidPesananGrosir != null ? HasilKunjungan.PesananDibuat : HasilKunjungan.TidakPesan;

  /// Selesaikan kunjungan berjalan [kunjungan] dengan [hasil] & [catatan]: baris lokal + outbox `Kunjungan.Catat`.
  Future<void> SelesaikanKunjungan(
    BarisKunjunganSalesLokal kunjungan, {
    required HasilKunjungan hasil,
    required StafLokal staf,
    String? catatan,
  }) async {
    _PastikanBoleh(staf);
    final teks = catatan?.trim();
    if (teks != null && teks.length > panjangCatatanKunjungan) {
      throw const GalatKasir('CatatanTerlaluPanjang', 'Catatan kunjungan maksimal 255 karakter.');
    }
    final keluar = _jam().toUtc();
    final masuk = kunjungan.MasukPada.toUtc();
    final selesai = keluar.isBefore(masuk) ? masuk : keluar;
    final adaCatatan = teks != null && teks.isNotEmpty;
    final data = SusunDataKunjungan(
      uuidPelanggan: kunjungan.UuidPelanggan,
      uuidPengguna: kunjungan.UuidPengguna,
      masukPada: masuk,
      keluarPada: selesai,
      lokasi: kunjungan.Latitude != null && kunjungan.Longitude != null
          ? LokasiPerangkat(
              latitude: kunjungan.Latitude!,
              longitude: kunjungan.Longitude!,
              akurasiMeter: kunjungan.AkurasiMeter,
            )
          : null,
      hasil: hasil,
      catatan: adaCatatan ? teks : null,
      uuidPesananGrosir: kunjungan.UuidPesananGrosir,
    );
    try {
      await repositori.SelesaikanKunjungan(
        kunjungan.Uuid,
        KunjunganSalesLokalCompanion(
          KeluarPada: Value(selesai),
          Hasil: Value(hasil.name),
          Catatan: Value(adaCatatan ? teks : null),
        ),
        ItemOutbox(jenis: jenisOutboxKunjungan, uuid: kunjungan.Uuid, data: data),
        keluar,
      );
    } on StateError {
      throw const GalatKasir('KunjunganSudahSelesai', 'Kunjungan ini sudah diselesaikan.');
    }
  }

  /// `Data` item outbox `Kunjungan.Catat` persis bentuk server (`PenanganSinkronCatatKunjungan`): koordinat & akurasi
  /// hanya bila lokasi ada (berpasangan), kunci opsional lain hanya bila terisi.
  static Map<String, Object?> SusunDataKunjungan({
    required String uuidPelanggan,
    required String uuidPengguna,
    required DateTime masukPada,
    DateTime? keluarPada,
    LokasiPerangkat? lokasi,
    required HasilKunjungan hasil,
    String? catatan,
    String? uuidPesananGrosir,
  }) => {
    'UuidPelanggan': uuidPelanggan,
    'UuidPengguna': uuidPengguna,
    'MasukPada': FormatWaktuKirim(masukPada),
    if (keluarPada != null) 'KeluarPada': FormatWaktuKirim(keluarPada),
    if (lokasi != null) ...{
      'Latitude': lokasi.latitude,
      'Longitude': lokasi.longitude,
      if (lokasi.akurasiMeter != null) 'AkurasiMeter': lokasi.akurasiMeter,
    },
    'Hasil': hasil.name,
    if (catatan != null && catatan.isNotEmpty) 'Catatan': catatan,
    'UuidPesananGrosir': ?uuidPesananGrosir,
  };

  /// Waktu ISO-8601 UTC berakhiran `Z` (cocok `ValidasiItemSinkron::POLA_WAKTU`).
  static String FormatWaktuKirim(DateTime waktu) => waktu.toUtc().toIso8601String();

  // Pesanan ---------------------------------------------------------------------------------------------------------

  /// Produk yang bisa dipesan lewat salesman: cakupan grosir bagian 1 server (`SimpanPesananGrosir`) = aktif, jenis
  /// `Stok`, tanpa pelacakan batch/seri, bukan paket sesi, dan punya satuan.
  static bool CekBolehDipesan(ProdukJual p) =>
      p.aktif &&
      p.jenis == 'Stok' &&
      p.pelacakan == JenisProdukKasir.pelacakanTidak &&
      !p.paketSesi &&
      p.satuan.isNotEmpty;

  /// Produk yang bisa dipesan, disaring [kata] (nama/SKU, atau barcode/SKU persis di depan), urut nama, maksimal
  /// [batasHasilCari].
  static List<ProdukJual> CariProduk(KatalogLokal katalog, String kata) {
    final kunci = kata.trim().toLowerCase();
    final dariKode = kunci.isEmpty ? null : katalog.CariKode(kata)?.produk;
    return <ProdukJual>[
      if (dariKode != null && CekBolehDipesan(dariKode)) dariKode,
      for (final p in katalog.produk)
        if (p != dariKode &&
            CekBolehDipesan(p) &&
            (kunci.isEmpty || p.nama.toLowerCase().contains(kunci) || (p.sku?.toLowerCase().contains(kunci) ?? false)))
          p,
    ].take(batasHasilCari).toList();
  }

  /// Satuan bawaan pesanan: satuan barcode (bila dipindai), selain itu satuan jual bawaan produk.
  static SatuanJual AmbilSatuanBawaan(ProdukJual p, {SatuanJual? dariBarcode}) =>
      dariBarcode ?? p.AmbilSatuanBawaan() ?? p.satuan.first;

  /// Baca teks jumlah (koma/titik desimal) menjadi [Kuantitas] pada [satuan]; galat berbahasa Indonesia bila kosong,
  /// bukan angka, ≤ 0, lebih dari 4 desimal, terlalu besar, atau desimal pada satuan bulat.
  static Kuantitas BacaJumlah(String teks, SatuanJual satuan) {
    final rapi = teks.trim().replaceAll(' ', '').replaceAll(',', '.');
    if (rapi.isEmpty) {
      throw const GalatKasir('JumlahKosong', 'Isi jumlah pesanan.');
    }
    if (!RegExp(r'^\d+(\.\d+)?$').hasMatch(rapi)) {
      throw const GalatKasir('JumlahTidakValid', 'Jumlah harus berupa angka, misal 12 atau 2,5.');
    }
    final nilai = Decimal.parse(rapi);
    if (nilai <= Decimal.zero) {
      throw const GalatKasir('JumlahTidakValid', 'Jumlah pesanan harus lebih dari 0.');
    }
    if (nilai.scale > Kuantitas.skala) {
      throw const GalatKasir('JumlahTidakValid', 'Jumlah maksimal 4 angka di belakang koma.');
    }
    if (nilai.toBigInt().toString().length > digitBulatMaksimal) {
      throw const GalatKasir('JumlahTidakValid', 'Jumlah terlalu besar. Periksa lagi angkanya.');
    }
    if (!satuan.bolehDesimal && !nilai.isInteger) {
      throw GalatKasir('JumlahTidakValid', 'Satuan ${satuan.nama} harus bilangan bulat.');
    }
    return Kuantitas.DariDesimal(nilai);
  }

  /// Perkiraan nilai [baris] dari harga katalog lokal (price engine yang sama dengan kasir, tier pelanggan,
  /// [uuidOutlet], tanpa kanal). Baris tanpa harga dilewati dan ditandai [PerkiraanPesanan.adaTanpaHarga].
  static PerkiraanPesanan HitungPerkiraan(
    List<BarisPesananSalesman> baris,
    KatalogLokal katalog, {
    String? tierPelanggan,
    String? uuidOutlet,
    required DateTime waktu,
  }) {
    var total = Uang.Nol();
    var adaTanpaHarga = false;
    final harga = <Uang?>[];
    for (final b in baris) {
      final hasil = b.jumlah.BernilaiNol() || b.jumlah.BernilaiNegatif()
          ? null
          : const PenentuHarga().Tentukan(
              katalog.AmbilKatalogHarga(b.produk.uuid),
              PermintaanHarga(
                uuidProduk: b.produk.uuid,
                uuidProdukSatuan: b.satuan.uuid,
                jumlah: b.jumlah,
                uuidOutlet: uuidOutlet,
                kanal: null,
                tierPelanggan: tierPelanggan,
                waktu: waktu.toUtc(),
              ),
            );
      harga.add(hasil?.harga);
      if (hasil == null) {
        adaTanpaHarga = true;
      } else {
        total = total.Tambah(hasil.harga.Kali(b.jumlah.KeDesimal()));
      }
    }
    return PerkiraanPesanan(total: total, hargaSatuan: harga, adaTanpaHarga: adaTanpaHarga);
  }

  /// `Data` item outbox `PesananGrosir.Buat` persis bentuk server (`PenanganSinkronBuatPesananGrosir`): **tanpa harga**,
  /// jumlah string desimal 4 angka, satuan = Uuid `ProdukSatuan`.
  static Map<String, Object?> SusunDataPesanan({
    required String uuidPelanggan,
    required String uuidPengguna,
    required DateTime dibuatPada,
    required List<BarisPesananSalesman> baris,
    String? catatan,
    String? uuidKunjungan,
  }) => {
    'UuidPelanggan': uuidPelanggan,
    'UuidPengguna': uuidPengguna,
    'DibuatPada': FormatWaktuKirim(dibuatPada),
    if (catatan != null && catatan.isNotEmpty) 'Catatan': catatan,
    'UuidKunjungan': ?uuidKunjungan,
    'Baris': [
      for (final b in baris) {'UuidProduk': b.produk.uuid, 'UuidSatuan': b.satuan.uuid, 'Jumlah': b.jumlah.KeString()},
    ],
  };

  /// Kirim pesanan [baris] untuk [pelanggan] oleh [staf]: baris lokal + outbox `PesananGrosir.Buat` dalam satu
  /// transaksi. Bila [staf] sedang berkunjung ke pelanggan ini, pesanan ditautkan ke kunjungannya. Hasil: Uuid
  /// pesanan. [perkiraan] hanya disimpan untuk riwayat.
  Future<String> KirimPesanan({
    required PelangganSalesman pelanggan,
    required List<BarisPesananSalesman> baris,
    required StafLokal staf,
    PerkiraanPesanan? perkiraan,
    String? catatan,
  }) async {
    _PastikanBoleh(staf);
    return _SimpanPesanan(
      uuidPelanggan: pelanggan.uuid,
      namaPelanggan: pelanggan.nama,
      baris: baris,
      staf: staf,
      perkiraan: perkiraan,
      catatan: catatan,
      tautkanKunjungan: true,
    );
  }

  // SO cepat di layar Jual (POS-05) ---------------------------------------------------------------------------------

  /// Kasir berizin jual (`penjualan.buat`) atau salesman boleh menjadikan keranjang pesanan grosir (SO).
  static bool CekBolehBuatSo(StafLokal staf) => CekBoleh(staf) || staf.PunyaIzin(IzinKasir.penjualanBuat);

  /// Alasan [keranjang] belum bisa dijadikan pesanan grosir; null = bisa. Pesanan grosir tidak membawa harga, diskon,
  /// ongkir, pilihan, maupun nomor seri dari perangkat: server yang menentukan harga (price engine & tier pelanggan)
  /// dan konfirmasinya di back-office, jadi apa pun yang diubah kasir di keranjang tidak akan sampai ke pesanan.
  /// Menerimanya diam-diam berarti pembeli dijanjikan harga yang tidak pernah dipakai.
  static String? AlasanKeranjangTidakBisaJadiSo(Keranjang keranjang, KatalogLokal katalog) {
    if (keranjang.CekKosong) {
      return 'Keranjang masih kosong. Tambahkan barang pesanan dulu.';
    }
    if (keranjang.pelanggan == null) {
      return 'Pilih pelanggan dulu. Pesanan grosir wajib atas nama pelanggan.';
    }
    if (keranjang.pesananMeja != null ||
        keranjang.voucher != null ||
        keranjang.tukarPoin != null ||
        keranjang.praPesan != null ||
        keranjang.reservasi != null ||
        keranjang.perintahKerja != null ||
        keranjang.laundry != null ||
        keranjang.tukar != null) {
      return 'Pesanan grosir tidak bisa dari pesanan meja, pre-order, voucher, tukar poin, reservasi, servis, laundry, '
          'atau tukar barang. Kosongkan itu dulu, lalu coba lagi.';
    }
    if (keranjang.diskonPesanan != null ||
        keranjang.biayaKirim.Bandingkan(Uang.Nol()) > 0 ||
        keranjang.baris.any((b) => b.diskon != null || b.hargaAsli != null || b.hargaTerbuka)) {
      return 'Pesanan grosir memakai harga dari server. Hapus diskon manual, harga manual, dan ongkir di keranjang, '
          'lalu coba lagi.';
    }
    for (final b in keranjang.baris) {
      final produk = katalog.CariProduk(b.uuidProduk);
      if (produk == null || !CekBolehDipesan(produk)) {
        return '"${b.nama}" tidak bisa dipesan lewat pesanan grosir (hanya produk berstok biasa).';
      }
      if (b.pilihan.isNotEmpty || b.nomorSeri.isNotEmpty || b.racikan != null) {
        return '"${b.nama}" memakai pilihan, nomor seri, atau racikan. Hapus dulu dari keranjang atau buat pesanannya di '
            'back-office.';
      }
    }
    return null;
  }

  /// Baris pesanan dari [keranjang] (satuan keranjang; bawaan produk bila kosong). Panggil setelah
  /// [AlasanKeranjangTidakBisaJadiSo] bernilai null.
  static List<BarisPesananSalesman> BarisDariKeranjang(Keranjang keranjang, KatalogLokal katalog) => [
    for (final b in keranjang.baris)
      () {
        final produk = katalog.CariProduk(b.uuidProduk)!;
        final satuan = produk.satuan.where((s) => s.uuid == b.uuidProdukSatuan).firstOrNull;
        return BarisPesananSalesman(produk: produk, satuan: satuan ?? AmbilSatuanBawaan(produk), jumlah: b.jumlah);
      }(),
  ];

  /// Jadikan [keranjang] (wajib ber-pelanggan) pesanan grosir: perkiraan harga dari katalog lokal untuk riwayat, lalu
  /// baris lokal + outbox `PesananGrosir.Buat` dalam satu transaksi (offline). Pesanan masuk **Draf** di server dan
  /// dikonfirmasi di back-office; stok baru berkurang saat surat jalan. Hasil: Uuid pesanan.
  Future<String> KirimPesananDariKeranjang({
    required Keranjang keranjang,
    required KatalogLokal katalog,
    required StafLokal staf,
    String? uuidOutlet,
    String? catatan,
  }) async {
    if (!CekBolehBuatSo(staf)) {
      throw GalatKasir('TanpaIzin', '${staf.nama} tidak punya izin membuat pesanan grosir.');
    }
    final alasan = AlasanKeranjangTidakBisaJadiSo(keranjang, katalog);
    if (alasan != null) {
      throw GalatKasir('KeranjangBelumDidukung', alasan);
    }
    final pelanggan = keranjang.pelanggan!;
    final baris = BarisDariKeranjang(keranjang, katalog);
    final perkiraan = HitungPerkiraan(
      baris,
      katalog,
      tierPelanggan: pelanggan.kodeTier,
      uuidOutlet: uuidOutlet,
      waktu: _jam(),
    );
    return _SimpanPesanan(
      uuidPelanggan: pelanggan.uuid,
      namaPelanggan: pelanggan.nama,
      baris: baris,
      staf: staf,
      perkiraan: perkiraan,
      catatan: catatan,
      tautkanKunjungan: false,
    );
  }

  Future<String> _SimpanPesanan({
    required String uuidPelanggan,
    required String namaPelanggan,
    required List<BarisPesananSalesman> baris,
    required StafLokal staf,
    required bool tautkanKunjungan,
    PerkiraanPesanan? perkiraan,
    String? catatan,
  }) async {
    if (baris.isEmpty) {
      throw const GalatKasir('PesananKosong', 'Tambahkan minimal satu produk.');
    }
    if (baris.length > barisMaksimal) {
      throw const GalatKasir('BarisTerlaluBanyak', 'Satu pesanan maksimal 200 baris. Pecah menjadi dua pesanan.');
    }
    for (final b in baris) {
      if (!CekBolehDipesan(b.produk)) {
        throw GalatKasir('ProdukTidakBisaDipesan', '"${b.produk.nama}" tidak bisa dipesan lewat salesman.');
      }
      if (!b.produk.satuan.any((s) => s.uuid == b.satuan.uuid)) {
        throw GalatKasir('SatuanTidakValid', 'Satuan ${b.satuan.nama} bukan satuan ${b.produk.nama}.');
      }
      if (b.jumlah.BernilaiNol() || b.jumlah.BernilaiNegatif()) {
        throw GalatKasir('JumlahTidakValid', 'Jumlah ${b.produk.nama} harus lebih dari 0.');
      }
    }
    final teks = catatan?.trim();
    if (teks != null && teks.length > panjangCatatanPesanan) {
      throw const GalatKasir('CatatanTerlaluPanjang', 'Catatan pesanan maksimal 500 karakter.');
    }
    final adaCatatan = teks != null && teks.isNotEmpty;
    final berjalan = tautkanKunjungan ? await repositori.AmbilKunjunganBerjalan(staf.uuid) : null;
    final uuidKunjungan = berjalan != null && berjalan.UuidPelanggan == uuidPelanggan ? berjalan.Uuid : null;
    final sekarang = _jam().toUtc();
    final uuid = _ulid.Buat();
    final data = SusunDataPesanan(
      uuidPelanggan: uuidPelanggan,
      uuidPengguna: staf.uuid,
      dibuatPada: sekarang,
      baris: baris,
      catatan: adaCatatan ? teks : null,
      uuidKunjungan: uuidKunjungan,
    );
    await repositori.SimpanPesanan(
      PesananGrosirLokalCompanion.insert(
        Uuid: uuid,
        UuidPelanggan: uuidPelanggan,
        NamaPelanggan: namaPelanggan,
        UuidPengguna: staf.uuid,
        NamaPengguna: staf.nama,
        UuidKunjungan: Value(uuidKunjungan),
        Catatan: Value(adaCatatan ? teks : null),
        Baris: jsonEncode([
          for (final (i, b) in baris.indexed)
            {
              'UuidProduk': b.produk.uuid,
              'NamaProduk': b.produk.nama,
              'UuidSatuan': b.satuan.uuid,
              'NamaSatuan': b.satuan.nama,
              'Jumlah': b.jumlah.KeString(),
              'PerkiraanHarga': perkiraan == null || i >= perkiraan.hargaSatuan.length
                  ? null
                  : perkiraan.hargaSatuan[i]?.KeString(),
            },
        ]),
        JumlahBaris: baris.length,
        PerkiraanTotal: (perkiraan?.total ?? Uang.Nol()).KeString(),
        DibuatPada: sekarang,
      ),
      ItemOutbox(jenis: jenisOutboxPesanan, uuid: uuid, data: data),
      sekarang,
      uuidKunjungan: uuidKunjungan,
    );
    return uuid;
  }

  /// Jumlah tanpa nol di belakang koma dengan koma desimal: `12.0000` → `12`, `2.5000` → `2,5`.
  static String FormatJumlah(Kuantitas jumlah) => jumlah.KeDesimal().toString().replaceAll('.', ',');
}
