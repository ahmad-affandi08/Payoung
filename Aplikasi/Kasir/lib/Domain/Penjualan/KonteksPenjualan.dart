import 'dart:convert';
import 'dart:developer' as developer;

import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriKatalog.dart';
import '../Dapur/LayananTiketDapur.dart';
import '../Meja/MinimumChargePos.dart';

/// Jenis metode pembayaran fase 1 (sama dengan server, Rincian F-07b langkah 9).
abstract final class JenisMetodeBayar {
  static const String tunai = 'Tunai';
  static const String qrisStatis = 'QrisStatis';

  /// v2.05: QRIS dinamis lewat gerbang pembayaran aktif (wajib online; lunas dipastikan server, bukan kasir).
  static const String qrisDinamis = 'QrisDinamis';
  static const String edc = 'Edc';
  static const String transfer = 'Transfer';
  static const String ewallet = 'Ewallet';

  /// F-12: piutang pelanggan; hanya tampil bila pelanggan dipilih (BR-12.1).
  static const String tempo = 'Tempo';

  /// F-16d bagian 1: saldo deposit pelanggan; hanya tampil bila pelanggan dipilih dan paket berlaku, saldo dicek online.
  static const String deposit = 'Deposit';

  /// X8 (v2.36): pesanan platform ojol/marketplace yang dibayar platform (dana lewat pencairan). Hanya tampil untuk
  /// penjualan kanal yang sama dengan `MetodePembayaran.Kanal`.
  static const String marketplace = 'Marketplace';

  static const List<String> fase1 = [
    tunai,
    qrisStatis,
    qrisDinamis,
    edc,
    transfer,
    ewallet,
    tempo,
    deposit,
    marketplace,
  ];

  /// F-12 bagian 2: uang muka pre-order yang dipakai saat diambil (metode sistem; tidak tampil sebagai pilihan bayar).
  static const String uangMuka = 'UangMuka';

  /// K-11: nilai barang retur yang membayar barang pengganti (metode sistem; tidak tampil sebagai pilihan bayar).
  static const String tukar = 'Tukar';

  /// Jenis yang boleh membayar uang muka pre-order (tanpa tempo).
  static const List<String> bolehUangMuka = [tunai, qrisStatis, edc, transfer, ewallet];

  /// F-16d bagian 1: jenis yang boleh dipakai mengisi saldo deposit di kasir (tanpa tempo, deposit, uang muka).
  static const List<String> bolehIsiDeposit = [tunai, qrisStatis, edc, transfer, ewallet];
}

/// Tarif pajak terbit bertanggal berlaku (CLAUDE.md #12). Tanggal `YYYY-MM-DD`; `berlakuSampai` inklusif.
class TarifPajakLokal {
  const TarifPajakLokal({
    required this.kode,
    required this.tarif,
    required this.pembilang,
    required this.penyebut,
    required this.berlakuMulai,
    required this.berlakuSampai,
  });

  final String kode;

  /// Persen apa adanya dari server (misal `12.00`); dipakai juga di snapshot outbox.
  final String tarif;
  final int pembilang;
  final int penyebut;
  final String berlakuMulai;
  final String? berlakuSampai;

  bool CekBerlaku(String tanggal) =>
      berlakuMulai.compareTo(tanggal) <= 0 && (berlakuSampai == null || tanggal.compareTo(berlakuSampai!) <= 0);

  static TarifPajakLokal DariBaris(BarisTarifPajak b) => TarifPajakLokal(
    kode: b.KodeJenisPajak,
    tarif: b.Tarif,
    pembilang: b.PengaliDppPembilang,
    penyebut: b.PengaliDppPenyebut <= 0 ? 1 : b.PengaliDppPenyebut,
    berlakuMulai: b.BerlakuMulai,
    berlakuSampai: b.BerlakuSampai,
  );
}

/// Zona waktu outlet (`Outlet.ZonaWaktu`, IANA) untuk tanggal bisnis & `YYMMDD` nomor (PRD v1.46 (d)), bukan zona
/// perangkat. Indonesia tidak memakai DST, jadi cukup tabel offset tetap: WIB +7, WITA +8, WIT +9. Zona tidak dikenal
/// (atau server lama yang belum mengirimnya) → UTC+7 dan dicatat ke log.
abstract final class ZonaWaktuOutlet {
  static const String bawaan = 'Asia/Jakarta';

  static const Map<String, int> _offsetJam = {
    'Asia/Jakarta': 7,
    'Asia/Pontianak': 7,
    'Asia/Makassar': 8,
    'Asia/Ujung_Pandang': 8,
    'Asia/Jayapura': 9,
  };

