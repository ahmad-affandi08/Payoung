import 'package:klien_api/KlienApi.dart';

/// Produk di daftar aksi cepat Owner (OWN-07). Harga berupa string desimal; null = belum punya harga dasar.
class ProdukAksiCepat {
  const ProdukAksiCepat({
    required this.uuid,
    required this.nama,
    required this.jenis,
    this.sku,
    this.hargaDasar,
    this.bisaUbahHarga = false,
    this.bisaTandaiHabis = false,
    this.habis,
  });

  final String uuid;
  final String nama;
  final String? sku;
  final String jenis;
  final String? hargaDasar;
  final bool bisaUbahHarga;
  final bool bisaTandaiHabis;

  /// Null = tidak diketahui (belum memilih outlet, atau produk induk varian).
  final bool? habis;

  static ProdukAksiCepat DariJson(Map<String, Object?> json) => ProdukAksiCepat(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    sku: UraiJson.AmbilTeksAtauNull(json['Sku']),
    jenis: UraiJson.AmbilTeks(json['Jenis']),
    hargaDasar: UraiJson.AmbilDesimalAtauNull(json['HargaDasar']),
    bisaUbahHarga: UraiJson.AmbilBenar(json['BisaUbahHarga']),
    bisaTandaiHabis: UraiJson.AmbilBenar(json['BisaTandaiHabis']),
    habis: UraiJson.AmbilBenarAtauNull(json['Habis']),
  );
}

class HasilUbahHarga {
  const HasilUbahHarga({required this.hargaLama, required this.hargaBaru, required this.berubah, required this.nama});

  final String hargaLama;
  final String hargaBaru;
  final bool berubah;
  final String nama;

  static HasilUbahHarga DariJson(Map<String, Object?> json) => HasilUbahHarga(
    hargaLama: UraiJson.AmbilDesimal(json['HargaLama']),
    hargaBaru: UraiJson.AmbilDesimal(json['HargaBaru']),
    berubah: UraiJson.AmbilBenar(json['Berubah']),
    nama: UraiJson.AmbilTeks(UraiJson.AmbilPeta(json['Produk'])['Nama']),
  );
}

class PromoAksiCepat {
  const PromoAksiCepat({
    required this.uuid,
    required this.kode,
    required this.nama,
    required this.aktif,
    required this.berakhir,
    this.mulaiPada,
    this.selesaiPada,
  });

  final String uuid;
  final String kode;
  final String nama;
  final bool aktif;
  final bool berakhir;
  final String? mulaiPada;
  final String? selesaiPada;

  static PromoAksiCepat DariJson(Map<String, Object?> json) => PromoAksiCepat(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    kode: UraiJson.AmbilTeks(json['Kode']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    aktif: UraiJson.AmbilBenar(json['Aktif']),
    berakhir: UraiJson.AmbilBenar(json['Berakhir']),
    mulaiPada: UraiJson.AmbilTeksAtauNull(json['MulaiPada']),
    selesaiPada: UraiJson.AmbilTeksAtauNull(json['SelesaiPada']),
  );
}

class PilihanPengeluaran {
  const PilihanPengeluaran({required this.uuid, required this.nama, this.kode});

  final String uuid;
  final String nama;
  final String? kode;

  static PilihanPengeluaran DariJson(Map<String, Object?> json) => PilihanPengeluaran(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    kode: UraiJson.AmbilTeksAtauNull(json['Kode']),
  );
}

class OpsiPengeluaran {
  const OpsiPengeluaran({required this.kategori, required this.sumber});

  final List<PilihanPengeluaran> kategori;
  final List<PilihanPengeluaran> sumber;

  static OpsiPengeluaran DariJson(Map<String, Object?> json) => OpsiPengeluaran(
    kategori: [for (final k in UraiJson.AmbilDaftarPeta(json['Kategori'])) PilihanPengeluaran.DariJson(k)],
    sumber: [for (final s in UraiJson.AmbilDaftarPeta(json['Sumber'])) PilihanPengeluaran.DariJson(s)],
  );
}

class HasilPengeluaran {
  const HasilPengeluaran({required this.uuid, required this.nomor, required this.jumlah, required this.berLampiran});

  final String uuid;
  final String nomor;
  final String jumlah;
  final bool berLampiran;

  static HasilPengeluaran DariJson(Map<String, Object?> json) {
    final isi = UraiJson.AmbilPeta(json['Pengeluaran']);
    return HasilPengeluaran(
      uuid: UraiJson.AmbilTeks(isi['Uuid']),
      nomor: UraiJson.AmbilTeks(isi['Nomor']),
      jumlah: UraiJson.AmbilDesimal(isi['Jumlah']),
      berLampiran: UraiJson.AmbilBenar(isi['BerLampiran']),
    );
  }
}
