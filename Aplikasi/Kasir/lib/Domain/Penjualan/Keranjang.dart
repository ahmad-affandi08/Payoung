import 'package:mesin_kasir/MesinKasir.dart';

import '../Katalog/KatalogLokal.dart';
import '../Meja/KonteksPesananMeja.dart';
import '../Sesi/StafLokal.dart';
import 'Racikan.dart';

/// Diskon manual baris atau pesanan: tepat satu dari [persen] atau [jumlah] (BR-07.3).
class DiskonManual {
  const DiskonManual._({this.persen, this.jumlah});

  static DiskonManual DariPersen(Decimal persen) => DiskonManual._(persen: persen);

  static DiskonManual DariJumlah(Uang jumlah) => DiskonManual._(jumlah: jumlah);

  final Decimal? persen;
  final Uang? jumlah;

  DataPotongan KePotongan() => persen != null ? DataPotongan.DariPersen(persen!) : DataPotongan.DariJumlah(jumlah!);

  /// Bentuk kontrak `DiskonManual {Persen|Jumlah}` (Rincian F-07b).
  Map<String, Object?> KeJson() => persen != null ? {'Persen': persen.toString()} : {'Jumlah': jumlah!.KeString()};

  static DiskonManual? DariJson(Object? json) {
    if (json is! Map<String, Object?>) {
      return null;
    }
    if (json['Persen'] is String) {
      return DariPersen(Decimal.parse(json['Persen']! as String));
    }
    return json['Jumlah'] is String ? DariJumlah(Uang.Dari(json['Jumlah']! as String)) : null;
  }

  String AmbilLabel() => persen != null ? 'Diskon ${persen.toString().replaceAll('.', ',')}%' : 'Diskon';
}

class PilihanTerpilih {
  const PilihanTerpilih({required this.uuid, required this.nama, required this.harga});

  final String uuid;
  final String nama;
  final Uang harga;

  /// Bentuk kontrak `Pilihan [{UuidPilihan, Nama, Harga}]`.
  Map<String, Object?> KeJson() => {'UuidPilihan': uuid, 'Nama': nama, 'Harga': harga.KeString()};

  static PilihanTerpilih DariJson(Map<String, Object?> json) => PilihanTerpilih(
    uuid: json['UuidPilihan']! as String,
    nama: json['Nama']! as String,
    harga: Uang.Dari(json['Harga']! as String),
  );
}

/// Satu baris keranjang dengan snapshot harga & pajak saat ditambahkan (BR-07.2).
class ItemKeranjang {
  const ItemKeranjang({
    required this.uuid,
    required this.uuidProduk,
    required this.nama,
    required this.uuidProdukSatuan,
    required this.namaSatuan,
    required this.bolehDesimal,
    required this.jumlah,
    required this.hargaSatuan,
    this.pilihan = const [],
    this.catatan,
    this.diskon,
    this.hargaTermasukPajak,
    this.pajak = const [],
    this.staf = const [],
    this.nomorSeri = const [],
    this.hargaTerbuka = false,
    this.racikan,
    this.hargaAsli,
    this.alasanHarga,
    this.penyetujuHarga,
  });

  final String uuid;
  final String uuidProduk;
  final String nama;
  final String? uuidProdukSatuan;
  final String? namaSatuan;
  final bool bolehDesimal;
  final Kuantitas jumlah;
  final Uang hargaSatuan;
  final List<PilihanTerpilih> pilihan;
  final String? catatan;
  final DiskonManual? diskon;

  /// Null = ikut pengaturan outlet.
  final bool? hargaTermasukPajak;

  /// Jenis pajak dari kelompok pajak produk (disaring profil & tarif saat dihitung).
  final List<PajakProduk> pajak;

  /// F-18: Uuid karyawan yang melayani baris ini (komisi dibagi rata di server).
  final List<String> staf;

  /// F-05h: nomor seri/IMEI tiap unit yang dijual (produk bernomor seri); jumlah baris = banyaknya nomor.
  final List<String> nomorSeri;

  /// K-25: harga diketik kasir; tidak ditentukan ulang saat jumlah/satuan/pelanggan/kanal berubah dan tidak digabung.
  final bool hargaTerbuka;

  /// Apotek bagian 4: racikan yang dibawa baris jasa racik (null = baris biasa).
  final RacikanBaris? racikan;

  /// Harga manual (lapis 1 price engine, `pos.harga.timpa`): harga price engine sebelum kasir mengubahnya. Null =
  /// harga tidak diubah. [alasanHarga] wajib bila terisi; [penyetujuHarga] hanya bila kasirnya sendiri tanpa izin.
  final Uang? hargaAsli;
  final String? alasanHarga;
  final PenyetujuDiskon? penyetujuHarga;

