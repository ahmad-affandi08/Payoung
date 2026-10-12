/// Model `GET /api/pos/v1/katalog?sejak=` (F-03 D.3, PRD §16.3). Key JSON = nama kolom PascalCase; FK sebagai
/// `Uuid{Tabel}`; uang & jumlah string desimal. Bagian yang belum dipakai POS fase 1 (Resep, PaketProdukDetail)
/// diabaikan karena stok dihitung server (Rincian F-07b).
library;

import 'UraiJson.dart';

class KategoriPos {
  const KategoriPos({required this.uuid, required this.uuidInduk, required this.nama, required this.urutan});

  final String uuid;
  final String? uuidInduk;
  final String nama;
  final int urutan;

  static KategoriPos DariJson(Map<String, Object?> json) => KategoriPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidInduk: UraiJson.AmbilTeksAtauNull(json['UuidInduk']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
  );
}

class SatuanPos {
  const SatuanPos({required this.uuid, required this.nama, required this.simbol, required this.bolehDesimal});

  final String uuid;
  final String nama;
  final String? simbol;
  final bool bolehDesimal;

  static SatuanPos DariJson(Map<String, Object?> json) => SatuanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    simbol: UraiJson.AmbilTeksAtauNull(json['Simbol']),
    bolehDesimal: UraiJson.AmbilBenar(json['BolehDesimal']),
  );
}

/// Satu jenis pajak di kelompok pajak produk (kode jenis, bukan tarif; tarif dari `TarifPajak`, CLAUDE.md #12).
class PajakKelompokPos {
  const PajakKelompokPos({
    required this.kodeJenisPajak,
    required this.dasarPengenaan,
    required this.urutan,
    this.kategori,
    this.kenaBiayaKirim = false,
  });

  static const String kategoriPpn = 'Ppn';
  static const String kategoriPbjt = 'Pbjt';
  static const String kategoriLainnya = 'Lainnya';

  final String kodeJenisPajak;

  /// Kategori jenis pajak (`Ppn`, `Pbjt`, `Lainnya`) dari atribut `JenisPajak` (PRD v1.46); null bila server lama
  /// tidak mengirimnya atau nilainya tidak dikenal.
  final String? kategori;

  /// `Subtotal` atau `SubtotalPlusLayanan`.
  final String dasarPengenaan;

  /// F-17 bagian 3: ongkir yang ditagih ke pembeli ikut DPP pajak ini. False bila server lama tidak mengirimnya.
  final bool kenaBiayaKirim;
  final int urutan;

  static PajakKelompokPos DariJson(Map<String, Object?> json) => PajakKelompokPos(
    kodeJenisPajak: UraiJson.AmbilTeks(json['KodeJenisPajak']),
    dasarPengenaan: UraiJson.AmbilTeks(json['DasarPengenaan'], 'Subtotal'),
    kenaBiayaKirim: UraiJson.AmbilBenar(json['KenaBiayaKirim']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
    kategori: switch (json['Kategori']) {
      final String k when k == kategoriPpn || k == kategoriPbjt || k == kategoriLainnya => k,
      _ => null,
    },
  );
}

class KelompokPajakPos {
  const KelompokPajakPos({required this.uuid, required this.nama, required this.kategori, required this.pajak});

  final String uuid;
  final String nama;
  final String? kategori;
  final List<PajakKelompokPos> pajak;

  static KelompokPajakPos DariJson(Map<String, Object?> json) => KelompokPajakPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    kategori: UraiJson.AmbilTeksAtauNull(json['Kategori']),
    pajak: UraiJson.AmbilDaftarPeta(json['Pajak']).map(PajakKelompokPos.DariJson).toList(),
  );
}

class ProdukPos {
  const ProdukPos({
    required this.uuid,
    required this.sku,
    required this.nama,
    required this.namaStruk,
    required this.jenis,
    required this.uuidKategori,
    required this.uuidSatuanDasar,
    required this.pelacakan,
    required this.uuidKelompokPajak,
    required this.hargaTermasukPajak,
    required this.tampilDiPos,
    this.favorit = false,
    required this.uuidInduk,
    required this.urlGambarKecil,
    required this.aktif,
    required this.dihapus,
    this.jumlahSesiPaket,
    this.masaGaransiBulan,
    this.atributVarian,
    this.hargaTerbuka = false,
    this.golonganObat,
    this.obatWajibApotek = false,
    this.prekursor = false,
    this.wajibResep = false,
  });

  final String uuid;
  final String? sku;

