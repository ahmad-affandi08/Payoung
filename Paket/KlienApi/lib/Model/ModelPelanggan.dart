import 'UraiJson.dart';

/// Hasil cari pelanggan dari POS (F-16a, `GET /api/pos/v1/pelanggan?kata=`). Nomor HP tersamar (`0812****7890`).
/// F-16b: kode & nama tier (harga per tier) dan saldo poin; server lama tanpa kolom ini = tanpa tier & 0 poin.
/// F-12: posisi kredit untuk cek BR-12.1 saat offline ([limitKredit] null = tidak boleh tempo tanpa penyetuju).
/// F-16c bagian 3 (promo): [hariLahir] `MM-DD` (tanpa tahun), [jumlahTransaksi] (null = server lama), dan
/// [pemakaianPromo] per Uuid promo pada tanggal bisnis hari ini.
class PelangganPos {
  const PelangganPos({
    required this.uuid,
    required this.nama,
    required this.noHpSamar,
    this.kodeTier,
    this.namaTier,
    this.saldoPoin = 0,
    this.limitKredit,
    this.sisaPiutang = '0',
    this.hariLewatJatuhTempo = 0,
    this.hariLahir,
    this.jumlahTransaksi,
    this.pemakaianPromo = const {},
    this.pemakaianPada,
  });

  final String uuid;
  final String nama;
  final String noHpSamar;
  final String? kodeTier;
  final String? namaTier;
  final int saldoPoin;
  final String? limitKredit;
  final String sisaPiutang;
  final int hariLewatJatuhTempo;
  final String? hariLahir;
  final int? jumlahTransaksi;
  final Map<String, ({int hari, int promo})> pemakaianPromo;

  /// Tanggal bisnis `YYYY-MM-DD` acuan hitungan harian [pemakaianPromo].
  final String? pemakaianPada;

  static PelangganPos DariJson(Map<String, Object?> json, {String? tanggalBisnis}) => PelangganPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    nama: UraiJson.AmbilTeks(json['Nama']),
    noHpSamar: UraiJson.AmbilTeks(json['NoHp']),
    kodeTier: UraiJson.AmbilTeksAtauNull(json['KodeTier']),
    namaTier: UraiJson.AmbilTeksAtauNull(json['NamaTier']),
    saldoPoin: UraiJson.AmbilBulat(json['SaldoPoin']),
    limitKredit: UraiJson.AmbilDesimalAtauNull(json['LimitKredit']),
    sisaPiutang: UraiJson.AmbilDesimal(json['SisaPiutang']),
    hariLewatJatuhTempo: UraiJson.AmbilBulat(json['HariLewatJatuhTempo']),
    hariLahir: UraiJson.AmbilTeksAtauNull(json['HariLahir']),
    jumlahTransaksi: json['JumlahTransaksi'] is int ? json['JumlahTransaksi']! as int : null,
    pemakaianPromo: {
      if (json['PemakaianPromo'] case final Map<String, Object?> peta)
        for (final e in peta.entries)
          if (e.value case final Map<String, Object?> v)
            e.key: (hari: UraiJson.AmbilBulat(v['Hari']), promo: UraiJson.AmbilBulat(v['Promo'])),
    },
    pemakaianPada: tanggalBisnis,
  );
}

/// Saldo poin terkini & aturan tukar (F-16b, `GET /api/pos/v1/pelanggan/{uuid}/poin`); penukaran poin wajib online
/// (§18.4). [nilaiTukarPoin] string desimal Rupiah per poin.
/// Saldo deposit terkini pelanggan (`GET /pelanggan/{uuid}/deposit`, F-16d bagian 1). Bisa minus bila dipakai
/// dua perangkat bersamaan.
class SaldoDepositPos {
  const SaldoDepositPos({required this.uuid, required this.saldoDeposit, required this.berlaku});

  final String uuid;
  final String saldoDeposit;

  /// Paket usaha termasuk fitur deposit.
  final bool berlaku;

  static SaldoDepositPos DariJson(Map<String, Object?> json) {
    final pelanggan = UraiJson.AmbilPeta(json['Pelanggan']);
    return SaldoDepositPos(
      uuid: UraiJson.AmbilTeks(pelanggan['Uuid']),
      saldoDeposit: UraiJson.AmbilDesimal(pelanggan['SaldoDeposit']),
      berlaku: UraiJson.AmbilBenar(json['Berlaku']),
    );
  }
}

class SaldoPoinPos {
  const SaldoPoinPos({
    required this.uuid,
    required this.saldoPoin,
    required this.berlaku,
    required this.nilaiTukarPoin,
    required this.minimalTukarPoin,
  });

  final String uuid;
  final int saldoPoin;

  /// Loyalti diaktifkan tenant dan termasuk paketnya.
  final bool berlaku;
  final String nilaiTukarPoin;
  final int minimalTukarPoin;

