import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' show driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter/widgets.dart' show Text;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/PenyimpanRahasia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Data/RepositoriKatalog.dart';
import 'package:kasir/Data/RepositoriAbsensi.dart';
import 'package:kasir/Data/RepositoriPelanggan.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart';
import 'package:kasir/Data/RepositoriDeposit.dart';
import 'package:kasir/Data/RepositoriPreOrder.dart';
import 'package:kasir/Data/RepositoriPersediaan.dart';
import 'package:kasir/Data/RepositoriPesananMeja.dart';
import 'package:kasir/Data/RepositoriSalesman.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Katalog/LayananKatalog.dart';
import 'package:kasir/Domain/Meja/LayananPesananMeja.dart';
import 'package:kasir/Domain/Pelanggan/LayananDeposit.dart';
import 'package:kasir/Domain/Pelanggan/LayananSesi.dart';
import 'package:kasir/Domain/Pelanggan/LayananPelanggan.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananLaundry.dart';
import 'package:kasir/Domain/Penjualan/LayananPesananOnline.dart';
import 'package:kasir/Domain/Penjualan/LayananPreOrder.dart';
import 'package:kasir/Domain/Penjualan/LayananPerintahKerja.dart';
import 'package:kasir/Domain/Penjualan/LayananReservasi.dart';
import 'package:kasir/Domain/Penjualan/LayananReservasiMeja.dart';
import 'package:kasir/Domain/Persediaan/LayananBahanTerbuang.dart';
import 'package:kasir/Domain/Persediaan/LayananGudang.dart';
import 'package:kasir/Domain/Perangkat/PenentuLokasi.dart';
import 'package:kasir/Domain/Salesman/LayananSalesman.dart';
import 'package:kasir/Domain/Sesi/LayananMasuk.dart';
import 'package:kasir/Domain/Sesi/LayananPerangkat.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Shift/LayananShift.dart';
import 'package:kasir/Domain/Shift/LayananTutupShift.dart';
import 'package:kasir/Domain/Sinkron/LayananSinkron.dart';
import 'package:kasir/Domain/Struk/LayananStruk.dart';
import 'package:klien_api/KlienApi.dart';

import 'KatalogUji.dart';
import 'PrinterTiruan.dart';

/// Item navigasi Pengaturan di lebar mana pun: "Pengaturan" di rel (≥ 600dp), "Atur" di bilah bawah HP.
Finder NavPengaturan() => find.byWidgetPredicate((w) => w is Text && (w.data == 'Pengaturan' || w.data == 'Atur'));

/// Vektor PIN bersama PHP & Dart: staf uji memakai PIN, garam, dan verifier terbungkus dari sini sehingga verifikasi
/// offline berjalan tanpa server.
final Map<String, Object?> vektorPin =
    jsonDecode(File('../../Spesifikasi/VektorUjiPin/VerifierPin.json').readAsStringSync()) as Map<String, Object?>;

Map<String, Object?> KasusPin(int indeks) => (vektorPin['Kasus']! as List<Object?>)[indeks]! as Map<String, Object?>;

Map<String, Object?> StafJson(String uuid, String nama, List<String> izin, int? indeksPin, {bool pemilik = false}) {
  final kasus = indeksPin == null ? null : KasusPin(indeksPin);
  return {
    'Uuid': uuid,
    'Nama': nama,
    'Pemilik': pemilik,
    'Izin': izin,
    'PinDiatur': true,
    'Pin': kasus == null ? null : {'Garam': kasus['Garam'], 'Nonce': kasus['Nonce'], 'Sandi': kasus['Sandi']},
  };
}

