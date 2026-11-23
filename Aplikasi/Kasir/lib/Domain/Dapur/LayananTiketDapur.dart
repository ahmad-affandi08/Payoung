import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';

import 'package:mesin_kasir/MesinKasir.dart' show KanalPenjualan;

import '../../Data/PesananMeja.dart';
import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../Katalog/KatalogLokal.dart';
import '../Penjualan/LayananPenjualan.dart';
import '../Struk/LayananStruk.dart';
import '../Struk/PenyusunDokumenKasir.dart';
import '../Struk/ProfilPrinter.dart';

/// Stasiun dapur aktif outlet untuk perutean tiket tercetak.
class StasiunDapurLokal {
  const StasiunDapurLokal(this.uuid, this.nama, {this.salinan = false});

  final String uuid;
  final String nama;

  /// D-310: stasiun checker. Tidak memasak: hanya mencetak salinan semua tiket yang dikirim ke dapur.
  final bool salinan;
}

/// Perutean tiket dapur di perangkat (cetak struk bagian 4c), sama dengan server (F-10b): stasiun yang diatur
/// langsung di menu (D-310, varian ikut produk induknya) menang atas stasiun kategori produk, yang diwarisi dari
/// kategori induk terdekat; menu tanpa stasiun → stasiun bawaan; tanpa stasiun sama sekali → tidak ada tiket.
/// Stasiun checker (salinan) tidak ikut perutean: ia hanya menerima salinan semua tiket. Data dari
/// `GET /api/pos/v1/meja` (disimpan saat data meja diperbarui) sehingga jalan offline.
class RuteDapur {
  const RuteDapur({this.stasiun = const [], this.uuidBawaan, this.kategori = const {}, this.produk = const {}});

  final List<StasiunDapurLokal> stasiun;
  final String? uuidBawaan;

  /// Uuid stasiun per Uuid kategori yang diatur langsung.
  final Map<String, String> kategori;

  /// D-310: Uuid stasiun per Uuid produk yang diatur langsung (menimpa kategori).
  final Map<String, String> produk;

  /// Stasiun checker (salinan) yang aktif.
  List<StasiunDapurLokal> get salinan => [
    for (final s in stasiun)
      if (s.salinan) s,
  ];

  /// Stasiun yang memasak (bukan checker): tujuan kategori dan menu, serta layar dapur.
  List<StasiunDapurLokal> get memasak => [
    for (final s in stasiun)
      if (!s.salinan) s,
  ];