  /// Apotek (§9.5): `Bebas`, `BebasTerbatas`, `Keras`, `Psikotropika`, `Narkotika`; null = bukan obat (atau server
  /// lama). [obatWajibApotek] hanya bermakna untuk obat keras; [wajibResep] dihitung server (keras bukan OWA,
  /// psikotropika, narkotika). Semua false untuk server lama.
  final String? golonganObat;
  final bool obatWajibApotek;
  final bool prekursor;
  final bool wajibResep;
  final String nama;
  final String? namaStruk;

  /// F-16d bagian 2: jumlah sesi bila produk ini paket sesi (wajib pelanggan, jumlah bulat, tidak bisa diretur); null =
  /// bukan paket sesi.
  final int? jumlahSesiPaket;

  /// F-05h: masa garansi standar (bulan) produk bernomor seri; null = tanpa garansi (atau server lama).
  final int? masaGaransiBulan;

  /// K-25: harga diketik kasir per transaksi (harga daftar jadi saran); false untuk server lama.
  final bool hargaTerbuka;

  /// K-9: atribut varian apa adanya dari server. Induk: daftar `{Nama, Nilai[]}`; anak: peta atribut → nilai.
  final Object? atributVarian;

  /// `Stok`, `IndukVarian`, `Resep`, `Produksi`, `Paket`, `Jasa`, `NonStok`, `BahanBaku`, `Konsinyasi`.
  final String jenis;
  final String? uuidKategori;
  final String? uuidSatuanDasar;

  /// `Tidak`, `Batch`, `Seri`.
  final String pelacakan;
  final String? uuidKelompokPajak;

  /// Null = ikut pengaturan pajak tenant.
  final bool? hargaTermasukPajak;
  final bool tampilDiPos;

  /// K-16 (v5.68): produk favorit pilihan pemilik; tampil di kategori Favorit layar jual. Server lama tanpa field = false.
  final bool favorit;
  final String? uuidInduk;
  final String? urlGambarKecil;
  final bool aktif;
  final bool dihapus;

  static ProdukPos DariJson(Map<String, Object?> json) => ProdukPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    sku: UraiJson.AmbilTeksAtauNull(json['Sku']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    namaStruk: UraiJson.AmbilTeksAtauNull(json['NamaStruk']),
    jenis: UraiJson.AmbilTeks(json['Jenis'], 'Stok'),
    uuidKategori: UraiJson.AmbilTeksAtauNull(json['UuidKategori']),
    uuidSatuanDasar: UraiJson.AmbilTeksAtauNull(json['UuidSatuanDasar']),
    pelacakan: UraiJson.AmbilTeks(json['Pelacakan'], 'Tidak'),
    uuidKelompokPajak: UraiJson.AmbilTeksAtauNull(json['UuidKelompokPajak']),
    hargaTermasukPajak: UraiJson.AmbilBenarAtauNull(json['HargaTermasukPajak']),
    tampilDiPos: UraiJson.AmbilBenar(json['TampilDiPos'], true),
    favorit: UraiJson.AmbilBenar(json['Favorit']),
    uuidInduk: UraiJson.AmbilTeksAtauNull(json['UuidInduk']),
    urlGambarKecil: UraiJson.AmbilTeksAtauNull(json['UrlGambarKecil']),
    aktif: UraiJson.AmbilBenar(json['Aktif'], true),
    dihapus: UraiJson.AmbilBenar(json['Dihapus']),
    jumlahSesiPaket: UraiJson.AmbilBulatAtauNull(UraiJson.AmbilPetaAtauNull(json['PaketSesi'])?['JumlahSesi']),
    masaGaransiBulan: UraiJson.AmbilBulatAtauNull(json['MasaGaransiBulan']),
    atributVarian: json['AtributVarian'] is Map || json['AtributVarian'] is List ? json['AtributVarian'] : null,
    hargaTerbuka: UraiJson.AmbilBenar(json['HargaTerbuka']),
    golonganObat: UraiJson.AmbilTeksAtauNull(json['GolonganObat']),
    obatWajibApotek: UraiJson.AmbilBenar(json['ObatWajibApotek']),
    prekursor: UraiJson.AmbilBenar(json['Prekursor']),
    wajibResep: UraiJson.AmbilBenar(json['WajibResep']),
  );
}

class ProdukSatuanPos {
  const ProdukSatuanPos({
    required this.uuid,
    required this.uuidProduk,
    required this.uuidSatuan,
    required this.konversiKeDasar,
    required this.defaultJual,
  });

  final String uuid;
  final String uuidProduk;
  final String uuidSatuan;
  final String konversiKeDasar;
  final bool defaultJual;

