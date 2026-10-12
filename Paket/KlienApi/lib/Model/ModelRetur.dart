/// Model respons `GET /api/pos/v1/penjualan/cari?nomor=` (PRD "Rincian F-09 fase 1"): struk asal untuk retur di
/// aplikasi POS. Uang & jumlah = string desimal apa adanya (tidak pernah melewati tipe pecahan biner). Kunci absen
/// memakai nilai bawaan agar kompatibel mundur (CLAUDE.md #16).
library;

import 'ModelPos.dart';
import 'UraiJson.dart';

/// Kepala penjualan yang dicari beserta status retur-nya.
class PenjualanCariPos {
  const PenjualanCariPos({
    required this.uuid,
    required this.nomor,
    required this.status,
    required this.labelStatus,
    required this.tanggalBisnis,
    required this.dibuatPada,
    required this.namaKasir,
    required this.hargaTermasukPajak,
    required this.subtotal,
    required this.totalDiskon,
    required this.biayaLayanan,
    required this.totalPajak,
    required this.pembulatan,
    required this.totalAkhir,
    required this.totalDibayar,
    required this.kembalian,
    required this.batasHariRetur,
    required this.batasReturSampai,
    required this.bisaDiretur,
    required this.alasanTidakBisaDiretur,
    this.sisaPiutang,
    this.bisaRefundDeposit = false,
  });

  /// Alasan `BisaDiretur` = false dari server.
  static const String alasanVoid = 'Void';
  static const String alasanSudahDireturPenuh = 'SudahDireturPenuh';
  static const String alasanLewatBatasHari = 'LewatBatasHari';

  final String uuid;
  final String nomor;
  final String status;
  final String labelStatus;

  /// `YYYY-MM-DD`.
  final String tanggalBisnis;

  /// ISO 8601 UTC.
  final String dibuatPada;
  final String namaKasir;
  final bool hargaTermasukPajak;
  final String subtotal;
  final String totalDiskon;
  final String biayaLayanan;
  final String totalPajak;
  final String pembulatan;
  final String totalAkhir;
  final String totalDibayar;
  final String kembalian;
  final int batasHariRetur;

  /// `YYYY-MM-DD` (inklusif).
  final String batasReturSampai;
  final bool bisaDiretur;
  final String? alasanTidakBisaDiretur;

  /// F-12: sisa piutang penjualan tempo (null = bukan tempo / server lama). Retur memotong piutang ini lebih dulu.
  final String? sisaPiutang;

  /// F-16d: penjualan berpelanggan, jadi refund retur boleh ke saldo deposit (server lama = false).
  final bool bisaRefundDeposit;

  static PenjualanCariPos DariJson(Map<String, Object?> json) => PenjualanCariPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    status: UraiJson.AmbilTeks(json['Status']),
    labelStatus: UraiJson.AmbilTeks(json['LabelStatus'], UraiJson.AmbilTeks(json['Status'])),
    tanggalBisnis: UraiJson.AmbilTeks(json['TanggalBisnis']),
    dibuatPada: UraiJson.AmbilTeks(json['DibuatPada']),
    namaKasir: UraiJson.AmbilTeks(json['NamaKasir']),
    hargaTermasukPajak: UraiJson.AmbilBenar(json['HargaTermasukPajak']),
    subtotal: UraiJson.AmbilDesimal(json['Subtotal']),
    totalDiskon: UraiJson.AmbilDesimal(json['TotalDiskon']),
    biayaLayanan: UraiJson.AmbilDesimal(json['BiayaLayanan']),
    totalPajak: UraiJson.AmbilDesimal(json['TotalPajak']),
    pembulatan: UraiJson.AmbilDesimal(json['Pembulatan']),
    totalAkhir: UraiJson.AmbilDesimal(json['TotalAkhir']),
    totalDibayar: UraiJson.AmbilDesimal(json['TotalDibayar']),
    kembalian: UraiJson.AmbilDesimal(json['Kembalian']),
    batasHariRetur: UraiJson.AmbilBulat(json['BatasHariRetur'], DataAwal.batasHariReturBawaan),
    batasReturSampai: UraiJson.AmbilTeks(json['BatasReturSampai']),
    bisaDiretur: UraiJson.AmbilBenar(json['BisaDiretur']),
    alasanTidakBisaDiretur: UraiJson.AmbilTeksAtauNull(json['AlasanTidakBisaDiretur']),
    sisaPiutang: UraiJson.AmbilDesimalAtauNull(json['SisaPiutang']),
    bisaRefundDeposit: UraiJson.AmbilBenar(json['BisaRefundDeposit']),
  );
}

