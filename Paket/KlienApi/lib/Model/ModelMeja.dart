import 'UraiJson.dart';

/// Area meja outlet (F-07 mode meja fase 1, `GET /api/pos/v1/meja`).
class AreaMejaPos {
  const AreaMejaPos({required this.uuid, required this.nama, required this.urutan});

  final String uuid;
  final String nama;
  final int urutan;

  static AreaMejaPos DariJson(Map<String, Object?> json) => AreaMejaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
  );
}

/// Meja aktif outlet. Status pakai (kosong/terisi) diturunkan dari pesanan terbuka.
class MejaPos {
  const MejaPos({
    required this.uuid,
    required this.nama,
    required this.uuidArea,
    required this.kapasitas,
    required this.bentuk,
    required this.urutan,
    this.minimumCharge,
  });

  final String uuid;
  final String nama;
  final String? uuidArea;
  final int kapasitas;
  final String bentuk;
  final int urutan;

  /// FnB minimum charge (D-144): minimum belanja yang berlaku untuk meja ini (minimum meja, atau areanya), string
  /// desimal ("150000.00"); null = tanpa minimum (juga server lama).
  final String? minimumCharge;

  static MejaPos DariJson(Map<String, Object?> json) => MejaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    uuidArea: UraiJson.AmbilTeksAtauNull(json['UuidArea']),
    kapasitas: UraiJson.AmbilBulat(json['Kapasitas'], 4),
    bentuk: UraiJson.AmbilTeks(json['Bentuk'], 'Persegi'),
    urutan: UraiJson.AmbilBulat(json['Urutan']),
    minimumCharge: UraiJson.AmbilTeksAtauNull(json['MinimumCharge']),
  );
}

class StasiunDapurPos {
  const StasiunDapurPos({required this.uuid, required this.nama});

  final String uuid;
  final String nama;

  static StasiunDapurPos DariJson(Map<String, Object?> json) =>
      StasiunDapurPos(uuid: UraiJson.AmbilTeks(json['Uuid']), nama: UraiJson.AmbilTeks(json['Nama']));
}

/// Data meja outlet perangkat untuk kerja offline.
class DataMejaPos {
  const DataMejaPos({
    required this.modeMejaAktif,
    required this.area,
    required this.meja,
    required this.stasiunDapur,
    required this.uuidStasiunBawaan,
    this.kategoriStasiun = const {},
    this.uuidProdukMinimumCharge,
  });

  final bool modeMejaAktif;
  final List<AreaMejaPos> area;
  final List<MejaPos> meja;
  final List<StasiunDapurPos> stasiunDapur;
  final String? uuidStasiunBawaan;

  /// Cetak struk bagian 4c: Uuid stasiun aktif per Uuid kategori yang diatur langsung (server lama = kosong).
  final Map<String, String> kategoriStasiun;

  /// D-144: Uuid produk "Selisih minimum charge" (harga terbuka) yang ditagihkan kasir saat belanja meja kurang dari
  /// minimum; null = belum ada meja/area ber-minimum.
  final String? uuidProdukMinimumCharge;

  static DataMejaPos DariJson(Map<String, Object?> json) => DataMejaPos(
    modeMejaAktif: UraiJson.AmbilBenar(json['ModeMejaAktif']),
    area: UraiJson.AmbilDaftarPeta(json['Area']).map(AreaMejaPos.DariJson).toList(),
    meja: UraiJson.AmbilDaftarPeta(json['Meja']).map(MejaPos.DariJson).toList(),
    stasiunDapur: UraiJson.AmbilDaftarPeta(json['StasiunDapur']).map(StasiunDapurPos.DariJson).toList(),
    uuidStasiunBawaan: UraiJson.AmbilTeksAtauNull(json['UuidStasiunBawaan']),
    kategoriStasiun: {
      for (final k in UraiJson.AmbilDaftarPeta(json['KategoriStasiun']))
        UraiJson.AmbilTeks(k['UuidKategori']): UraiJson.AmbilTeks(k['UuidStasiun']),
    },
    uuidProdukMinimumCharge: UraiJson.AmbilTeksAtauNull(json['UuidProdukMinimumCharge']),
  );
}

/// Satu baris pesanan terbuka dari server (append-only; batal = void item).
class BarisPesananTerbukaPos {
  const BarisPesananTerbukaPos({
    required this.uuid,
    required this.uuidProduk,
    required this.uuidProdukSatuan,
    required this.namaProduk,
    required this.jumlah,
    required this.hargaSatuan,
    required this.hargaPilihan,
    required this.pilihan,
    required this.catatan,
    required this.ronde,
    required this.dibatalkan,
    required this.dikirimKeDapur,
    required this.statusDapur,
    this.kursus,
  });

  final String uuid;

  /// Null untuk baris dari server lama (sebelum v1.57 kolom UuidProduk).
  final String? uuidProduk;
  final String? uuidProdukSatuan;
  final String namaProduk;
  final String jumlah;
  final String hargaSatuan;
  final String hargaPilihan;