  static Future<RuteDapur> Muat(RepositoriKasir repositori) async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.ruteDapur);
    try {
      final json = teks == null || teks.isEmpty ? null : jsonDecode(teks);
      if (json is! Map<String, Object?>) {
        return const RuteDapur();
      }
      final daftar = json['Stasiun'];
      final peta = json['Kategori'];
      final petaProduk = json['Produk'];
      return RuteDapur(
        stasiun: [
          if (daftar is List<Object?>)
            for (final s in daftar.whereType<Map<String, Object?>>())
              if (s['Uuid'] case final String uuid)
                StasiunDapurLokal(
                  uuid,
                  s['Nama'] is String ? s['Nama']! as String : uuid,
                  salinan: s['Salinan'] == true,
                ),
        ],
        uuidBawaan: json['Bawaan'] is String ? json['Bawaan']! as String : null,
        kategori: {
          if (peta is Map<String, Object?>)
            for (final e in peta.entries)
              if (e.value case final String uuidStasiun) e.key: uuidStasiun,
        },
        produk: {
          if (petaProduk is Map<String, Object?>)
            for (final e in petaProduk.entries)
              if (e.value case final String uuidStasiun) e.key: uuidStasiun,
        },
      );
    } on FormatException {
      return const RuteDapur();
    }
  }

  StasiunDapurLokal? CariStasiun(String uuid) => stasiun.where((s) => s.uuid == uuid).firstOrNull;

  /// Salinan dengan peta kategori/produk diganti (D-310: setelah kasir mengatur stasiun, tanpa menunggu sinkron).
  RuteDapur Salin({Map<String, String>? kategori, Map<String, String>? produk}) => RuteDapur(
    stasiun: stasiun,
    uuidBawaan: uuidBawaan,
    kategori: kategori ?? this.kategori,
    produk: produk ?? this.produk,
  );

  /// Bentuk simpan yang sama dengan yang ditulis saat data meja diperbarui (`RepositoriPesananMeja`).
  Map<String, Object?> KeJson() => {
    'Stasiun': [
      for (final s in stasiun) {'Uuid': s.uuid, 'Nama': s.nama, 'Salinan': s.salinan},
    ],
    'Bawaan': uuidBawaan,
    'Kategori': kategori,
    'Produk': produk,
  };

  Future<void> Simpan(RepositoriKasir repositori) =>
      repositori.SimpanPengaturan(KunciPengaturan.ruteDapur, jsonEncode(KeJson()));

  /// Stasiun untuk produk berkategori [uuidKategori]; [indukKategori] = Uuid induk per Uuid kategori. D-310: stasiun
  /// yang diatur langsung di produk ([uuidProduk], atau produk induknya untuk varian) menang atas kategori.
  StasiunDapurLokal? AmbilStasiun(
    String? uuidKategori,
    Map<String, String?> indukKategori, {
    String? uuidProduk,
    String? uuidIndukProduk,
  }) {
    for (final kunci in [uuidProduk, uuidIndukProduk]) {
      final uuidLangsung = kunci == null ? null : produk[kunci];
      final langsung = uuidLangsung == null ? null : CariStasiun(uuidLangsung);
      if (langsung != null && !langsung.salinan) {
        return langsung;
      }
    }
    var kategoriSaatIni = uuidKategori;
    for (var langkah = 0; kategoriSaatIni != null && langkah < 5; langkah++) {
      final uuidStasiun = kategori[kategoriSaatIni];
      if (uuidStasiun != null) {
        final hasil = CariStasiun(uuidStasiun);
        if (hasil != null) {
          return hasil;
        }
        break;
      }
      kategoriSaatIni = indukKategori[kategoriSaatIni];
    }
    final bawaan = uuidBawaan;
    return bawaan == null ? null : CariStasiun(bawaan);
  }
}

/// Printer tiket satu stasiun di perangkat ini: printer struk perangkat, atau printer jaringan sendiri.
class PrinterDapur {
  const PrinterDapur.Struk() : profil = null;

  const PrinterDapur.Sendiri(ProfilPrinter this.profil);

  /// Null = memakai printer struk perangkat ini.
  final ProfilPrinter? profil;

  bool get samaDenganStruk => profil == null;

  Map<String, Object?> KeJson() => profil == null ? {'SamaDenganStruk': true} : profil!.KeJson();

  static PrinterDapur? DariJson(Object? json) {
    if (json is Map<String, Object?> && json['SamaDenganStruk'] == true) {
      return const PrinterDapur.Struk();
    }
    final profil = ProfilPrinter.DariJson(json);
    return profil == null ? null : PrinterDapur.Sendiri(profil);
  }

  /// Printer dapur per Uuid stasiun (stasiun tanpa printer tidak ada di peta = tiketnya tidak dicetak di perangkat ini).
  static Future<Map<String, PrinterDapur>> MuatSemua(RepositoriKasir repositori) async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.printerDapur);
    try {
      final json = teks == null || teks.isEmpty ? null : jsonDecode(teks);
      return {
        if (json is Map<String, Object?>)
          for (final e in json.entries)
            if (DariJson(e.value) case final PrinterDapur p) e.key: p,
      };
    } on FormatException {
      return const {};
    }
  }

  static Future<void> SimpanSemua(RepositoriKasir repositori, Map<String, PrinterDapur> peta) =>
      repositori.SimpanPengaturan(
        KunciPengaturan.printerDapur,
        jsonEncode({for (final e in peta.entries) e.key: e.value.KeJson()}),
      );
}