/// Data awal uji: Rina (kasir, boleh diskon manual, PIN kasus 0 "246810"), Budi (supervisor, penyetuju kas keluar &
/// diskon & selisih kas tutup shift & void/retur & tempo & bahan terbuang & gudang, PIN kasus 1 "135790"), Sari (kasir tanpa verifier offline), kategori keluar & masuk, batas kas keluar
/// Rp 200.000. F-07b: outlet SLB, perangkat POS-001, memungut PBJT 10% (bukan PKP), batas diskon 10%/30%, lima
/// metode pembayaran fase 1.
Map<String, Object?> DataAwalUji({
  bool shiftBersama = false,
  Map<String, Object?>? pembulatanTunai,
  Map<String, Object?>? profilPajak,
  List<Map<String, Object?>>? tarifPajak,
  bool tutupShiftButa = true,
  String toleransiSelisihKas = '10000.00',
  String? zonaWaktu,
  String? jamTutupBuku,
  Map<String, Object?>? nomorUrutPenjualan,
  Map<String, Object?>? nomorUrutRetur,
  bool tempo = false,
  bool qrisDinamis = false,
  int? batasHariLewatJatuhTempo,
  bool? bukaLaciPerluPin,
  List<Map<String, Object?>>? karyawan,
  bool deposit = false,
  Map<String, Object?>? nomorUrutIsiDeposit,
  bool laundry = false,
  bool tokoOnline = false,
  bool persetujuanJarakJauh = false,
  bool ojol = false,
  List<String>? jenisPesanan,
  String? jenisPesananBawaan,
  Map<String, Object?>? barcodeTimbangan,
  String? modeKasir,
  bool tukar = false,
  bool giftCard = false,
}) => {
  'Karyawan': ?karyawan,
  // F-16d: deposit pelanggan (fitur paket) hanya bila diminta test.
  if (deposit) 'Deposit': {'Berlaku': true, 'MinimalIsi': '1000.00', 'MaksimalIsi': '10000000.00'},
  // F-17: menu Pesanan toko online hanya muncul bila outlet melayani toko online.
  if (tokoOnline) 'TokoOnline': {'Aktif': true},
  // Laundry (§9.9): isian tiket laundry di kasir hanya bila diminta test.
  if (laundry)
    'Laundry': {
      'Aktif': true,
      'JamReguler': 48,
      'JamExpress': 24,
      'Parfum': ['Lavender', 'Sakura'],
      'AwalanLacak': 'https://payoung.test/s/1a.',
    },
  'Pengaturan': {
    'BatasKasKeluar': '200000.00',
    'ShiftBersama': shiftBersama,
    'BatasDiskonManual': '10.00',
    'BatasDiskonPenyetuju': '30.00',
    'PembulatanTunai': pembulatanTunai,
    'TutupShiftButa': tutupShiftButa,
    'ToleransiSelisihKas': toleransiSelisihKas,
    'BatasHariLewatJatuhTempo': ?batasHariLewatJatuhTempo,
    'BukaLaciPerluPin': ?bukaLaciPerluPin,
    // X4: tombol minta persetujuan jarak jauh di dialog PIN penyetuju.
    'PersetujuanJarakJauh': persetujuanJarakJauh,
    // v3.55: barcode timbangan hanya bila diminta test.
    'BarcodeTimbangan': ?barcodeTimbangan,
  },
  'Outlet': {
    'Uuid': '01K50VT1ET0000000000000001',
    'Kode': 'SLB',
    'Nama': 'Kopi Senja Solo Baru',
    'Alamat': 'Jl. Ir. Soekarno No. 12, Solo Baru, Sukoharjo',
    'Telepon': '0271-555123',
    'JamTutupBuku': ?jamTutupBuku,
    'ZonaWaktu': ?zonaWaktu,
    // v3.51: jenis pesanan outlet (FnB) hanya bila diminta test.
    'JenisPesanan': ?jenisPesanan,
    'JenisPesananBawaan': ?jenisPesananBawaan,
    // K-8: mode kasir template sektor hanya bila diminta test.
    if (modeKasir != null) 'ModeKasir': [modeKasir],
    'ModeKasirBawaan': ?modeKasir,
  },
  'Perangkat': {
    'Uuid': '01K5PERANGKAT0000000000001',
    'Kode': 'POS-001',
    'NomorUrutPenjualan': ?nomorUrutPenjualan,
    'NomorUrutRetur': ?nomorUrutRetur,
    'NomorUrutIsiDeposit': ?nomorUrutIsiDeposit,
  },
  'ProfilPajak':
      profilPajak ??
      {
        'Pkp': false,
        'PungutPbjt': true,
        'HargaTermasukPajak': false,
        'BiayaLayanan': {'Aktif': false, 'Persen': '0.00'},
      },
  'TarifPajak':
      tarifPajak ??
      [
        {
          'KodeJenisPajak': 'PbjtMakananMinuman',
          'Tarif': '10.00',
          'PengaliDppPembilang': 1,
          'PengaliDppPenyebut': 1,
          'BerlakuMulai': '2024-01-01',
          'BerlakuSampai': null,
        },
        {
          'KodeJenisPajak': 'Ppn',
          'Tarif': '12.00',
          'PengaliDppPembilang': 11,
          'PengaliDppPenyebut': 12,
          'BerlakuMulai': '2025-01-01',
          'BerlakuSampai': null,
        },
      ],
  'MetodePembayaran': [
    {'Uuid': '01K5MTD0000000000000000001', 'Jenis': 'Tunai', 'Nama': 'Tunai', 'AdaGambarQris': false, 'Urutan': 1},
    {'Uuid': '01K5MTD0000000000000000002', 'Jenis': 'QrisStatis', 'Nama': 'QRIS', 'AdaGambarQris': true, 'Urutan': 2},
    {'Uuid': '01K5MTD0000000000000000003', 'Jenis': 'Edc', 'Nama': 'EDC BCA', 'AdaGambarQris': false, 'Urutan': 3},
    {
      'Uuid': '01K5MTD0000000000000000004',
      'Jenis': 'Transfer',
      'Nama': 'Transfer BCA',
      'NomorRekening': '0151234567',
      'NamaPemilikRekening': 'CV Kopi Senja',
      'AdaGambarQris': false,
      'Urutan': 4,
    },
    {'Uuid': '01K5MTD0000000000000000005', 'Jenis': 'Ewallet', 'Nama': 'GoPay', 'AdaGambarQris': false, 'Urutan': 5},
    {'Uuid': '01K5MTD0000000000000000006', 'Jenis': 'Piutang', 'Nama': 'Kasbon', 'AdaGambarQris': false, 'Urutan': 6},
    // F-12: metode Tempo (piutang) hanya bila diminta test.
    if (tempo)
      {'Uuid': '01K5MTD0000000000000000007', 'Jenis': 'Tempo', 'Nama': 'Tempo', 'AdaGambarQris': false, 'Urutan': 7},
    // K-11: metode sistem Tukar barang hanya bila diminta test.
    if (tukar)
      {
        'Uuid': '01K5MTD000000000000T0KAR01',
        'Jenis': 'Tukar',
        'Nama': 'Tukar barang',
        'AdaGambarQris': false,
        'Urutan': 99,
      },
    // F-16d: metode Deposit pelanggan hanya bila diminta test.
    if (deposit)
      {
        'Uuid': '01K5MTD0000000000000000009',
        'Jenis': 'Deposit',
        'Nama': 'Deposit pelanggan',
        'AdaGambarQris': false,
        'Urutan': 9,
      },
    // CRM-06: metode Gift card hanya bila diminta test.
    if (giftCard)
      {
        'Uuid': '01K5MTD0000000000000GIFT01',
        'Jenis': 'GiftCard',
        'Nama': 'Gift card',
        'AdaGambarQris': false,
        'Urutan': 12,
      },
    // X8: metode platform ojol (GoFood & GrabFood) hanya bila diminta test.
    if (ojol) ...[
      {
        'Uuid': '01K5MTD000000000000G0F00D1',
        'Jenis': 'Marketplace',
        'Nama': 'GoFood',
        'AdaGambarQris': false,
        'Urutan': 10,
        'Kanal': 'GoFood',
      },
      {
        'Uuid': '01K5MTD00000000000GRABF00D',
        'Jenis': 'Marketplace',
        'Nama': 'GrabFood',
        'AdaGambarQris': false,
        'Urutan': 11,
        'Kanal': 'GrabFood',
      },
    ],
    // v2.05: QRIS dinamis lewat gerbang pembayaran hanya bila diminta test.
    if (qrisDinamis)
      {
        'Uuid': '01K5MTD0000000000000000008',
        'Jenis': 'QrisDinamis',
        'Nama': 'QRIS Dinamis',
        'AdaGambarQris': false,
        'Urutan': 8,
      },
  ],
  'KategoriKas': [
    {'Uuid': '01K5KATEGORI00000000000001', 'Nama': 'Beli es batu & galon', 'Jenis': 'Keluar'},
    {'Uuid': '01K5KATEGORI00000000000002', 'Nama': 'Tambahan uang receh', 'Jenis': 'Masuk'},
  ],
  'Staf': [
    StafJson('01K5STAF000000000000000001', 'Rina Wulandari', ['penjualan.buat', 'penjualan.diskon.manual'], 0),
    StafJson('01K5STAF000000000000000002', 'Budi Santoso', [
      'penjualan.buat',
      'kas.keluar.setujui',
      'penjualan.diskon.manual',
      'penjualan.diskon.setujui',
      'shift.selisih.setujui',
      'penjualan.void',
      'penjualan.retur',
      'penjualan.tempo.setujui',
      'persediaan.terbuang.catat',
      'persediaan.kelola',
    ], 1),
    StafJson('01K5STAF000000000000000003', 'Sari Lestari', ['penjualan.buat'], null),
  ],
  'PinOffline': {'Tersedia': true, 'Parameter': vektorPin['Parameter'], 'BatasSalah': 5, 'MenitKunci': 5},
  'WaktuServer': '2026-09-24T01:00:00Z',
};