  static ProdukSatuanPos DariJson(Map<String, Object?> json) => ProdukSatuanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeks(json['UuidProduk']),
    uuidSatuan: UraiJson.AmbilTeks(json['UuidSatuan']),
    konversiKeDasar: UraiJson.AmbilDesimal(json['KonversiKeDasar'], '1'),
    defaultJual: UraiJson.AmbilBenar(json['DefaultJual']),
  );
}

class ProdukBarcodePos {
  const ProdukBarcodePos({
    required this.uuid,
    required this.uuidProduk,
    required this.uuidProdukSatuan,
    required this.barcode,
  });

  final String uuid;
  final String uuidProduk;
  final String? uuidProdukSatuan;
  final String barcode;

  static ProdukBarcodePos DariJson(Map<String, Object?> json) => ProdukBarcodePos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeks(json['UuidProduk']),
    uuidProdukSatuan: UraiJson.AmbilTeksAtauNull(json['UuidProdukSatuan']),
    barcode: UraiJson.AmbilTeks(json['Barcode']),
  );
}

class DaftarHargaPos {
  const DaftarHargaPos({
    required this.uuid,
    required this.nama,
    required this.uuidOutlet,
    required this.kanal,
    required this.tierPelanggan,
    required this.mulaiPada,
    required this.selesaiPada,
    required this.prioritas,
    required this.aktif,
  });

  final String uuid;
  final String nama;

  /// Null = semua outlet.
  final List<String>? uuidOutlet;
  final String? kanal;
  final String? tierPelanggan;

  /// ISO 8601 UTC.
  final String? mulaiPada;
  final String? selesaiPada;
  final int prioritas;
  final bool aktif;

  static DaftarHargaPos DariJson(Map<String, Object?> json) => DaftarHargaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    uuidOutlet: json['UuidOutlet'] is List<Object?> ? UraiJson.AmbilDaftarTeks(json['UuidOutlet']) : null,
    kanal: UraiJson.AmbilTeksAtauNull(json['Kanal']),
    tierPelanggan: UraiJson.AmbilTeksAtauNull(json['TierPelanggan']),
    mulaiPada: UraiJson.AmbilTeksAtauNull(json['MulaiPada']),
    selesaiPada: UraiJson.AmbilTeksAtauNull(json['SelesaiPada']),
    prioritas: UraiJson.AmbilBulat(json['Prioritas']),
    aktif: UraiJson.AmbilBenar(json['Aktif'], true),
  );
}

class ProdukHargaPos {
  const ProdukHargaPos({
    required this.uuid,
    required this.uuidProduk,
    required this.uuidProdukSatuan,
    required this.uuidDaftarHarga,
    required this.jumlahMinimum,
    required this.harga,
  });

  final String uuid;
  final String uuidProduk;
  final String uuidProdukSatuan;
  final String? uuidDaftarHarga;
  final String jumlahMinimum;
  final String harga;

  static ProdukHargaPos DariJson(Map<String, Object?> json) => ProdukHargaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeks(json['UuidProduk']),
    uuidProdukSatuan: UraiJson.AmbilTeks(json['UuidProdukSatuan']),
    uuidDaftarHarga: UraiJson.AmbilTeksAtauNull(json['UuidDaftarHarga']),
    jumlahMinimum: UraiJson.AmbilDesimal(json['JumlahMinimum'], '1'),
    harga: UraiJson.AmbilDesimal(json['Harga']),
  );
}

class KelompokPilihanPos {
  const KelompokPilihanPos({
    required this.uuid,
    required this.nama,
    required this.minimalPilih,
    required this.maksimalPilih,
    required this.urutan,
  });

  final String uuid;
  final String nama;
  final int minimalPilih;

  /// Null = tanpa batas atas.
  final int? maksimalPilih;
  final int urutan;

  static KelompokPilihanPos DariJson(Map<String, Object?> json) => KelompokPilihanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    minimalPilih: UraiJson.AmbilBulat(json['MinimalPilih']),
    maksimalPilih: UraiJson.AmbilBulatAtauNull(json['MaksimalPilih']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
  );
}

class PilihanPos {
  const PilihanPos({
    required this.uuid,
    required this.uuidKelompokPilihan,
    required this.nama,
    required this.harga,
    required this.uuidProdukBahan,
    required this.jumlah,
    required this.aktif,
    required this.urutan,
  });

  final String uuid;
  final String uuidKelompokPilihan;
  final String nama;
  final String harga;
  final String? uuidProdukBahan;
  final String? jumlah;
  final bool aktif;
  final int urutan;