  /// Harga ditentukan kasir (diketik atau diubah manual): tidak ditentukan ulang saat jumlah/satuan/pelanggan/kanal berubah.
  bool get CekHargaDikunci => hargaTerbuka || hargaAsli != null;

  Uang AmbilHargaPilihan() => pilihan.fold(Uang.Nol(), (total, p) => total.Tambah(p.harga));

  /// Baris yang sama (produk, satuan, pilihan) tanpa catatan & diskon digabung saat produk ditambah lagi.
  bool CekBisaDigabung(ItemKeranjang lain) =>
      uuidProduk == lain.uuidProduk &&
      uuidProdukSatuan == lain.uuidProdukSatuan &&
      catatan == null &&
      lain.catatan == null &&
      diskon == null &&
      lain.diskon == null &&
      staf.isEmpty &&
      lain.staf.isEmpty &&
      nomorSeri.isEmpty &&
      lain.nomorSeri.isEmpty &&
      !CekHargaDikunci &&
      !lain.CekHargaDikunci &&
      racikan == null &&
      lain.racikan == null &&
      pilihan.map((p) => p.uuid).toSet().containsAll(lain.pilihan.map((p) => p.uuid)) &&
      pilihan.length == lain.pilihan.length;

  ItemKeranjang Salin({
    Kuantitas? jumlah,
    Uang? hargaSatuan,
    String? uuidProdukSatuan,
    String? namaSatuan,
    bool? bolehDesimal,
    List<PilihanTerpilih>? pilihan,
    String? Function()? catatan,
    DiskonManual? Function()? diskon,
    List<String>? staf,
    List<String>? nomorSeri,
    RacikanBaris? racikan,
    Uang? Function()? hargaAsli,
    String? Function()? alasanHarga,
    PenyetujuDiskon? Function()? penyetujuHarga,
  }) => ItemKeranjang(
    uuid: uuid,
    uuidProduk: uuidProduk,
    nama: nama,
    uuidProdukSatuan: uuidProdukSatuan ?? this.uuidProdukSatuan,
    namaSatuan: namaSatuan ?? this.namaSatuan,
    bolehDesimal: bolehDesimal ?? this.bolehDesimal,
    jumlah: jumlah ?? this.jumlah,
    hargaSatuan: hargaSatuan ?? this.hargaSatuan,
    pilihan: pilihan ?? this.pilihan,
    catatan: catatan == null ? this.catatan : catatan(),
    diskon: diskon == null ? this.diskon : diskon(),
    hargaTermasukPajak: hargaTermasukPajak,
    pajak: pajak,
    staf: staf ?? this.staf,
    nomorSeri: nomorSeri ?? this.nomorSeri,
    hargaTerbuka: hargaTerbuka,
    racikan: racikan ?? this.racikan,
    hargaAsli: hargaAsli == null ? this.hargaAsli : hargaAsli(),
    alasanHarga: alasanHarga == null ? this.alasanHarga : alasanHarga(),
    penyetujuHarga: penyetujuHarga == null ? this.penyetujuHarga : penyetujuHarga(),
  );

  Map<String, Object?> KeJson() => {
    'Uuid': uuid,
    'UuidProduk': uuidProduk,
    'Nama': nama,
    'UuidProdukSatuan': uuidProdukSatuan,
    'NamaSatuan': namaSatuan,
    'BolehDesimal': bolehDesimal,
    'Jumlah': jumlah.KeString(),
    'HargaSatuan': hargaSatuan.KeString(),
    'Pilihan': [for (final p in pilihan) p.KeJson()],
    'Catatan': catatan,
    'DiskonManual': diskon?.KeJson(),
    'HargaTermasukPajak': hargaTermasukPajak,
    'Pajak': [for (final p in pajak) p.KeJson()],
    'Staf': staf,
    'NomorSeri': nomorSeri,
    'HargaTerbuka': hargaTerbuka,
    'Racikan': racikan?.KeJson(),
    'HargaAsli': hargaAsli?.KeString(),
    'AlasanHarga': alasanHarga,
    'PenyetujuHarga': penyetujuHarga?.KeJson(),
  };

