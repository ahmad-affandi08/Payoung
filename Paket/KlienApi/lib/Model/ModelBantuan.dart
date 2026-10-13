import 'UraiJson.dart';

/// Kategori tiket bantuan dari aplikasi (kunci = `KategoriTiketDukungan` server). Urutan = urutan tampil di pilihan.
enum KategoriBantuan {
  Perangkat('Perangkat', 'Perangkat & printer'),
  Penjualan('Penjualan', 'Kasir & penjualan'),
  Persediaan('Persediaan', 'Stok & persediaan'),
  Laporan('Laporan', 'Laporan'),
  AkunLangganan('AkunLangganan', 'Akun & langganan'),
  Tagihan('Tagihan', 'Tagihan & pembayaran langganan'),
  Lainnya('Lainnya', 'Lainnya');

  const KategoriBantuan(this.kunci, this.label);

  final String kunci;
  final String label;
}

/// Satu pesan di percakapan tiket (catatan internal tim tidak pernah dikirim ke aplikasi).
class PesanTiketBantuan {
  const PesanTiketBantuan({
    required this.uuid,
    required this.jenisPengirim,
    required this.namaPengirim,
    required this.isi,
    required this.dibuatPada,
  });

  /// `Pengguna` (pelapor), `Pengelola` (tim Dukungan), atau `Sistem`.
  final String uuid;
  final String jenisPengirim;
  final String namaPengirim;
  final String isi;
  final DateTime? dibuatPada;

  bool get dariTim => jenisPengirim == 'Pengelola';

  static PesanTiketBantuan DariJson(Map<String, Object?> json) => PesanTiketBantuan(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    jenisPengirim: UraiJson.AmbilTeks(json['JenisPengirim'], 'Pengguna'),
    namaPengirim: UraiJson.AmbilTeks(json['NamaPengirim']),
    isi: UraiJson.AmbilTeks(json['Isi']),
    dibuatPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibuatPada']))?.toLocal(),
  );
}

/// Tiket bantuan (P-09) untuk daftar dan percakapan di Aplikasi Kasir & Owner. [pesan] kosong pada daftar.
class TiketBantuan {
  const TiketBantuan({
    required this.uuid,
    required this.nomor,
    required this.judul,
    required this.labelKategori,
    required this.labelPrioritas,
    required this.status,
    required this.labelStatus,
    required this.dibuatPada,
    required this.batasSlaPada,
    required this.responsPertamaPada,
    required this.lewatSla,
    required this.slaJamKerja,
    required this.bisaDibalas,
    this.pesan = const [],
  });

  final String uuid;
  final String nomor;
  final String judul;
  final String labelKategori;
  final String labelPrioritas;

  /// `Baru`, `Ditangani`, `MenungguPelanggan`, `Selesai`, `Ditutup`.
  final String status;
  final String labelStatus;
  final DateTime? dibuatPada;
  final DateTime? batasSlaPada;
  final DateTime? responsPertamaPada;
  final bool lewatSla;

  /// SLA dihitung jam kerja tim (Senin–Jumat 08.00–17.00 WIB, kecuali libur); false = jam kalender.
  final bool slaJamKerja;
  final bool bisaDibalas;
  final List<PesanTiketBantuan> pesan;

  bool get menungguBalasan => status == 'MenungguPelanggan';

  bool get terbuka => status == 'Baru' || status == 'Ditangani' || status == 'MenungguPelanggan';

  static TiketBantuan DariJson(Map<String, Object?> json) => TiketBantuan(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    judul: UraiJson.AmbilTeks(json['Judul']),
    labelKategori: UraiJson.AmbilTeks(json['LabelKategori']),
    labelPrioritas: UraiJson.AmbilTeks(json['LabelPrioritas']),
    status: UraiJson.AmbilTeks(json['Status'], 'Baru'),
    labelStatus: UraiJson.AmbilTeks(json['LabelStatus']),
    dibuatPada: DateTime.tryParse(UraiJson.AmbilTeks(json['DibuatPada']))?.toLocal(),
    batasSlaPada: DateTime.tryParse(UraiJson.AmbilTeks(json['BatasSlaPada']))?.toLocal(),
    responsPertamaPada: DateTime.tryParse(UraiJson.AmbilTeks(json['ResponsPertamaPada']))?.toLocal(),
    lewatSla: UraiJson.AmbilBenar(json['LewatSla']),
    slaJamKerja: UraiJson.AmbilBenar(json['SlaJamKerja']),
    bisaDibalas: UraiJson.AmbilBenar(json['BisaDibalas']),
    pesan: [for (final p in UraiJson.AmbilDaftarPeta(json['Pesan'])) PesanTiketBantuan.DariJson(p)],
  );

  static List<TiketBantuan> DaftarDariJson(Object? json) => [
    for (final t in UraiJson.AmbilDaftarPeta(json)) TiketBantuan.DariJson(t),
  ];
}