/// Satu baris penjualan asal beserta jumlah & nilai yang masih bisa diretur.
class BarisPenjualanCariPos {
  const BarisPenjualanCariPos({
    required this.uuid,
    required this.uuidProduk,
    required this.namaProduk,
    required this.simbolSatuan,
    required this.jumlah,
    required this.hargaSatuan,
    required this.hargaPilihan,
    required this.pilihan,
    required this.bruto,
    required this.jumlahDiskon,
    required this.jumlahDiskonPesanan,
    required this.biayaLayanan,
    required this.jumlahPajak,
    required this.totalBaris,
    required this.jumlahSudahDiretur,
    required this.jumlahBisaDiretur,
    required this.nilaiBisaDiretur,
    this.bolehDesimal,
    this.uuidProdukSatuan,
    this.racikan = false,
  });

  /// Uuid `PenjualanDetail` (dikirim sebagai `UuidPenjualanDetail`).
  final String uuid;
  final String? uuidProduk;
  final String namaProduk;
  final String simbolSatuan;
  final String jumlah;
  final String hargaSatuan;
  final String hargaPilihan;

  /// Nama pilihan (modifier) yang dipilih saat jual.
  final List<String> pilihan;
  final String bruto;
  final String jumlahDiskon;
  final String jumlahDiskonPesanan;
  final String biayaLayanan;
  final String jumlahPajak;
  final String totalBaris;
  final String jumlahSudahDiretur;
  final String jumlahBisaDiretur;

  /// Sisa `TotalBaris` yang belum dikembalikan; dipakai bila retur menghabiskan sisa baris.
  final String nilaiBisaDiretur;

  /// Apakah satuan baris ini boleh jumlah desimal (`BolehDesimal`); null bila server lama tidak mengirimnya.
  final bool? bolehDesimal;

  /// Uuid `ProdukSatuan` yang dijual (`UuidProdukSatuan`); null bila tidak ada atau server lama.
  final String? uuidProdukSatuan;

  /// Obat racikan (`Racikan`): tidak bisa diretur; false bila server lama tidak mengirimnya.
  final bool racikan;

  static BarisPenjualanCariPos DariJson(Map<String, Object?> json) => BarisPenjualanCariPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidProduk: UraiJson.AmbilTeksAtauNull(json['UuidProduk']),
    namaProduk: UraiJson.AmbilTeks(json['NamaProduk']),
    simbolSatuan: UraiJson.AmbilTeks(json['SimbolSatuan']),
    jumlah: UraiJson.AmbilDesimal(json['Jumlah']),
    hargaSatuan: UraiJson.AmbilDesimal(json['HargaSatuan']),
    hargaPilihan: UraiJson.AmbilDesimal(json['HargaPilihan']),
    pilihan: [
      for (final p in UraiJson.AmbilDaftarPeta(json['Pilihan']))
        if (UraiJson.AmbilTeks(p['Nama']).isNotEmpty) UraiJson.AmbilTeks(p['Nama']),
    ],
    bruto: UraiJson.AmbilDesimal(json['Bruto']),
    jumlahDiskon: UraiJson.AmbilDesimal(json['JumlahDiskon']),
    jumlahDiskonPesanan: UraiJson.AmbilDesimal(json['JumlahDiskonPesanan']),
    biayaLayanan: UraiJson.AmbilDesimal(json['BiayaLayanan']),
    jumlahPajak: UraiJson.AmbilDesimal(json['JumlahPajak']),
    totalBaris: UraiJson.AmbilDesimal(json['TotalBaris']),
    jumlahSudahDiretur: UraiJson.AmbilDesimal(json['JumlahSudahDiretur']),
    jumlahBisaDiretur: UraiJson.AmbilDesimal(json['JumlahBisaDiretur'], UraiJson.AmbilDesimal(json['Jumlah'])),
    nilaiBisaDiretur: UraiJson.AmbilDesimal(json['NilaiBisaDiretur'], UraiJson.AmbilDesimal(json['TotalBaris'])),
    bolehDesimal: UraiJson.AmbilBenarAtauNull(json['BolehDesimal']),
    uuidProdukSatuan: UraiJson.AmbilTeksAtauNull(json['UuidProdukSatuan']),
    racikan: UraiJson.AmbilBenar(json['Racikan']),
  );
}

/// Pembayaran penjualan asal (untuk informasi kasir saat memilih metode refund).
class PembayaranPenjualanCariPos {
  const PembayaranPenjualanCariPos({
    required this.uuid,
    required this.uuidMetodePembayaran,
    required this.jenisMetode,
    required this.namaMetode,
    required this.jumlah,
    required this.referensi,
  });

  final String uuid;
  final String? uuidMetodePembayaran;
  final String jenisMetode;
  final String namaMetode;
  final String jumlah;
  final String? referensi;