  static bool CekDikenal(String? zona) => _offsetJam.containsKey(zona);

  static Duration AmbilOffset(String? zona) => Duration(hours: _offsetJam[zona] ?? _offsetJam[bawaan]!);

  /// Jam dinding outlet untuk [waktu], sebagai `DateTime` UTC yang field-nya (tahun…menit) = waktu di outlet.
  static DateTime KeWaktuOutlet(DateTime waktu, String? zona) => waktu.toUtc().add(AmbilOffset(zona));
}

/// Pengaturan yang dipakai saat menjual: identitas outlet & perangkat (nomor BR-07.1), profil pajak, tarif, batas
/// diskon (BR-07.3), pembulatan tunai (BR-08.6), dan metode pembayaran. Dimuat dari data awal tersimpan.
class KonteksPenjualan {
  const KonteksPenjualan({
    required this.uuidOutlet,
    required this.kodeOutlet,
    required this.kodePerangkat,
    required this.jamTutupBuku,
    required this.profilPajak,
    required this.tarif,
    required this.pembulatanTunai,
    required this.batasDiskonManual,
    required this.batasDiskonPenyetuju,
    required this.metodePembayaran,
    this.zonaWaktu = ZonaWaktuOutlet.bawaan,
    this.promo = const [],
    this.namaPromo = const {},
    this.modeResolusiPromo = ModeResolusiPromo.Terbaik,
    this.kategoriProduk = const {},
    this.batasHariLewatJatuhTempo = 0,
    this.kirimDapurLangsung = false,
    this.deposit = const DepositPos(),
    this.laundry = const LaundryPos(),
    this.tokoOnlineAktif = false,
    this.kodeSektor = const [],
    this.jenisPesanan = const [],
    this.barcodeTimbangan = const BarcodeTimbanganPos(),
    this.jenisPesananBawaan,
    this.metodeTukar,
    this.minimumCharge = const MinimumChargePos(),
  });

  final String? uuidOutlet;
  final String? kodeOutlet;
  final String? kodePerangkat;

  /// `HH:mm` waktu outlet; transaksi sebelum jam ini masuk tanggal bisnis hari sebelumnya.
  final String jamTutupBuku;

  /// Zona waktu IANA outlet (`Outlet.ZonaWaktu`), lihat [ZonaWaktuOutlet].
  final String zonaWaktu;
  final ProfilPajakPos profilPajak;
  final List<TarifPajakLokal> tarif;
  final DataPembulatanTunai? pembulatanTunai;
  final Decimal batasDiskonManual;
  final Decimal batasDiskonPenyetuju;

  /// Metode aktif berjenis fase 1, urut tampil.
  final List<BarisMetodePembayaran> metodePembayaran;

  /// F-16c: promo aktif tersimpan (diterapkan otomatis, juga offline), nama per Uuid, mode resolusi, dan kategori per
  /// produk untuk kondisi promo kategori.
  final List<DefinisiPromo> promo;
  final Map<String, String> namaPromo;
  final ModeResolusiPromo modeResolusiPromo;
  final Map<String, String> kategoriProduk;

  /// F-12 BR-12.1: piutang lewat jatuh tempo lebih dari sekian hari = penjualan tempo butuh penyetuju.
  final int batasHariLewatJatuhTempo;

  /// Cetak struk bagian 4c (v1.89): outlet punya stasiun dapur aktif, jadi penjualan langsung (mode cepat, bukan pesanan
  /// meja atau pengambilan pre-order) dikirim ke dapur (`KirimDapur`) dan tiketnya dicetak.
  final bool kirimDapurLangsung;

  /// F-16d bagian 1: deposit pelanggan berlaku (fitur paket) & batas isi per transaksi.
  final DepositPos deposit;

  /// Laundry (§9.9): isian tiket laundry di keranjang, durasi estimasi, parfum, awalan tautan lacak.
  final LaundryPos laundry;

  /// F-17: toko online melayani outlet ini, jadi menu Pesanan toko online ditampilkan.
  final bool tokoOnlineAktif;

  /// D-48: kode sektor outlet (template + jenis usaha tambahan). Kosong = belum diketahui, semua fitur sektor tampil.
  final List<String> kodeSektor;

  /// FnB minimum charge (D-144): minimum belanja per meja dan produk penampung selisihnya.
  final MinimumChargePos minimumCharge;