/// Apotek bagian 2: data awal uji + apoteker "apt. Dewi Anggraini" (izin `apotek.obat-keras.jual`, PIN kasus 2
/// "000000"). Rina (kasir) tidak berizin apoteker.
Map<String, Object?> DataAwalApotekUji({bool tanpaApoteker = false}) {
  final data = DataAwalUji();
  data['Staf'] = [
    ...(data['Staf']! as List<Object?>),
    if (!tanpaApoteker)
      StafJson('01K6STAF0000000000APOTEK01', 'apt. Dewi Anggraini', ['penjualan.buat', 'apotek.obat-keras.jual'], 2),
  ];
  return data;
}

/// Respons `GET /api/pos/v1/meja` uji.
Map<String, Object?> DataMejaUji() => {
  'ModeMejaAktif': true,
  'Area': [
    {'Uuid': '01K5AREA000000000000DALAM1', 'Nama': 'Dalam', 'Urutan': 1},
    {'Uuid': '01K5AREA000000000000TERAS1', 'Nama': 'Teras', 'Urutan': 2},
  ],
  'Meja': [
    {'Uuid': '01K5MEJA0000000000000D0101', 'Nama': 'D-01', 'UuidArea': '01K5AREA000000000000DALAM1', 'Kapasitas': 4},
    {'Uuid': '01K5MEJA0000000000000D0201', 'Nama': 'D-02', 'UuidArea': '01K5AREA000000000000DALAM1', 'Kapasitas': 2},
    {'Uuid': '01K5MEJA0000000000000T0101', 'Nama': 'T-01', 'UuidArea': '01K5AREA000000000000TERAS1', 'Kapasitas': 6},
  ],
  'StasiunDapur': [
    {'Uuid': '01K5STAS1VN000000000BAR001', 'Nama': 'Bar'},
    {'Uuid': '01K5STAS1VN000000000DAPUR1', 'Nama': 'Dapur'},
  ],
  'UuidStasiunBawaan': '01K5STAS1VN000000000DAPUR1',
  // Cetak struk bagian 4c: kategori Kopi → Bar; Makanan tanpa stasiun → bawaan (Dapur).
  'KategoriStasiun': [
    {'UuidKategori': '01K5KAT0000000000000K0P101', 'UuidStasiun': '01K5STAS1VN000000000BAR001'},
  ],
};