  static ItemKeranjang DariJson(Map<String, Object?> json) => ItemKeranjang(
    uuid: json['Uuid']! as String,
    uuidProduk: json['UuidProduk']! as String,
    nama: json['Nama']! as String,
    uuidProdukSatuan: json['UuidProdukSatuan'] as String?,
    namaSatuan: json['NamaSatuan'] as String?,
    bolehDesimal: json['BolehDesimal'] == true,
    jumlah: Kuantitas.Dari(json['Jumlah']! as String),
    hargaSatuan: Uang.Dari(json['HargaSatuan']! as String),
    pilihan: [
      for (final p in (json['Pilihan'] as List<Object?>? ?? const []).whereType<Map<String, Object?>>())
        PilihanTerpilih.DariJson(p),
    ],
    catatan: json['Catatan'] as String?,
    diskon: DiskonManual.DariJson(json['DiskonManual']),
    hargaTermasukPajak: json['HargaTermasukPajak'] as bool?,
    pajak: [
      for (final p in (json['Pajak'] as List<Object?>? ?? const []).whereType<Map<String, Object?>>())
        PajakProduk.DariJson(p),
    ],
    staf: [...(json['Staf'] as List<Object?>? ?? const []).whereType<String>()],
    nomorSeri: [...(json['NomorSeri'] as List<Object?>? ?? const []).whereType<String>()],
    hargaTerbuka: json['HargaTerbuka'] == true,
    racikan: RacikanBaris.DariJson(json['Racikan']),
    // Keranjang tertahan dari versi sebelum harga manual tidak punya kunci ini; harga tidak diubah.
    hargaAsli: json['HargaAsli'] is String ? Uang.Dari(json['HargaAsli']! as String) : null,
    alasanHarga: json['AlasanHarga'] as String?,
    penyetujuHarga: PenyetujuDiskon.DariJson(json['PenyetujuHarga']),
  );
}

/// Staf yang menyetujui diskon di atas batas (BR-07.3), lolos PIN.
class PenyetujuDiskon {
  const PenyetujuDiskon({required this.uuid, required this.nama, required this.pemilik});

  final String uuid;
  final String nama;
  final bool pemilik;

  Map<String, Object?> KeJson() => {'Uuid': uuid, 'Nama': nama, 'Pemilik': pemilik};

  static PenyetujuDiskon? DariJson(Object? json) => json is Map<String, Object?> && json['Uuid'] is String
      ? PenyetujuDiskon(uuid: json['Uuid']! as String, nama: '${json['Nama'] ?? ''}', pemilik: json['Pemilik'] == true)
      : null;
}