  /// D-48: fitur khusus sektor tampil bila salah satu sektor outlet berawalan [awalan] (`SVC` mencakup `SVC-WRK`);
  /// sektor outlet kosong (server lama / belum diterapkan) = tampil.
  bool CekSesuaiSektor(List<String> awalan) {
    if (kodeSektor.isEmpty) {
      return true;
    }
    return awalan.any((a) => kodeSektor.any((kode) => kode == a || kode.startsWith('$a-')));
  }

  /// v3.51: jenis pesanan yang dipilih kasir per transaksi (Makan di tempat/Bawa pulang/Antar), dari data awal outlet.
  /// Kosong = tanpa pilihan jenis pesanan (retail).
  final List<KanalPenjualan> jenisPesanan;

  /// v3.55 (§9.3): barcode timbangan tenant (tidak aktif bila server lama).
  final BarcodeTimbanganPos barcodeTimbangan;

  /// Jenis pesanan bawaan transaksi baru; null = Bawa pulang.
  final KanalPenjualan? jenisPesananBawaan;

  /// K-11: metode sistem "Tukar barang" dari data awal; null = server lama (tukar barang tidak ditawarkan).
  final BarisMetodePembayaran? metodeTukar;

  Decimal AmbilPersenBiayaLayanan() =>
      profilPajak.biayaLayananAktif ? Decimal.tryParse(profilPajak.persenBiayaLayanan) ?? Decimal.zero : Decimal.zero;

  /// Tanggal bisnis `YYYY-MM-DD` dari [waktu] menurut jam dinding outlet ([zonaWaktu]), bukan zona perangkat.
  String HitungTanggalBisnis(DateTime waktu) {
    var lokal = ZonaWaktuOutlet.KeWaktuOutlet(waktu, zonaWaktu);
    final jam = '${lokal.hour.toString().padLeft(2, '0')}:${lokal.minute.toString().padLeft(2, '0')}';
    if (RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(jamTutupBuku) && jam.compareTo(jamTutupBuku) < 0) {
      lokal = lokal.subtract(const Duration(days: 1));
    }
    return '${lokal.year.toString().padLeft(4, '0')}-${lokal.month.toString().padLeft(2, '0')}-'
        '${lokal.day.toString().padLeft(2, '0')}';
  }

  TarifPajakLokal? CariTarif(String kode, String tanggal) {
    for (final t in tarif) {
      if (t.kode == kode && t.CekBerlaku(tanggal)) {
        return t;
      }
    }
    return null;
  }