  static PembayaranPenjualanCariPos DariJson(Map<String, Object?> json) => PembayaranPenjualanCariPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    uuidMetodePembayaran: UraiJson.AmbilTeksAtauNull(json['UuidMetodePembayaran']),
    jenisMetode: UraiJson.AmbilTeks(json['JenisMetode']),
    namaMetode: UraiJson.AmbilTeks(json['NamaMetode']),
    jumlah: UraiJson.AmbilDesimal(json['Jumlah']),
    referensi: UraiJson.AmbilTeksAtauNull(json['Referensi']),
  );
}

/// Retur sebelumnya atas penjualan ini.
class ReturRingkasCariPos {
  const ReturRingkasCariPos({
    required this.uuid,
    required this.nomor,
    required this.dibuatPada,
    required this.totalRefund,
  });

  final String uuid;
  final String nomor;
  final String dibuatPada;
  final String totalRefund;

  static ReturRingkasCariPos DariJson(Map<String, Object?> json) => ReturRingkasCariPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    dibuatPada: UraiJson.AmbilTeks(json['DibuatPada']),
    totalRefund: UraiJson.AmbilDesimal(json['TotalRefund']),
  );
}

/// Satu penjualan yang bisa dipilih di layar retur (`penjualan/kandidat`), tanpa mengetik nomor struk utuh.
class KandidatReturPos {
  const KandidatReturPos({
    required this.uuid,
    required this.nomor,
    required this.labelStatus,
    required this.dibuatPada,
    required this.totalAkhir,
  });

  final String uuid;
  final String nomor;
  final String labelStatus;
  final String dibuatPada;
  final String totalAkhir;

  static KandidatReturPos DariJson(Map<String, Object?> json) => KandidatReturPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nomor: UraiJson.AmbilTeks(json['Nomor']),
    labelStatus: UraiJson.AmbilTeks(json['LabelStatus']),
    dibuatPada: UraiJson.AmbilTeks(json['DibuatPada']),
    totalAkhir: UraiJson.AmbilDesimal(json['TotalAkhir']),
  );
}

/// Hasil `penjualan/cari`.
class HasilCariPenjualan {
  const HasilCariPenjualan({
    required this.penjualan,
    required this.baris,
    required this.pembayaran,
    required this.retur,
  });

  final PenjualanCariPos penjualan;
  final List<BarisPenjualanCariPos> baris;
  final List<PembayaranPenjualanCariPos> pembayaran;
  final List<ReturRingkasCariPos> retur;

  static HasilCariPenjualan DariJson(Map<String, Object?> json) => HasilCariPenjualan(
    penjualan: PenjualanCariPos.DariJson(UraiJson.AmbilPeta(json['Penjualan'])),
    baris: UraiJson.AmbilDaftarPeta(json['Baris']).map(BarisPenjualanCariPos.DariJson).toList(),
    pembayaran: UraiJson.AmbilDaftarPeta(json['Pembayaran']).map(PembayaranPenjualanCariPos.DariJson).toList(),
    retur: UraiJson.AmbilDaftarPeta(json['Retur']).map(ReturRingkasCariPos.DariJson).toList(),
  );
}

/// Shift terbuka milik satu kasir di perangkat LAIN pada outlet yang sama (`shift/kasir-terbuka`, BR-06.1): dipakai
/// sebelum membuka shift agar kasir tidak membuka shift ganda.
class ShiftKasirPerangkatLainPos {
  const ShiftKasirPerangkatLainPos({required this.uuid, required this.dibukaPada, required this.namaPerangkat});

  final String uuid;
  final DateTime dibukaPada;
  final String namaPerangkat;

  static ShiftKasirPerangkatLainPos DariJson(Map<String, Object?> json) => ShiftKasirPerangkatLainPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    dibukaPada: DateTime.parse(UraiJson.AmbilTeks(json['DibukaPada'])),
    namaPerangkat: UraiJson.AmbilTeks(json['NamaPerangkat']),
  );
}

/// Shift perangkat ini yang masih terbuka di server (`shift/terbuka`), untuk ditutup paksa supervisor bila menahan
/// shift baru (`ShiftSudahTerbuka`).
class ShiftTerbukaServerPos {
  const ShiftTerbukaServerPos({
    required this.uuid,
    required this.status,
    required this.dibukaPada,
    required this.namaKasir,
    required this.kasAwal,
  });

  final String uuid;
  final String status;
  final DateTime dibukaPada;
  final String namaKasir;
  final String kasAwal;

  static ShiftTerbukaServerPos DariJson(Map<String, Object?> json) => ShiftTerbukaServerPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    status: UraiJson.AmbilTeks(json['Status']),
    dibukaPada: DateTime.parse(UraiJson.AmbilTeks(json['DibukaPada'])),
    namaKasir: UraiJson.AmbilTeks(json['NamaKasir']),
    kasAwal: UraiJson.AmbilDesimal(json['KasAwal']),
  );
}