/// Pelanggan yang dipilih untuk transaksi (F-16a). Nomor HP hanya tersamar. F-16b: [kodeTier] menentukan harga per
/// tier; [saldoPoin] hanya informasi dari pencarian online (null = tidak diketahui/offline). F-12: posisi kredit
/// terakhir yang diketahui perangkat untuk cek BR-12.1 ([sisaPiutang] null = belum pernah diketahui). F-16c bagian 3:
/// [hariLahir] `MM-DD`, [jumlahTransaksi] (null = tidak diketahui), dan [pemakaianPromo] per Uuid promo yang hitungan
/// harinya berlaku untuk tanggal bisnis [pemakaianPada] (`YYYY-MM-DD`).
class PelangganTerpilih {
  const PelangganTerpilih({
    required this.uuid,
    required this.nama,
    required this.noHpSamar,
    this.kodeTier,
    this.namaTier,
    this.saldoPoin,
    this.limitKredit,
    this.sisaPiutang,
    this.hariLewatJatuhTempo,
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
  final int? saldoPoin;
  final String? limitKredit;
  final String? sisaPiutang;
  final int? hariLewatJatuhTempo;
  final String? hariLahir;
  final int? jumlahTransaksi;
  final Map<String, PemakaianPromoPelanggan> pemakaianPromo;
  final String? pemakaianPada;

  /// Pemakaian promo untuk tanggal bisnis [tanggal]: hitungan hari berlaku hanya pada tanggal [pemakaianPada].
  Map<String, PemakaianPromoPelanggan> AmbilPemakaianPada(String tanggal) => {
    for (final e in pemakaianPromo.entries)
      e.key: PemakaianPromoPelanggan(hari: pemakaianPada == tanggal ? e.value.hari : 0, promo: e.value.promo),
  };

  Map<String, Object?> KeJson() => {
    'Uuid': uuid,
    'Nama': nama,
    'NoHpSamar': noHpSamar,
    'KodeTier': kodeTier,
    'NamaTier': namaTier,
    'SaldoPoin': saldoPoin,
    'LimitKredit': limitKredit,
    'SisaPiutang': sisaPiutang,
    'HariLewatJatuhTempo': hariLewatJatuhTempo,
    'HariLahir': hariLahir,
    'JumlahTransaksi': jumlahTransaksi,
    'PemakaianPromo': KodekPemakaianPromo.KeJson(pemakaianPromo),
    'PemakaianPada': pemakaianPada,
  };

  static PelangganTerpilih? DariJson(Object? json) => json is Map<String, Object?> && json['Uuid'] is String
      ? PelangganTerpilih(
          uuid: json['Uuid']! as String,
          nama: '${json['Nama'] ?? ''}',
          noHpSamar: '${json['NoHpSamar'] ?? ''}',
          kodeTier: json['KodeTier'] as String?,
          namaTier: json['NamaTier'] as String?,
          saldoPoin: json['SaldoPoin'] as int?,
          limitKredit: json['LimitKredit'] as String?,
          sisaPiutang: json['SisaPiutang'] as String?,
          hariLewatJatuhTempo: json['HariLewatJatuhTempo'] as int?,
          hariLahir: json['HariLahir'] as String?,
          jumlahTransaksi: json['JumlahTransaksi'] as int?,
          pemakaianPromo: KodekPemakaianPromo.DariJson(json['PemakaianPromo']),
          pemakaianPada: json['PemakaianPada'] as String?,
        )
      : null;
}

/// Bentuk JSON pemakaian promo pelanggan `{UuidPromo: {Hari, Promo}}` (keranjang tersimpan & cache `PelangganLokal`).
abstract final class KodekPemakaianPromo {
  static Map<String, Object?> KeJson(Map<String, PemakaianPromoPelanggan> peta) => {
    for (final e in peta.entries) e.key: {'Hari': e.value.hari, 'Promo': e.value.promo},
  };

  static Map<String, PemakaianPromoPelanggan> DariJson(Object? json) => {
    if (json is Map<String, Object?>)
      for (final e in json.entries)
        if (e.value case final Map<String, Object?> v)
          e.key: PemakaianPromoPelanggan(hari: v['Hari'] as int? ?? 0, promo: v['Promo'] as int? ?? 0),
  };
}

/// Poin pelanggan yang ditukar sebagai diskon pesanan sebelum pajak (F-16b, J-16.4). Diperiksa online saat dipasang
/// (§18.4); [nilai] = poin × nilai tukar per poin, dibatasi sisa subtotal.
class TukarPoin {
  const TukarPoin({required this.poin, required this.nilai});

  final int poin;
  final Uang nilai;

  Map<String, Object?> KeJson() => {'Poin': poin, 'Nilai': nilai.KeString()};

  static TukarPoin? DariJson(Object? json) =>
      json is Map<String, Object?> && json['Poin'] is int && json['Nilai'] is String
      ? TukarPoin(poin: json['Poin']! as int, nilai: Uang.Dari(json['Nilai']! as String))
      : null;
}

/// Voucher yang dipesan online untuk keranjang ini (F-16c bagian 2, wajib online). [uuidPenjualan] = Uuid penjualan yang
/// akan dibuat (voucher dipesan server untuk penjualan itu); [promo] = promo voucher bentuk `PromoPos.KeJson` agar
/// bisa dievaluasi walau daftar promo tersimpan belum diperbarui.
class VoucherKeranjang {
  const VoucherKeranjang({
    required this.kode,
    required this.uuidPenjualan,
    required this.uuidPromo,
    required this.namaPromo,
    required this.promo,
  });

  final String kode;
  final String uuidPenjualan;
  final String uuidPromo;
  final String namaPromo;
  final Map<String, Object?> promo;

  Map<String, Object?> KeJson() => {
    'Kode': kode,
    'UuidPenjualan': uuidPenjualan,
    'UuidPromo': uuidPromo,
    'NamaPromo': namaPromo,
    'Promo': promo,
  };

  static VoucherKeranjang? DariJson(Object? json) =>
      json is Map<String, Object?> && json['Kode'] is String && json['UuidPenjualan'] is String
      ? VoucherKeranjang(
          kode: json['Kode']! as String,
          uuidPenjualan: json['UuidPenjualan']! as String,
          uuidPromo: '${json['UuidPromo'] ?? ''}',
          namaPromo: '${json['NamaPromo'] ?? ''}',
          promo: json['Promo'] is Map<String, Object?> ? json['Promo']! as Map<String, Object?> : const {},
        )
      : null;
}

/// Asal uang muka keranjang: pre-order F-12 atau pesanan toko online F-17. Server menolak dua sumber sekaligus
/// (`UangMukaDuaSumber`), karena `Penjualan.Buat` hanya punya satu baris bayar Uang Muka.
enum SumberUangMuka {
  praPesan,
  pesananOnline;

  /// Kunci `Penjualan.Buat` yang merujuk dokumen asalnya.
  String get KunciOutbox => this == SumberUangMuka.praPesan ? 'UuidPesananPenjualan' : 'UuidPesananOnline';

  String get Sebutan => this == SumberUangMuka.praPesan ? 'pre-order' : 'pesanan online';
}

/// Dokumen yang sedang ditagihkan dan menyumbang uang muka (F-12 bagian 2 pre-order, F-17 bagian 2 pesanan online):
/// uang mukanya dipakai lewat metode sistem "Uang muka (DP)" ([uuidMetode]) dan `Penjualan.Buat` merujuk [uuid].
/// [sisaUangMuka] bisa nol — pesanan online bayar saat ambil/COD ditagih lewat jalur yang sama tanpa baris DP.
class PraPesananKeranjang {
  const PraPesananKeranjang({
    required this.uuid,
    required this.nomor,
    required this.sisaUangMuka,
    required this.uuidMetode,
    required this.namaMetode,
    this.sumber = SumberUangMuka.praPesan,
  });

  final String uuid;
  final String nomor;
  final Uang sisaUangMuka;
  final String uuidMetode;
  final String namaMetode;
  final SumberUangMuka sumber;

  Map<String, Object?> KeJson() => {
    'Uuid': uuid,
    'Nomor': nomor,
    'SisaUangMuka': sisaUangMuka.KeString(),
    'UuidMetode': uuidMetode,
    'NamaMetode': namaMetode,
    'Sumber': sumber.name,
  };

  static PraPesananKeranjang? DariJson(Object? json) =>
      json is Map<String, Object?> && json['Uuid'] is String && json['SisaUangMuka'] is String
      ? PraPesananKeranjang(
          uuid: json['Uuid']! as String,
          nomor: '${json['Nomor'] ?? ''}',
          sisaUangMuka: Uang.Dari(json['SisaUangMuka']! as String),
          uuidMetode: '${json['UuidMetode'] ?? ''}',
          namaMetode: '${json['NamaMetode'] ?? 'Uang muka (DP)'}',
          // Draf keranjang yang disimpan sebelum F-17 bagian 2 tidak punya kunci ini: selalu pre-order.
          sumber: json['Sumber'] == SumberUangMuka.pesananOnline.name
              ? SumberUangMuka.pesananOnline
              : SumberUangMuka.praPesan,
        )
      : null;
}

/// Persetujuan retur tukar barang. PIN penyetuju diminta di akhir, saat pembayaran diselesaikan (satu kali, saat nilai
/// transaksi sudah pasti), bukan sebelum kasir memilih barang pengganti. [staf] diisi layar Bayar setelah PIN benar dan
/// dipakai [TukarKeranjang.simpanRetur].
class PenyetujuTukar {
  PenyetujuTukar({required this.izin, required this.pesan, this.bolehSendiri = false});

  /// Izin yang harus dimiliki penyetuju (`penjualan.retur` atau `penjualan.retur.tanpa-struk`).
  final String izin;

  /// Kalimat alasan di dialog PIN.
  final String pesan;

  /// True = kasir yang membayar boleh menyetujui sendiri bila ber-izin [izin] (retur dari struk); false = selalu PIN
  /// penyetuju (retur tanpa struk). Dinilai saat pembayaran, memakai kasir yang sedang bertugas, bukan yang memulai
  /// tukar (kasir bisa berganti sebelum bayar).
  final bool bolehSendiri;

  StafLokal? staf;

  /// Kasir yang membayar; diisi layar Bayar dan dipakai sebagai pelaku retur.
  StafLokal? kasir;

  bool get siap => staf != null;
}

/// K-11 tukar barang: barang yang diretur menjadi pembayaran barang pengganti. Retur **belum** disimpan selama kasir
/// memilih barang pengganti; [simpanRetur] dipanggil saat pembayaran diselesaikan, di transaksi lokal yang sama dengan
/// penjualan pengganti, dengan pembagian refund `Tukar` (sebesar yang dipakai membayar) dan `Tunai` (selisih yang
/// dikembalikan ke pelanggan bila barang pengganti lebih murah). Tidak ikut draf keranjang tersimpan (K-4): retur
/// tertunda hanya hidup selama aplikasi terbuka, jadi tidak pernah ada retur setengah jadi.
class TukarKeranjang {
  const TukarKeranjang({
    required this.uuidRetur,
    required this.nomorPenjualanAsal,
    required this.nilai,
    required this.uuidMetode,
    required this.namaMetode,
    required this.simpanRetur,
    required this.penyetuju,
    this.tanpaStruk = false,
  });

  /// Persetujuan yang diminta saat pembayaran diselesaikan.
  final PenyetujuTukar penyetuju;

  /// Uuid retur yang akan dibuat (dirujuk `UuidReturTukar` penjualan pengganti).
  final String uuidRetur;
  final String nomorPenjualanAsal;

  /// Nilai barang yang diretur (setelah potong piutang bila penjualan asal tempo).
  final Uang nilai;
  final String uuidMetode;
  final String namaMetode;

  /// K28: retur tanpa struk tidak boleh dikembalikan tunai, jadi barang pengganti minimal senilai [nilai].
  final bool tanpaStruk;

  /// Simpan retur dengan refund `Tukar` [tukar] dan `Tunai` [tunai] (Σ = [nilai]); hasil = nomor retur.
  final Future<String> Function({required Uang tukar, required Uang tunai}) simpanRetur;

  /// Nilai tukar yang dipakai membayar penjualan senilai [total]: tidak pernah melebihi total belanja.
  Uang HitungDipakai(Uang total) => nilai.Bandingkan(total) > 0 ? total : nilai;
}

/// Reservasi layanan yang sedang dilayani (F-07 mode service bagian 2): `Penjualan.Buat` merujuk [uuid] supaya server
/// menyelesaikan dan menautkan reservasinya.
class ReservasiKeranjang {
  const ReservasiKeranjang({required this.uuid, required this.nomor});

  final String uuid;
  final String nomor;

  Map<String, Object?> KeJson() => {'Uuid': uuid, 'Nomor': nomor};

  static ReservasiKeranjang? DariJson(Object? json) => json is Map<String, Object?> && json['Uuid'] is String
      ? ReservasiKeranjang(uuid: json['Uuid']! as String, nomor: '${json['Nomor'] ?? ''}')
      : null;
}

/// Perintah kerja bengkel yang sedang ditagih (Bengkel bagian 2, §9.10): `Penjualan.Buat` merujuk [uuid] supaya server
/// menandainya Ditagih dan menautkannya di transaksi yang sama. [nomorPolisi] & [labelKendaraan] untuk struk & layar.
class PerintahKerjaKeranjang {
  const PerintahKerjaKeranjang({required this.uuid, required this.nomor, this.nomorPolisi, this.labelKendaraan});

  final String uuid;
  final String nomor;
  final String? nomorPolisi;
  final String? labelKendaraan;

  Map<String, Object?> KeJson() => {
    'Uuid': uuid,
    'Nomor': nomor,
    'NomorPolisi': nomorPolisi,
    'LabelKendaraan': labelKendaraan,
  };

  static PerintahKerjaKeranjang? DariJson(Object? json) => json is Map<String, Object?> && json['Uuid'] is String
      ? PerintahKerjaKeranjang(
          uuid: json['Uuid']! as String,
          nomor: '${json['Nomor'] ?? ''}',
          nomorPolisi: json['NomorPolisi'] as String?,
          labelKendaraan: json['LabelKendaraan'] as String?,
        )
      : null;
}

/// Tiket laundry yang dibuat bersama penjualan (§9.9): jenis layanan, berat (kg, 2 desimal) dan/atau item satuan,
/// parfum, catatan, perkiraan selesai (UTC), serta nama & HP penerima (bila tanpa pelanggan tertaut). Dikirim sebagai
/// blok `Laundry` di `Penjualan.Buat`.
class LaundryKeranjang {
  const LaundryKeranjang({
    required this.jenisLayanan,
    required this.estimasiSelesaiPada,
    this.berat,
    this.item = const [],
    this.parfum,
    this.catatan,
    this.namaPelanggan,
    this.noHp,
  });

  static const String reguler = 'Reguler';
  static const String express = 'Express';

  final String jenisLayanan;
  final DateTime estimasiSelesaiPada;
  final Decimal? berat;
  final List<({String nama, int jumlah})> item;
  final String? parfum;
  final String? catatan;
  final String? namaPelanggan;
  final String? noHp;

  /// "3,5 kg | Bed cover ×1".
  String RingkasIsi() {
    final b = berat;
    return [
      if (b != null) '${b.toString().replaceAll('.', ',')} kg',
      for (final i in item) '${i.nama} ×${i.jumlah}',
    ].join(' | ');
  }

  Map<String, Object?> KeJson() => {
    'JenisLayanan': jenisLayanan,
    'Berat': berat?.toStringAsFixed(2),
    'Item': [
      for (final i in item) {'Nama': i.nama, 'Jumlah': i.jumlah},
    ],
    'Parfum': parfum,
    'Catatan': catatan,
    'EstimasiSelesaiPada': estimasiSelesaiPada.toUtc().toIso8601String(),
    'NamaPelanggan': namaPelanggan,
    'NoHp': noHp,
  };

  static LaundryKeranjang? DariJson(Object? json) {
    if (json is! Map<String, Object?> || json['JenisLayanan'] is! String) {
      return null;
    }
    final estimasi = DateTime.tryParse('${json['EstimasiSelesaiPada'] ?? ''}');
    if (estimasi == null) {
      return null;
    }
    return LaundryKeranjang(
      jenisLayanan: json['JenisLayanan']! as String,
      estimasiSelesaiPada: estimasi.toUtc(),
      berat: json['Berat'] is String ? Decimal.tryParse(json['Berat']! as String) : null,
      item: [
        for (final i in (json['Item'] as List<Object?>? ?? const []).whereType<Map<String, Object?>>())
          (nama: '${i['Nama'] ?? ''}', jumlah: i['Jumlah'] is int ? i['Jumlah']! as int : 1),
      ],
      parfum: json['Parfum'] as String?,
      catatan: json['Catatan'] as String?,
      namaPelanggan: json['NamaPelanggan'] as String?,
      noHp: json['NoHp'] as String?,
    );
  }
}

/// Keranjang yang sedang dibangun kasir (belum tersimpan sebagai penjualan). [pesananMeja] terisi saat pesanan meja
/// dibuka (F-07 mode meja): pembayarannya menutup pesanan terbuka itu.
class Keranjang {
  // Bukan `const`: `Uang.Nol()` sebagai bawaan `biayaKirim`/`diskonKirim` bukan ekspresi konstan (F-17 bagian 3).
  Keranjang({
    this.baris = const [],
    this.diskonPesanan,
    this.penyetuju,
    this.catatan,
    this.pesananMeja,
    this.pelanggan,
    this.tukarPoin,
    this.voucher,
    this.praPesan,
    this.reservasi,
    this.perintahKerja,
    this.laundry,
    this.kanal,
    this.namaPemesan,
    this.tukar,
    Uang? biayaKirim,
    Uang? diskonKirim,
  }) : biayaKirim = biayaKirim ?? Uang.Nol(),
       diskonKirim = diskonKirim ?? Uang.Nol();

  static final Keranjang kosong = Keranjang();

  final List<ItemKeranjang> baris;
  final DiskonManual? diskonPesanan;
  final PenyetujuDiskon? penyetuju;
  final String? catatan;
  final KonteksPesananMeja? pesananMeja;

  /// F-16a: null = pelanggan umum.
  final PelangganTerpilih? pelanggan;

  /// F-16b: poin [pelanggan] yang ditukar; dilepas saat pelanggan diganti.
  final TukarPoin? tukarPoin;

  /// F-16c bagian 2: voucher yang sudah dipesan online.
  final VoucherKeranjang? voucher;

  /// F-12 bagian 2: pre-order yang sedang diambil.
  final PraPesananKeranjang? praPesan;

  /// F-07 mode service: reservasi yang sedang dilayani.
  final ReservasiKeranjang? reservasi;

  /// Bengkel bagian 2: perintah kerja yang sedang ditagih.
  final PerintahKerjaKeranjang? perintahKerja;

  /// Laundry (§9.9): tiket laundry yang dibuat bersama penjualan ini.
  final LaundryKeranjang? laundry;

  /// X8: kanal yang dipilih kasir (GoFood, GrabFood, …). Null = bawaan (pesanan meja `MakanDiTempat`, selain itu
  /// `BawaPulang`); lihat `LayananPenjualan.AmbilKanal`.
  final KanalPenjualan? kanal;

  /// v3.52 (§9.2): nama yang dipanggil saat pesanan bayar-dulu siap; bukan data pelanggan, tidak wajib.
  final String? namaPemesan;

  /// K-11: tukar barang yang sedang berlangsung (null = penjualan biasa).
  final TukarKeranjang? tukar;

  /// F-17 bagian 3: ongkir yang ditagih ke pembeli (nol = tanpa ongkir) dan diskonnya (mis. gratis ongkir). Dipisah
  /// supaya struk tetap bisa menulis ongkirnya beserta potongannya, bukan ongkir yang hilang.
  final Uang biayaKirim;
  final Uang diskonKirim;

  bool get CekKosong => baris.isEmpty;

  /// Keranjang benar-benar bebas: tanpa baris dan tanpa dokumen yang sedang ditagih (tukar barang, pre-order, reservasi,
  /// perintah kerja). Dipakai sebelum memuat dokumen lain ke keranjang dan oleh kunci otomatis, supaya keranjang tukar
  /// yang belum berisi barang pengganti tidak tertimpa atau terkunci diam-diam.
  bool get CekBebas => baris.isEmpty && tukar == null && praPesan == null && reservasi == null && perintahKerja == null;

  /// Ongkir yang benar-benar ditagih (bisa nol karena gratis ongkir, meski [biayaKirim] tidak nol).
  Uang HitungBiayaKirimNetto() => biayaKirim.Kurangi(diskonKirim);

  Kuantitas HitungJumlahItem() => baris.fold(Kuantitas.Nol(), (total, b) => total.Tambah(b.jumlah));

  Keranjang Salin({
    List<ItemKeranjang>? baris,
    DiskonManual? Function()? diskonPesanan,
    PenyetujuDiskon? Function()? penyetuju,
    String? Function()? catatan,
    KonteksPesananMeja? Function()? pesananMeja,
    PelangganTerpilih? Function()? pelanggan,
    TukarPoin? Function()? tukarPoin,
    VoucherKeranjang? Function()? voucher,
    PraPesananKeranjang? Function()? praPesan,
    ReservasiKeranjang? Function()? reservasi,
    PerintahKerjaKeranjang? Function()? perintahKerja,
    LaundryKeranjang? Function()? laundry,
    KanalPenjualan? Function()? kanal,
    String? Function()? namaPemesan,
    TukarKeranjang? Function()? tukar,
    Uang? biayaKirim,
    Uang? diskonKirim,
  }) => Keranjang(
    baris: baris ?? this.baris,
    diskonPesanan: diskonPesanan == null ? this.diskonPesanan : diskonPesanan(),
    penyetuju: penyetuju == null ? this.penyetuju : penyetuju(),
    catatan: catatan == null ? this.catatan : catatan(),
    pesananMeja: pesananMeja == null ? this.pesananMeja : pesananMeja(),
    pelanggan: pelanggan == null ? this.pelanggan : pelanggan(),
    tukarPoin: tukarPoin == null ? this.tukarPoin : tukarPoin(),
    voucher: voucher == null ? this.voucher : voucher(),
    praPesan: praPesan == null ? this.praPesan : praPesan(),
    reservasi: reservasi == null ? this.reservasi : reservasi(),
    perintahKerja: perintahKerja == null ? this.perintahKerja : perintahKerja(),
    laundry: laundry == null ? this.laundry : laundry(),
    kanal: kanal == null ? this.kanal : kanal(),
    namaPemesan: namaPemesan == null ? this.namaPemesan : namaPemesan(),
    tukar: tukar == null ? this.tukar : tukar(),
    biayaKirim: biayaKirim ?? this.biayaKirim,
    diskonKirim: diskonKirim ?? this.diskonKirim,
  );

  Map<String, Object?> KeJson() => {
    'Baris': [for (final b in baris) b.KeJson()],
    'DiskonManualPesanan': diskonPesanan?.KeJson(),
    'Penyetuju': penyetuju?.KeJson(),
    'Catatan': catatan,
    'Pelanggan': pelanggan?.KeJson(),
    'TukarPoin': tukarPoin?.KeJson(),
    'Voucher': voucher?.KeJson(),
    'PraPesan': praPesan?.KeJson(),
    'Reservasi': reservasi?.KeJson(),
    'PerintahKerja': perintahKerja?.KeJson(),
    'Laundry': laundry?.KeJson(),
    'Kanal': kanal?.name,
    'NamaPemesan': namaPemesan,
    'BiayaKirim': biayaKirim.KeString(),
    'DiskonKirim': diskonKirim.KeString(),
  };

  static Keranjang DariJson(Map<String, Object?> json) => Keranjang(
    baris: [
      for (final b in (json['Baris'] as List<Object?>? ?? const []).whereType<Map<String, Object?>>())
        ItemKeranjang.DariJson(b),
    ],
    diskonPesanan: DiskonManual.DariJson(json['DiskonManualPesanan']),
    penyetuju: PenyetujuDiskon.DariJson(json['Penyetuju']),
    catatan: json['Catatan'] as String?,
    pelanggan: PelangganTerpilih.DariJson(json['Pelanggan']),
    tukarPoin: TukarPoin.DariJson(json['TukarPoin']),
    voucher: VoucherKeranjang.DariJson(json['Voucher']),
    praPesan: PraPesananKeranjang.DariJson(json['PraPesan']),
    reservasi: ReservasiKeranjang.DariJson(json['Reservasi']),
    perintahKerja: PerintahKerjaKeranjang.DariJson(json['PerintahKerja']),
    laundry: LaundryKeranjang.DariJson(json['Laundry']),
    // Kanal yang tidak dikenal aplikasi versi ini = bawaan.
    kanal: KanalPenjualan.values.where((k) => k.name == json['Kanal']).firstOrNull,
    namaPemesan: json['NamaPemesan'] as String?,
    // Keranjang tertahan dari versi sebelum F-17 bagian 3 tidak punya kunci ini; tanpa ongkir.
    biayaKirim: json['BiayaKirim'] is String ? Uang.Dari(json['BiayaKirim']! as String) : null,
    diskonKirim: json['DiskonKirim'] is String ? Uang.Dari(json['DiskonKirim']! as String) : null,
  );
}