  static Future<KonteksPenjualan> Muat(RepositoriKasir repositori, RepositoriKatalog katalog) async {
    final profil = await repositori.AmbilPengaturan(KunciPengaturan.profilPajak);
    final pembulatan = await repositori.AmbilPengaturan(KunciPengaturan.pembulatanTunai);
    final petaPembulatan = pembulatan == null || pembulatan.isEmpty ? null : jsonDecode(pembulatan);
    final dataPembulatan = PembulatanTunaiPos.DariJson(petaPembulatan);
    final zona = await repositori.AmbilPengaturan(KunciPengaturan.zonaWaktu);
    final teksPromo = await repositori.AmbilPengaturan(KunciPengaturan.promo);
    final dataPromo = teksPromo == null || teksPromo.isEmpty
        ? DataPromoPos.kosong
        : DataPromoPos.DariJson(jsonDecode(teksPromo) as Map<String, Object?>);
    if (!ZonaWaktuOutlet.CekDikenal(zona)) {
      developer.log(
        'Zona waktu outlet "${zona ?? '-'}" tidak dikenal; tanggal bisnis memakai UTC+7 (WIB).',
        name: 'KonteksPenjualan',
      );
    }
    return KonteksPenjualan(
      uuidOutlet: await repositori.AmbilPengaturan(KunciPengaturan.uuidOutlet),
      kodeOutlet: await repositori.AmbilPengaturan(KunciPengaturan.kodeOutlet),
      kodePerangkat: await repositori.AmbilPengaturan(KunciPengaturan.kodePerangkat),
      jamTutupBuku: await repositori.AmbilPengaturan(KunciPengaturan.jamTutupBuku) ?? '00:00',
      profilPajak: ProfilPajakPos.DariJson(profil == null ? null : jsonDecode(profil)),
      tarif: (await katalog.AmbilTarifPajak()).map(TarifPajakLokal.DariBaris).toList(),
      pembulatanTunai: dataPembulatan == null
          ? null
          : DataPembulatanTunai(
              kelipatan: dataPembulatan.kelipatan,
              arah:
                  ArahPembulatan.values.where((a) => a.name == dataPembulatan.arah).firstOrNull ??
                  ArahPembulatan.Terdekat,
            ),
      batasDiskonManual:
          Decimal.tryParse(await repositori.AmbilPengaturan(KunciPengaturan.batasDiskonManual) ?? '') ??
          Decimal.parse(DataAwal.batasDiskonManualBawaan),
      batasDiskonPenyetuju:
          Decimal.tryParse(await repositori.AmbilPengaturan(KunciPengaturan.batasDiskonPenyetuju) ?? '') ??
          Decimal.parse(DataAwal.batasDiskonPenyetujuBawaan),
      metodePembayaran: (await katalog.AmbilMetodePembayaran())
          .where((m) => JenisMetodeBayar.fase1.contains(m.Jenis))
          .toList(),
      zonaWaktu: ZonaWaktuOutlet.CekDikenal(zona) ? zona! : ZonaWaktuOutlet.bawaan,
      promo: [for (final p in dataPromo.promo) ?UraiPromo(p)],
      namaPromo: {for (final p in dataPromo.promo) p.uuid: p.nama},
      modeResolusiPromo:
          ModeResolusiPromo.values.where((m) => m.name == dataPromo.modeResolusi).firstOrNull ??
          ModeResolusiPromo.Terbaik,
      kategoriProduk: await katalog.AmbilKategoriProduk(),
      batasHariLewatJatuhTempo:
          int.tryParse(await repositori.AmbilPengaturan(KunciPengaturan.batasHariLewatJatuhTempo) ?? '') ?? 0,
      kirimDapurLangsung: (await RuteDapur.Muat(repositori)).stasiun.isNotEmpty,
      deposit: await MuatDeposit(repositori),
      laundry: await MuatLaundry(repositori),
      tokoOnlineAktif: await repositori.AmbilPengaturan(KunciPengaturan.tokoOnlineAktif) == '1',
      kodeSektor: await MuatKodeSektor(repositori),
      minimumCharge: MinimumChargePos.DariJson(
        jsonDecode(await repositori.AmbilPengaturan(KunciPengaturan.minimumChargeMeja) ?? 'null'),
      ),
      jenisPesanan: [
        for (final nama in (jsonDecode(
          await repositori.AmbilPengaturan(KunciPengaturan.jenisPesanan) ?? '[]',
        ) as List).whereType<String>())
          ?AmbilKanal(nama),
      ],
      jenisPesananBawaan: AmbilKanal(await repositori.AmbilPengaturan(KunciPengaturan.jenisPesananBawaan)),
      metodeTukar: (await katalog.AmbilMetodePembayaran()).where((m) => m.Jenis == JenisMetodeBayar.tukar).firstOrNull,
      barcodeTimbangan: BarcodeTimbanganPos.DariJson(
        jsonDecode(await repositori.AmbilPengaturan(KunciPengaturan.barcodeTimbangan) ?? 'null'),
      ),
    );
  }

  static KanalPenjualan? AmbilKanal(String? nama) => KanalPenjualan.values.where((k) => k.name == nama).firstOrNull;

  /// Kode sektor outlet tersimpan dari data awal (tidak ada = sektor belum diketahui).
  static Future<List<String>> MuatKodeSektor(RepositoriKasir repositori) async {
    final mentah = jsonDecode(await repositori.AmbilPengaturan(KunciPengaturan.kodeSektor) ?? '[]');
    return mentah is List ? mentah.whereType<String>().toList() : const [];
  }

  /// Pengaturan deposit tersimpan dari data awal (tidak ada = tidak berlaku).
  static Future<DepositPos> MuatDeposit(RepositoriKasir repositori) async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.deposit);
    return teks == null || teks.isEmpty ? const DepositPos() : DepositPos.DariJson(jsonDecode(teks));
  }

  /// Pengaturan laundry tersimpan dari data awal (tidak ada = tidak aktif).
  static Future<LaundryPos> MuatLaundry(RepositoriKasir repositori) async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.laundry);
    return teks == null || teks.isEmpty ? const LaundryPos() : LaundryPos.DariJson(jsonDecode(teks));
  }

  /// Definisi promo dari server; definisi yang tidak bisa dibaca aplikasi versi ini dilewati (null).
  static DefinisiPromo? UraiPromo(PromoPos p) {
    try {
      return DefinisiPromo.Urai(
        uuid: p.uuid,
        kode: p.kode,
        definisi: p.definisi,
        prioritas: p.prioritas,
        eksklusif: p.eksklusif,
        mulaiPada: p.mulaiPada,
        selesaiPada: p.selesaiPada,
        kuotaTersisa: p.kuotaTersisa,
      );
    } on Object catch (galat) {
      developer.log('Promo ${p.kode} dilewati: $galat', name: 'KonteksPenjualan');
      return null;
    }
  }
}