  /// `[{UuidPilihan, Nama, Harga}]` apa adanya.
  final List<Map<String, Object?>> pilihan;
  final String? catatan;
  final int ronde;
  final bool dibatalkan;
  final bool dikirimKeDapur;

  /// Antre/Dimasak/Siap/Disajikan; null = belum ada tiket.
  final String? statusDapur;

  /// K-13: Pembuka/Utama/Penutup; null = tanpa kursus (atau server lama).
  final String? kursus;

  static BarisPesananTerbukaPos DariJson(Map<String, Object?> json) => BarisPesananTerbukaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeksAtauNull(json['UuidProduk']),
    uuidProdukSatuan: UraiJson.AmbilTeksAtauNull(json['UuidProdukSatuan']),
    namaProduk: UraiJson.AmbilTeks(json['NamaProduk']),
    jumlah: UraiJson.AmbilDesimal(json['Jumlah'], '1'),
    hargaSatuan: UraiJson.AmbilDesimal(json['HargaSatuan']),
    hargaPilihan: UraiJson.AmbilDesimal(json['HargaPilihan']),
    pilihan: UraiJson.AmbilDaftarPeta(json['Pilihan']),
    catatan: UraiJson.AmbilTeksAtauNull(json['Catatan']),
    ronde: UraiJson.AmbilBulat(json['Ronde'], 1),
    dibatalkan: UraiJson.AmbilBenar(json['Dibatalkan']),
    dikirimKeDapur: UraiJson.AmbilBenar(json['DikirimKeDapur']),
    statusDapur: UraiJson.AmbilTeksAtauNull(json['StatusDapur']),
    kursus: UraiJson.AmbilTeksAtauNull(json['Kursus']),
  );
}

class PesananTerbukaPos {
  const PesananTerbukaPos({
    required this.uuid,
    required this.nomor,
    required this.uuidMeja,
    required this.namaMeja,
    required this.label,
    required this.jumlahTamu,
    required this.dibukaOleh,
    required this.dibukaPada,
    required this.dikunciBayar,
    required this.baris,
    this.mintaBillPada,
  });

  final String uuid;
  final String nomor;
  final String? uuidMeja;
  final String? namaMeja;
  final String? label;
  final int jumlahTamu;
  final String? dibukaOleh;
  final DateTime? dibukaPada;
  final bool dikunciBayar;
  final List<BarisPesananTerbukaPos> baris;

  /// K-12: tamu minta tagihan sejak waktu ini (server v3.63+); null = belum / server lama.
  final DateTime? mintaBillPada;

  static PesananTerbukaPos DariJson(Map<String, Object?> json) => PesananTerbukaPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    uuidMeja: UraiJson.AmbilTeksAtauNull(json['UuidMeja']),
    namaMeja: UraiJson.AmbilTeksAtauNull(json['NamaMeja']),
    label: UraiJson.AmbilTeksAtauNull(json['Label']),
    jumlahTamu: UraiJson.AmbilBulat(json['JumlahTamu'], 1),
    dibukaOleh: UraiJson.AmbilTeksAtauNull(json['DibukaOleh']),
    dibukaPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibukaPada'])),
    dikunciBayar: UraiJson.AmbilBenar(json['DikunciBayar']),
    baris: UraiJson.AmbilDaftarPeta(json['Baris']).map(BarisPesananTerbukaPos.DariJson).toList(),
    mintaBillPada: DateTime.tryParse(UraiJson.AmbilTeksAtauNull(json['MintaBillPada']) ?? ''),
  );
}

/// Snapshot pesanan terbuka outlet (`GET /api/pos/v1/pesanan-terbuka`) beserta `ETag`.
class SnapshotPesananTerbuka {
  const SnapshotPesananTerbuka({
    required this.pesanan,
    required this.ditutup,
    required this.etag,
    this.mejaPerluDibersihkan = const [],
  });

  final List<PesananTerbukaPos> pesanan;

  /// Uuid pesanan yang ditutup (dibayar/dibatalkan) dalam 12 jam terakhir → hapus dari layar.
  final List<({String uuid, String status})> ditutup;
  final String? etag;

  /// K-12: meja yang perlu dibersihkan setelah tagihannya dibayar (kosong di server lama).
  final List<({String uuidMeja, DateTime sejak})> mejaPerluDibersihkan;

  static SnapshotPesananTerbuka DariJson(Map<String, Object?> json, String? etag) => SnapshotPesananTerbuka(
    pesanan: UraiJson.AmbilDaftarPeta(json['Pesanan']).map(PesananTerbukaPos.DariJson).toList(),
    ditutup: [
      for (final d in UraiJson.AmbilDaftarPeta(json['Ditutup']))
        (uuid: UraiJson.AmbilTeks(d['Uuid']), status: UraiJson.AmbilTeks(d['Status'])),
    ],
    etag: etag,
    mejaPerluDibersihkan: [
      for (final m in UraiJson.AmbilDaftarPeta(json['MejaPerluDibersihkan']))
        if (DateTime.tryParse(UraiJson.AmbilTeks(m['Sejak'])) case final sejak?)
          (uuidMeja: UraiJson.AmbilTeks(m['UuidMeja']), sejak: sejak),
    ],
  );
}