/// Server tiruan: penangan bisa diganti per test; semua permintaan dicatat.
class ServerTiruan {
  final List<http.Request> permintaan = [];
  Future<http.Response> Function(http.Request permintaan) penangan = (_) async => http.Response('{}', 200);

  http.Client BuatKlien() => MockClient((p) async {
    permintaan.add(p);
    return penangan(p);
  });
}

/// Respons berkas biner (gambar) dari server tiruan.
http.Response BytesUji(List<int> isi) => http.Response.bytes(isi, 200, headers: {'content-type': 'image/png'});

http.Response JsonUji(Object isi, [int status = 200]) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

class LingkunganUji {
  LingkunganUji._(this.db, this.server, this.rahasia, this.jam);

  final BasisDataKasir db;
  final ServerTiruan server;
  final PenyimpanRahasiaMemori rahasia;
  DateTime jam;

  late final RepositoriKasir repositori = RepositoriKasir(db);

  /// PRD v1.79: printer struk tiruan (dipasang juga di aplikasi utuh lewat `PasangAplikasi`).
  final PrinterTiruan printer = PrinterTiruan();
  late final PemindaiTiruan pemindai = PemindaiTiruan(printer);

  /// v2.01: layar pelanggan tiruan (dipakai untuk semua mode selain Mati).
  final LayarPelangganTiruan layarPelanggan = LayarPelangganTiruan();
  late final LayananStruk struk = LayananStruk(
    repositori: repositori,
    penjualan: repositoriPenjualan,
    pembuatTransport: (_) => printer,
  );
  late final RepositoriKatalog repositoriKatalog = RepositoriKatalog(db);
  late final RepositoriPenjualan repositoriPenjualan = RepositoriPenjualan(db, repositori);
  late final KlienPos klien = KlienPos(
    alamatDasar: Uri.parse('https://kasir.contoh.id/'),
    versiAplikasi: '1.0.0',
    ambilToken: () => rahasia.isi[PenyimpanRahasia.kunciToken],
    klien: server.BuatKlien(),
  );
  late final LayananPerangkat perangkat = LayananPerangkat(
    klien: klien,
    repositori: repositori,
    rahasia: rahasia,
    platform: 'Android',
    jam: () => jam,
  );
  late final LayananMasuk masuk = LayananMasuk(repositori: repositori, rahasia: rahasia, klien: klien, jam: () => jam);
  late final LayananShift shift = LayananShift(repositori: repositori, jam: () => jam);
  late final LayananTutupShift tutupShift = LayananTutupShift(
    repositori: repositori,
    repositoriPenjualan: repositoriPenjualan,
    repositoriPreOrder: repositoriPreOrder,
    repositoriDeposit: repositoriDeposit,
    jam: () => jam,
  );
  late final RepositoriDeposit repositoriDeposit = RepositoriDeposit(db, repositori);
  late final LayananSesi sesi = LayananSesi(klien: klien, repositoriKasir: repositori, jam: () => jam);
  late final LayananDeposit deposit = LayananDeposit(
    klien: klien,
    repositori: repositoriDeposit,
    repositoriKasir: repositori,
    jam: () => jam,
  );
  late final RepositoriPreOrder repositoriPreOrder = RepositoriPreOrder(db, repositori);
  late final LayananPreOrder preOrder = LayananPreOrder(
    klien: klien,
    repositori: repositori,
    repositoriPreOrder: repositoriPreOrder,
    penjualan: penjualan,
    jam: () => jam,
  );
  late final LayananPesananOnline pesananOnline = LayananPesananOnline(klien: klien, penjualan: penjualan);
  late final LayananReservasi reservasi = LayananReservasi(klien: klien, penjualan: penjualan);
  late final LayananReservasiMeja reservasiMeja = LayananReservasiMeja(klien: klien);
  late final LayananPerintahKerja perintahKerja = LayananPerintahKerja(klien: klien, penjualan: penjualan);
  late final LayananLaundry laundry = LayananLaundry(klien: klien, jam: () => jam);
  late final LayananSinkron sinkron = LayananSinkron(
    klien: klien,
    repositori: repositori,
    perangkat: perangkat,
    jam: () => jam,
  );