  static PilihanPos DariJson(Map<String, Object?> json) => PilihanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidKelompokPilihan: UraiJson.AmbilTeks(json['UuidKelompokPilihan']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    harga: UraiJson.AmbilDesimal(json['Harga']),
    uuidProdukBahan: UraiJson.AmbilTeksAtauNull(json['UuidProdukBahan']),
    jumlah: UraiJson.AmbilDesimalAtauNull(json['Jumlah']),
    aktif: UraiJson.AmbilBenar(json['Aktif'], true),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
  );
}

class ProdukKelompokPilihanPos {
  const ProdukKelompokPilihanPos({
    required this.uuid,
    required this.uuidProduk,
    required this.uuidKelompokPilihan,
    required this.urutan,
  });

  final String uuid;
  final String uuidProduk;
  final String uuidKelompokPilihan;
  final int urutan;

  static ProdukKelompokPilihanPos DariJson(Map<String, Object?> json) => ProdukKelompokPilihanPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeks(json['UuidProduk']),
    uuidKelompokPilihan: UraiJson.AmbilTeks(json['UuidKelompokPilihan']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
  );
}

/// Baris katalog yang dihapus permanen sejak kursor (`Entitas` = nama tabel).
class TerhapusPos {
  const TerhapusPos({required this.entitas, required this.uuid});

  final String entitas;
  final String uuid;

  static TerhapusPos DariJson(Map<String, Object?> json) =>
      TerhapusPos(entitas: UraiJson.AmbilTeks(json['Entitas']), uuid: UraiJson.AmbilTeks(json['Uuid']));
}

/// Amplop katalog: `Lengkap: true` = ganti seluruh katalog lokal; selain itu delta sejak kursor.
class KatalogPos {
  const KatalogPos({
    required this.skema,
    required this.lengkap,
    required this.kursor,
    required this.waktuServer,
    this.kategori = const [],
    this.satuan = const [],
    this.kelompokPajak = const [],
    this.produk = const [],
    this.produkSatuan = const [],
    this.produkBarcode = const [],
    this.daftarHarga = const [],
    this.produkHarga = const [],
    this.kelompokPilihan = const [],
    this.pilihan = const [],
    this.produkKelompokPilihan = const [],
    this.terhapus = const [],
  });

  final int skema;
  final bool lengkap;
  final String? kursor;
  final String waktuServer;
  final List<KategoriPos> kategori;
  final List<SatuanPos> satuan;
  final List<KelompokPajakPos> kelompokPajak;
  final List<ProdukPos> produk;
  final List<ProdukSatuanPos> produkSatuan;
  final List<ProdukBarcodePos> produkBarcode;
  final List<DaftarHargaPos> daftarHarga;
  final List<ProdukHargaPos> produkHarga;
  final List<KelompokPilihanPos> kelompokPilihan;
  final List<PilihanPos> pilihan;
  final List<ProdukKelompokPilihanPos> produkKelompokPilihan;
  final List<TerhapusPos> terhapus;

  static KatalogPos DariJson(Map<String, Object?> json) {
    List<T> Urai<T>(String kunci, T Function(Map<String, Object?>) buat) =>
        UraiJson.AmbilDaftarPeta(json[kunci]).map(buat).toList();
    final kursor = UraiJson.AmbilTeksAtauNull(json['Kursor']);
    return KatalogPos(
      skema: UraiJson.AmbilBulat(json['Skema'], 1),
      lengkap: UraiJson.AmbilBenar(json['Lengkap']),
      kursor: kursor == null || kursor.isEmpty ? null : kursor,
      waktuServer: UraiJson.AmbilTeks(json['WaktuServer']),
      kategori: Urai('Kategori', KategoriPos.DariJson),
      satuan: Urai('Satuan', SatuanPos.DariJson),
      kelompokPajak: Urai('KelompokPajak', KelompokPajakPos.DariJson),
      produk: Urai('Produk', ProdukPos.DariJson),
      produkSatuan: Urai('ProdukSatuan', ProdukSatuanPos.DariJson),
      produkBarcode: Urai('ProdukBarcode', ProdukBarcodePos.DariJson),
      daftarHarga: Urai('DaftarHarga', DaftarHargaPos.DariJson),
      produkHarga: Urai('ProdukHarga', ProdukHargaPos.DariJson),
      kelompokPilihan: Urai('KelompokPilihan', KelompokPilihanPos.DariJson),
      pilihan: Urai('Pilihan', PilihanPos.DariJson),
      produkKelompokPilihan: Urai('ProdukKelompokPilihan', ProdukKelompokPilihanPos.DariJson),
      terhapus: Urai('Terhapus', TerhapusPos.DariJson),
    );
  }
}
