import 'UraiJson.dart';

/// Baris perintah kerja yang sudah **disetujui** pelanggan (Bengkel §9.10, `GET /api/pos/v1/perintah-kerja`). Harga =
/// snapshot server saat estimasi (sudah memperhitungkan tier pelanggan); [diskon] = potongan nominal untuk seluruh
/// baris; [uuidKaryawan] = mekanik baris jasa (jadi `Baris[].Staf` penjualan → komisi F-18).
class BarisPerintahKerjaPos {
  const BarisPerintahKerjaPos({
    required this.uuid,
    required this.jenis,
    required this.uuidProduk,
    required this.uuidProdukSatuan,
    required this.namaProduk,
    required this.jumlah,
    required this.hargaSatuan,
    required this.diskon,
    required this.uuidKaryawan,
    required this.namaKaryawan,
    required this.catatan,
    this.nomorSeri = const [],
  });

  static const String jenisJasa = 'Jasa';
  static const String jenisSparepart = 'Sparepart';

  final String uuid;

  /// `Jasa` / `Sparepart`.
  final String jenis;
  final String uuidProduk;
  final String? uuidProdukSatuan;
  final String namaProduk;
  final String jumlah;
  final String hargaSatuan;
  final String diskon;
  final String? uuidKaryawan;
  final String? namaKaryawan;
  final String? catatan;

  /// Nomor seri unit sparepart yang dicatat di perintah kerja (kosong = diisi kasir saat menagih; server lama tidak
  /// mengirimnya).
  final List<String> nomorSeri;

  bool get CekJasa => jenis == jenisJasa;

  static BarisPerintahKerjaPos DariJson(Map<String, Object?> json) => BarisPerintahKerjaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    jenis: UraiJson.AmbilTeks(json['Jenis'], jenisSparepart),
    uuidProduk: UraiJson.AmbilTeks(json['UuidProduk']),
    uuidProdukSatuan: UraiJson.AmbilTeksAtauNull(json['UuidProdukSatuan']),
    namaProduk: UraiJson.AmbilTeks(json['NamaProduk']),
    jumlah: UraiJson.AmbilDesimal(json['Jumlah'], '1'),
    hargaSatuan: UraiJson.AmbilDesimal(json['HargaSatuan']),
    diskon: UraiJson.AmbilDesimal(json['Diskon']),
    uuidKaryawan: UraiJson.AmbilTeksAtauNull(json['UuidKaryawan']),
    namaKaryawan: UraiJson.AmbilTeksAtauNull(json['NamaKaryawan']),
    catatan: UraiJson.AmbilTeksAtauNull(json['Catatan']),
    nomorSeri: [...(json['NomorSeri'] as List<Object?>? ?? const []).whereType<String>()],
  );
}

/// Perintah kerja bengkel untuk kasir (§9.10, perlu online, hanya baca). [siapTagih] = disetujui pelanggan & belum
/// ditagih. [pelanggan] `{Uuid, Nama, NoHp (tersamar), KodeTier}`; [kendaraan] `{Uuid, Jenis (Kendaraan|Perangkat, D-142), NomorPolisi (plat atau IMEI/seri), Label}`.
class PerintahKerjaPos {
  const PerintahKerjaPos({
    required this.uuid,
    required this.nomor,
    required this.status,
    required this.labelStatus,
    required this.siapTagih,
    required this.dibuatPada,
    required this.pelanggan,
    required this.kendaraan,
    required this.kmMasuk,
    required this.keluhan,
    required this.catatanQc,
    required this.totalDisetujui,
    required this.baris,
  });

  final String uuid;
  final String nomor;
  final String status;
  final String labelStatus;
  final bool siapTagih;

  /// UTC; null bila server tidak mengirimnya.
  final DateTime? dibuatPada;
  final Map<String, Object?>? pelanggan;
  final Map<String, Object?>? kendaraan;
  final int? kmMasuk;
  final String? keluhan;
  final String? catatanQc;
  final String totalDisetujui;
  final List<BarisPerintahKerjaPos> baris;

  String? get nomorPolisi => UraiJson.AmbilTeksAtauNull(kendaraan?['NomorPolisi']);
  String? get labelKendaraan => UraiJson.AmbilTeksAtauNull(kendaraan?['Label']);

  /// `Kendaraan` atau `Perangkat` (servis elektronik, D-142); server lama tanpa `Jenis` = Kendaraan.
  String get jenisUnit => UraiJson.AmbilTeks(kendaraan?['Jenis'], 'Kendaraan');
  bool get unitPerangkat => jenisUnit == 'Perangkat';

  /// Judul singkat unit: plat kendaraan, atau merek & tipe perangkat (IMEI boleh kosong).
  String? get pengenalUnit => unitPerangkat ? labelKendaraan : nomorPolisi;
  String? get namaPelanggan => UraiJson.AmbilTeksAtauNull(pelanggan?['Nama']);

  static PerintahKerjaPos DariJson(Map<String, Object?> json) => PerintahKerjaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    status: UraiJson.AmbilTeks(json['Status']),
    labelStatus: UraiJson.AmbilTeks(json['LabelStatus'], UraiJson.AmbilTeks(json['Status'])),
    siapTagih: UraiJson.AmbilBenar(json['SiapTagih']),
    dibuatPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibuatPada']))?.toUtc(),
    pelanggan: UraiJson.AmbilPetaAtauNull(json['Pelanggan']),
    kendaraan: UraiJson.AmbilPetaAtauNull(json['Kendaraan']),
    kmMasuk: UraiJson.AmbilBulatAtauNull(json['KmMasuk']),
    keluhan: UraiJson.AmbilTeksAtauNull(json['Keluhan']),
    catatanQc: UraiJson.AmbilTeksAtauNull(json['CatatanQc']),
    totalDisetujui: UraiJson.AmbilDesimal(json['TotalDisetujui']),
    baris: [for (final b in UraiJson.AmbilDaftarPeta(json['Baris'])) BarisPerintahKerjaPos.DariJson(b)],
  );
}