  late final LayananKatalog katalog = LayananKatalog(
    klien: klien,
    repositori: repositori,
    repositoriKatalog: repositoriKatalog,
    jam: () => jam,
  );
  late final LayananPenjualan penjualan = LayananPenjualan(
    repositori: repositori,
    repositoriPenjualan: repositoriPenjualan,
    repositoriPelanggan: repositoriPelanggan,
    jam: () => jam,
  );

  late final RepositoriPesananMeja repositoriMeja = RepositoriPesananMeja(db, repositori);
  late final LayananPesananMeja pesananMeja = LayananPesananMeja(
    klien: klien,
    repositori: repositori,
    repositoriMeja: repositoriMeja,
    jam: () => jam,
  );

  late final RepositoriPersediaan repositoriPersediaan = RepositoriPersediaan(db, repositori);
  late final LayananBahanTerbuang bahanTerbuang = LayananBahanTerbuang(
    repositori: repositoriPersediaan,
    jam: () => jam,
  );

  late final LayananGudang gudang = LayananGudang(klien: klien, repositori: repositori);

  /// Modul Salesman bagian 2: lokasi tiruan (bawaan tidak tersedia; test boleh menggantinya sebelum memakai [salesman]).
  PenentuLokasi lokasi = const PenentuLokasiTidakAda();
  late final RepositoriSalesman repositoriSalesman = RepositoriSalesman(db, repositori);
  late final LayananSalesman salesman = LayananSalesman(
    klien: klien,
    repositori: repositoriSalesman,
    penentuLokasi: _LokasiDelegasi(this),
    jam: () => jam,
  );