  static SaldoPoinPos DariJson(Map<String, Object?> json) {
    final pelanggan = UraiJson.AmbilPeta(json['Pelanggan']);
    final tukar = UraiJson.AmbilPeta(json['TukarPoin']);
    return SaldoPoinPos(
      uuid: UraiJson.AmbilTeks(pelanggan['Uuid']),
      saldoPoin: UraiJson.AmbilBulat(pelanggan['SaldoPoin']),
      berlaku: UraiJson.AmbilBenar(tukar['Berlaku']),
      nilaiTukarPoin: UraiJson.AmbilDesimal(tukar['NilaiTukarPoin']),
      minimalTukarPoin: UraiJson.AmbilBulat(tukar['MinimalTukarPoin'], 1),
    );
  }
}

/// Paket sesi aktif pelanggan (F-16d bagian 2, `GET pelanggan/{uuid}/sesi`, wajib online).
class SaldoSesiPos {
  const SaldoSesiPos({required this.uuidPelanggan, required this.berlaku, required this.paket});

  final String uuidPelanggan;

  /// Paket usaha termasuk fitur paket sesi.
  final bool berlaku;
  final List<PaketSesiPelangganPos> paket;

  static SaldoSesiPos DariJson(Map<String, Object?> json) => SaldoSesiPos(
    uuidPelanggan: UraiJson.AmbilTeks(UraiJson.AmbilPeta(json['Pelanggan'])['Uuid']),
    berlaku: UraiJson.AmbilBenar(json['Berlaku']),
    paket: [for (final p in UraiJson.AmbilDaftarPeta(json['Paket'])) PaketSesiPelangganPos.DariJson(p)],
  );
}

class PaketSesiPelangganPos {
  const PaketSesiPelangganPos({
    required this.uuid,
    required this.namaPaket,
    required this.jumlahSesi,
    required this.sisaSesi,
    required this.berlakuSampai,
    required this.nomorPenjualan,
    required this.semuaProdukJasa,
    required this.produkBerlaku,
  });

  /// Uuid saldo sesi (dikirim di outbox `Sesi.Pakai`).
  final String uuid;
  final String namaPaket;
  final int jumlahSesi;
  final int sisaSesi;

  /// `YYYY-MM-DD` atau null (tanpa batas).
  final String? berlakuSampai;
  final String nomorPenjualan;

  /// Semua produk Jasa boleh ditukar; bila false hanya [produkBerlaku].
  final bool semuaProdukJasa;
  final List<({String uuid, String nama})> produkBerlaku;

  static PaketSesiPelangganPos DariJson(Map<String, Object?> json) => PaketSesiPelangganPos(
    uuid: UraiJson.AmbilTeks(json['Uuid']),
    namaPaket: UraiJson.AmbilTeks(json['NamaPaket']),
    jumlahSesi: UraiJson.AmbilBulat(json['JumlahSesi']),
    sisaSesi: UraiJson.AmbilBulat(json['SisaSesi']),
    berlakuSampai: UraiJson.AmbilTeksAtauNull(json['BerlakuSampai']),
    nomorPenjualan: UraiJson.AmbilTeks(json['NomorPenjualan']),
    semuaProdukJasa: UraiJson.AmbilBenar(json['SemuaProdukJasa']),
    produkBerlaku: [
      for (final p in UraiJson.AmbilDaftarPeta(json['ProdukBerlaku']))
        (uuid: UraiJson.AmbilTeks(p['Uuid']), nama: UraiJson.AmbilTeks(p['Nama'])),
    ],
  );
}

/// Gift card hasil cek online di kasir (`POST /gift-card/cek`, CRM-06, D-153). [kode] sudah dinormalkan server
/// (`XXXX-XXXX-XXXX`); [bisaDipakai] false disertai [alasan] (hangus, dibatalkan, lewat masa berlaku, atau saldo habis).
/// Saldo bisa minus bila kartu dipakai dua perangkat bersamaan.
class GiftCardPos {
  const GiftCardPos({
    required this.kode,
    required this.saldo,
    required this.status,
    required this.berlakuSampai,
    required this.bisaDipakai,
    required this.alasan,
  });

  final String kode;
  final String saldo;
  final String status;

  /// `YYYY-MM-DD`; null = tanpa batas masa berlaku.
  final String? berlakuSampai;
  final bool bisaDipakai;
  final String? alasan;

  static GiftCardPos DariJson(Map<String, Object?> json) => GiftCardPos(
    kode: UraiJson.AmbilTeks(json['Kode']),
    saldo: UraiJson.AmbilDesimal(json['Saldo']),
    status: UraiJson.AmbilTeks(json['Status']),
    berlakuSampai: UraiJson.AmbilTeksAtauNull(json['BerlakuSampai']),
    bisaDipakai: UraiJson.AmbilBenar(json['BisaDipakai']),
    alasan: UraiJson.AmbilTeksAtauNull(json['Alasan']),
  );
}