/// Hasil cetak tiket satu stasiun.
class HasilTiketDapur {
  const HasilTiketDapur(this.stasiun, this.galat);

  final StasiunDapurLokal stasiun;

  /// Null = tercetak.
  final String? galat;
}

/// Cetak tiket dapur per stasiun (cetak struk bagian 4c, §17.2.5 "tiket dapur dikirim ke printer per station") saat
/// pesanan meja dikirim ke dapur. Hanya stasiun yang punya printer di perangkat ini yang dicetak (stasiun lain memakai
/// layar dapur/KDS atau perangkat lain). Semua dari data lokal sehingga jalan offline; gagal cetak tidak membatalkan
/// kiriman (tiket tetap masuk KDS lewat sinkron).
class LayananTiketDapur {
  LayananTiketDapur({required this.repositori, required this.struk, required this.penjualan});

  final RepositoriKasir repositori;
  final LayananStruk struk;
  final RepositoriPenjualan penjualan;

  /// Apakah ada printer dapur di perangkat ini.
  Future<bool> CekAdaPrinter() async => (await PrinterDapur.MuatSemua(repositori)).isNotEmpty;

  /// Tiket dapur penjualan langsung (mode cepat, bayar dulu): semua baris penjualan [uuidPenjualan]; judul tiket =
  /// nama pelanggan atau kanal ("Bawa pulang"/"Makan di tempat").
  Future<List<HasilTiketDapur>> CetakPenjualan(
    String uuidPenjualan, {
    required KatalogLokal katalog,
    String? namaPelanggan,
    bool cetakUlang = false,
  }) async {
    final jual = await penjualan.CariPenjualan(uuidPenjualan);
    if (jual == null) {
      return const [];
    }
    final detail = await penjualan.AmbilDetail(uuidPenjualan);
    final baris = [
      for (final d in detail)
        BarisPesananMeja(
          uuid: d.Uuid,
          uuidProduk: d.UuidProduk,
          uuidProdukSatuan: d.UuidProdukSatuan,
          namaProduk: d.NamaProduk,
          jumlah: d.Jumlah,
          hargaSatuan: d.HargaSatuan,
          hargaPilihan: d.HargaPilihan,
          pilihan: _UraiPilihan(d.Pilihan),
          catatan: d.Catatan,
          dikirimKeDapur: true,
        ),
    ];
    final pesanan = PesananMeja(
      uuid: jual.Uuid,
      nomor: jual.Nomor,
      uuidMeja: null,
      namaMeja: null,
      // v3.52: nomor antrian + nama pemesan dipanggil saat pesanan siap; tanpa itu nama pelanggan atau jenis pesanan.
      label:
          [
            if (jual.NomorAntrian case final antrian?) '#$antrian',
            ?(jual.NamaPemesan ?? namaPelanggan),
          ].join(' ').nonKosong ??
          LayananPenjualan.AmbilLabelKanal(
            KanalPenjualan.values.where((k) => k.name == jual.Kanal).firstOrNull ?? KanalPenjualan.BawaPulang,
          ),
      jumlahTamu: 0,
      dibukaOleh: jual.UuidPengguna,
      dibukaPada: jual.DibuatPada,
      status: jual.Status,
      dikunciBayar: false,
      baris: baris,
    );
    return Cetak(
      pesanan: pesanan,
      uuidBaris: baris.map((b) => b.uuid),
      katalog: katalog,
      waktu: jual.DibuatPada,
      namaKasir: jual.NamaKasir,
      cetakUlang: cetakUlang,
    );
  }

  static List<Map<String, Object?>> _UraiPilihan(String teks) {
    try {
      final json = jsonDecode(teks);
      return json is List<Object?> ? json.whereType<Map<String, Object?>>().toList() : const [];
    } on FormatException {
      return const [];
    }
  }