  late final RepositoriPelanggan repositoriPelanggan = RepositoriPelanggan(db, repositori);
  late final RepositoriAbsensi repositoriAbsensi = RepositoriAbsensi(db, repositori);
  late final LayananPelanggan pelanggan = LayananPelanggan(
    klien: klien,
    repositori: repositoriPelanggan,
    jam: () => jam,
  );

  /// Area "Dalam" & "Teras", meja D-01 (4 kursi), D-02, T-01; mode meja aktif (bentuk `GET /api/pos/v1/meja`).
  Future<void> SiapkanMeja() => repositoriMeja.SimpanDataMeja(DataMejaPos.DariJson(DataMejaUji()));

  static LingkunganUji Buat() {
    driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
    return LingkunganUji._(
      BasisDataKasir(NativeDatabase.memory()),
      ServerTiruan(),
      PenyimpanRahasiaMemori(),
      DateTime.utc(2026, 9, 24, 1),
    );
  }

  /// Perangkat sudah aktif + data awal uji tersimpan (tanpa lewat server).
  Future<void> SiapkanAktif({bool shiftBersama = false, Map<String, Object?>? dataAwal}) async {
    rahasia.isi[PenyimpanRahasia.kunciToken] = '12|rahasia';
    rahasia.isi[PenyimpanRahasia.kunciPin] = vektorPin['KunciPerangkat']! as String;
    await repositori.SimpanDataAwal(DataAwal.DariJson(dataAwal ?? DataAwalUji(shiftBersama: shiftBersama)), jam);
  }

  /// Katalog uji "Kopi Senja" tersimpan lokal (tanpa lewat server).
  Future<void> SiapkanKatalog([Map<String, Object?>? katalog]) =>
      repositoriKatalog.GantiKatalog(KatalogPos.DariJson(katalog ?? KatalogUji()));

  Future<KatalogLokal> MuatKatalog() async => KatalogLokal.Bangun(await repositoriKatalog.Muat());

  Future<KonteksPenjualan> MuatKonteks() => KonteksPenjualan.Muat(repositori, repositoriKatalog);

  Future<StafLokal> Staf(String nama) async =>
      StafLokal.DariBaris((await repositori.AmbilStaf()).firstWhere((s) => s.Nama == nama));

  Future<void> Tutup() => db.close();
}

/// Meneruskan ke [LingkunganUji.lokasi] yang berlaku saat dipanggil (test boleh menggantinya setelah layanan dibuat).
class _LokasiDelegasi implements PenentuLokasi {
  const _LokasiDelegasi(this.u);

  final LingkunganUji u;

  @override
  Future<LokasiPerangkat?> Ambil() => u.lokasi.Ambil();
}