class BarisTiketDapurPos {
  const BarisTiketDapurPos({
    required this.uuidBaris,
    required this.namaProduk,
    required this.jumlah,
    required this.pilihan,
    required this.catatan,
    required this.dibatalkan,
  });

  final String uuidBaris;
  final String namaProduk;
  final String jumlah;
  final List<String> pilihan;
  final String? catatan;
  final bool dibatalkan;

  static BarisTiketDapurPos DariJson(Map<String, Object?> json) => BarisTiketDapurPos(
    uuidBaris: UraiJson.AmbilTeks(json['UuidBaris']),
    namaProduk: UraiJson.AmbilTeks(json['NamaProduk']),
    jumlah: UraiJson.AmbilDesimal(json['Jumlah'], '1'),
    pilihan: UraiJson.AmbilDaftarTeks(json['Pilihan']),
    catatan: UraiJson.AmbilTeksAtauNull(json['Catatan']),
    dibatalkan: UraiJson.AmbilBenar(json['Dibatalkan']),
  );
}

/// Tiket dapur untuk layar KDS (F-10b fase 1).
class TiketDapurPos {
  const TiketDapurPos({
    required this.uuid,
    required this.uuidStasiun,
    required this.nomorDokumen,
    required this.namaMeja,
    required this.label,
    required this.ronde,
    required this.status,
    required this.dikirimPada,
    required this.baris,
  });

  final String uuid;
  final String? uuidStasiun;
  final String nomorDokumen;
  final String? namaMeja;
  final String? label;
  final int ronde;

  /// Antre/Dimasak/Siap/Disajikan.
  final String status;
  final DateTime dikirimPada;
  final List<BarisTiketDapurPos> baris;

  static TiketDapurPos DariJson(Map<String, Object?> json) => TiketDapurPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidStasiun: UraiJson.AmbilTeksAtauNull(json['UuidStasiun']),
    nomorDokumen: UraiJson.AmbilTeks(json['NomorDokumen']),
    namaMeja: UraiJson.AmbilTeksAtauNull(json['NamaMeja']),
    label: UraiJson.AmbilTeksAtauNull(json['Label']),
    ronde: UraiJson.AmbilBulat(json['Ronde'], 1),
    status: UraiJson.AmbilTeks(json['Status'], 'Antre'),
    dikirimPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DikirimPada'])) ?? DateTime.now().toUtc(),
    baris: UraiJson.AmbilDaftarPeta(json['Baris']).map(BarisTiketDapurPos.DariJson).toList(),
  );
}

/// Daftar tiket aktif + jam server (untuk umur tiket yang tidak bergantung jam perangkat KDS).
class DaftarTiketDapur {
  const DaftarTiketDapur({required this.tiket, required this.waktuServer});

  final List<TiketDapurPos> tiket;
  final DateTime? waktuServer;

  static DaftarTiketDapur DariJson(Map<String, Object?> json) => DaftarTiketDapur(
    tiket: UraiJson.AmbilDaftarPeta(json['Tiket']).map(TiketDapurPos.DariJson).toList(),
    waktuServer: DateTime.tryParse(UraiJson.AmbilTeks(json['WaktuServer'])),
  );
}

/// Pesanan dari QR meja (F-17 self-order, v2.02) yang menunggu konfirmasi staf. Baris berbentuk sama dengan baris
/// pesanan terbuka (harga dari server) tanpa status dapur.
class PesananSendiriPos {
  const PesananSendiriPos({
    required this.uuid,
    required this.nomor,
    required this.uuidMeja,
    required this.namaMeja,
    required this.namaPemesan,
    required this.catatan,
    required this.dibuatPada,
    required this.subtotal,
    required this.baris,
  });

  final String uuid;
  final String nomor;
  final String? uuidMeja;
  final String? namaMeja;
  final String? namaPemesan;
  final String? catatan;
  final DateTime dibuatPada;
  final String subtotal;
  final List<BarisPesananTerbukaPos> baris;

  static PesananSendiriPos DariJson(Map<String, Object?> json) => PesananSendiriPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    uuidMeja: UraiJson.AmbilTeksAtauNull(json['UuidMeja']),
    namaMeja: UraiJson.AmbilTeksAtauNull(json['NamaMeja']),
    namaPemesan: UraiJson.AmbilTeksAtauNull(json['NamaPemesan']),
    catatan: UraiJson.AmbilTeksAtauNull(json['Catatan']),
    dibuatPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibuatPada']))?.toUtc() ?? DateTime.utc(1970),
    subtotal: UraiJson.AmbilDesimal(json['Subtotal']),
    baris: [for (final b in UraiJson.AmbilDaftarPeta(json['Baris'])) BarisPesananTerbukaPos.DariJson(b)],
  );
}