  /// Cetak baris [uuidBaris] pesanan [pesanan] ke printer stasiunnya.
  Future<List<HasilTiketDapur>> Cetak({
    required PesananMeja pesanan,
    required Iterable<String> uuidBaris,
    required KatalogLokal katalog,
    required DateTime waktu,
    String? namaKasir,
    bool cetakUlang = false,
  }) async {
    final printer = await PrinterDapur.MuatSemua(repositori);
    if (printer.isEmpty) {
      return const [];
    }
    final rute = await RuteDapur.Muat(repositori);
    final induk = {for (final k in katalog.kategori) k.Uuid: k.UuidInduk};
    final dipilih = uuidBaris.toSet();
    final perStasiun = <StasiunDapurLokal, List<BarisPesananMeja>>{};
    // D-310: untuk salinan checker, kelompokkan semua baris yang dikirim menurut stasiun memasaknya (urut stasiun).
    final kelompokSalinan = <String, ({String nama, List<BarisPesananMeja> baris})>{};
    final urutStasiun = {for (var i = 0; i < rute.stasiun.length; i++) rute.stasiun[i].uuid: i};
    for (final b in pesanan.AmbilBarisAktif().where((b) => dipilih.contains(b.uuid))) {
      final produk = b.uuidProduk == null ? null : katalog.CariProduk(b.uuidProduk!);
      final indukProduk = produk?.uuidInduk == null ? null : katalog.CariProduk(produk!.uuidInduk!);
      // Varian anak mengikuti kategori produk induknya bila ia sendiri tidak punya kategori (sama dengan server).
      final stasiun = rute.AmbilStasiun(
        produk?.uuidKategori ?? indukProduk?.uuidKategori,
        induk,
        uuidProduk: produk?.uuid ?? b.uuidProduk,
        uuidIndukProduk: produk?.uuidInduk,
      );
      if (stasiun != null && printer.containsKey(stasiun.uuid)) {
        (perStasiun[stasiun] ??= []).add(b);
      }
      final kunciKelompok = stasiun?.uuid ?? '';
      (kelompokSalinan[kunciKelompok] ??= (nama: stasiun?.nama ?? 'Tanpa stasiun', baris: [])).baris.add(b);
    }

    final hasil = <HasilTiketDapur>[];
    for (final MapEntry(key: stasiun, value: baris) in perStasiun.entries) {
      final dokumen = PenyusunDokumenKasir.SusunTiketDapur(
        namaStasiun: stasiun.nama,
        pesanan: pesanan,
        baris: baris,
        waktu: waktu,
        namaKasir: namaKasir,
        cetakUlang: cetakUlang,
      );
      try {
        await struk.CetakDokumenKe(printer[stasiun.uuid]!.profil, dokumen);
        hasil.add(HasilTiketDapur(stasiun, null));
      } on GalatPrinter catch (galat) {
        hasil.add(HasilTiketDapur(stasiun, galat.pesan));
      }
    }

    // D-310: stasiun checker mencetak satu tiket salinan berisi semua baris yang dikirim, dikelompokkan per stasiun.
    if (kelompokSalinan.isNotEmpty) {
      final urut = kelompokSalinan.entries.toList()
        ..sort((a, b) => (urutStasiun[a.key] ?? 1 << 20).compareTo(urutStasiun[b.key] ?? 1 << 20));
      for (final checker in rute.salinan.where((s) => printer.containsKey(s.uuid))) {
        final dokumen = PenyusunDokumenKasir.SusunTiketDapur(
          namaStasiun: checker.nama,
          pesanan: pesanan,
          baris: [for (final e in urut) ...e.value.baris],
          waktu: waktu,
          namaKasir: namaKasir,
          cetakUlang: cetakUlang,
          kelompokSalinan: [for (final e in urut) (stasiun: e.value.nama, baris: e.value.baris)],
        );
        try {
          await struk.CetakDokumenKe(printer[checker.uuid]!.profil, dokumen);
          hasil.add(HasilTiketDapur(checker, null));
        } on GalatPrinter catch (galat) {
          hasil.add(HasilTiketDapur(checker, galat.pesan));
        }
      }
    }
    return hasil;
  }
}

extension on String {
  String? get nonKosong => trim().isEmpty ? null : this;
}
