import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Pelanggan/LayananSesi.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananReturPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

const String paketCreambath = '01K5PRD000000000PAKETSES101';
const String creambath = '01K5PRD000000000CREAMBATH01';
const String potongRambut = '01K5PRD00000000P0T0NGRMBT1';
const String psPaket = '01K5PS0000000000PAKETSES101';
const String psCreambath = '01K5PS000000000CREAMBATH01';

/// Katalog uji + salon: paket "Creambath 10x" (Jasa, paket sesi 10 sesi) dan dua layanan Jasa.
Map<String, Object?> KatalogSalonUji() {
  final katalog = KatalogUji();
  katalog['Produk'] = [
    ...(katalog['Produk']! as List<Object?>),
    {
      ...ProdukUji(paketCreambath, 'Paket Creambath Rambut Panjang 10x Sesi', jenis: 'Jasa'),
      'PaketSesi': {'JumlahSesi': 10, 'MasaBerlakuHari': 90, 'Aktif': true},
    },
    ProdukUji(creambath, 'Creambath Rambut Panjang Aroma Ginseng', jenis: 'Jasa'),
    ProdukUji(potongRambut, 'Potong Rambut Wanita Model Layer', jenis: 'Jasa'),
  ];
  katalog['ProdukSatuan'] = [
    ...(katalog['ProdukSatuan']! as List<Object?>),
    SatuanProdukUji(psPaket, paketCreambath, UuidUji.satuanPcs),
    SatuanProdukUji(psCreambath, creambath, UuidUji.satuanPcs),
  ];
  katalog['ProdukHarga'] = [
    ...(katalog['ProdukHarga']! as List<Object?>),
    HargaUji('01K5HRG0000000PAKETSES1001', paketCreambath, psPaket, '1000000.00'),
    HargaUji('01K5HRG000000CREAMBATH0001', creambath, psCreambath, '120000.00'),
  ];
  return katalog;
}

PaketSesiPelangganPos PaketUji({int sisa = 7, bool semua = false}) => PaketSesiPelangganPos(
  uuid: '01K5SALDOSESI0000000000001',
  namaPaket: 'Paket Creambath Rambut Panjang 10x Sesi',
  jumlahSesi: 10,
  sisaSesi: sisa,
  berlakuSampai: '2026-12-31',
  nomorPenjualan: 'INV/SLB/260926/POS-001-0008',
  semuaProdukJasa: semua,
  produkBerlaku: const [(uuid: creambath, nama: 'Creambath Rambut Panjang Aroma Ginseng')],
);

/// Rincian F-16d bagian 2 di perangkat: produk paket sesi dari katalog, jual paket wajib pelanggan & jumlah bulat,
/// baris paket tidak diretur, saldo sesi dibaca online, dan pemakaian sesi lewat outbox `Sesi.Pakai`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  const ani = PelangganTerpilih(uuid: '01K5PELANGGAN0000000000001', nama: 'Ani Rahmawati', noHpSamar: '0812****7890');

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog(KatalogSalonUji());
  });
  tearDown(() => u.Tutup());

  test('katalog menandai paket sesi. Jual paket wajib pelanggan & per paket utuh. Baris paket tidak diretur', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final paket = katalog.CariProduk(paketCreambath)!;
    expect(paket.paketSesi, isTrue);
    expect(paket.jumlahSesiPaket, 10);
    expect(katalog.CariProduk(creambath)!.paketSesi, isFalse);

    final baris = u.penjualan.BuatBaris(katalog, k, paket);
    expect(baris.bolehDesimal, isFalse);
    final tanpaPelanggan = u.penjualan.TambahBaris(Keranjang.kosong, baris, katalog, k);
    expect(
      () => LayananPenjualan.ValidasiPaketSesi(tanpaPelanggan, katalog),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PaketSesiTanpaPelanggan')),
    );
    expect(
      () => u.penjualan.UbahJumlah(tanpaPelanggan, baris.uuid, Kuantitas.Dari('1.5'), katalog, k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'JumlahTidakValid')),
    );
    LayananPenjualan.ValidasiPaketSesi(tanpaPelanggan.Salin(pelanggan: () => ani), katalog);

    final barisAsal = BarisPenjualanCariPos.DariJson({
      'Uuid': '01K5DETAIL0000000000000001',
      'UuidProduk': paketCreambath,
      'NamaProduk': 'Paket Creambath Rambut Panjang 10x Sesi',
      'Jumlah': '1.0000',
      'TotalBaris': '1000000.00',
    });
    expect(LayananReturPenjualan.CekPaketSesi(barisAsal, katalog), isTrue);
  });

  test(
    'saldo sesi dibaca online (offline = PerluOnline). Pakai sesi menulis outbox Sesi.Pakai. Aturan jumlah & layanan',
    () async {
      final katalog = await u.MuatKatalog();
      u.server.penangan = (p) async {
        expect(p.url.path, endsWith('/api/pos/v1/pelanggan/${ani.uuid}/sesi'));
        return JsonUji({
          'Pelanggan': {'Uuid': ani.uuid},
          'Berlaku': true,
          'Paket': [
            {
              'Uuid': '01K5SALDOSESI0000000000001',
              'NamaPaket': 'Paket Creambath Rambut Panjang 10x Sesi',
              'JumlahSesi': 10,
              'SisaSesi': 7,
              'BerlakuSampai': '2026-12-31',
              'NomorPenjualan': 'INV/SLB/260926/POS-001-0008',
              'SemuaProdukJasa': false,
              'ProdukBerlaku': [
                {'Uuid': creambath, 'Nama': 'Creambath Rambut Panjang Aroma Ginseng'},
              ],
            },
          ],
        });
      };
      final saldo = await u.sesi.AmbilSaldo(ani.uuid);
      expect(saldo.paket.single.sisaSesi, 7);

      u.server.penangan = (p) async => throw http.ClientException('offline');
      await expectLater(
        u.sesi.AmbilSaldo(ani.uuid),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );

      final paket = saldo.paket.single;
      Future<String> Pakai(String produk, int jumlah) =>
          u.sesi.Pakai(paket: paket, uuidProduk: produk, jumlah: jumlah, kasir: rina, katalog: katalog);

      await expectLater(Pakai(creambath, 8), throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'SesiKurang')));
      await expectLater(
        Pakai(creambath, 0),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'JumlahTidakValid')),
      );
      await expectLater(
        Pakai(potongRambut, 1),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ProdukDiluarPaket')),
      );

      final uuid = await Pakai(creambath, 2);
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final item = outbox.last;
      expect(item.Jenis, LayananSesi.jenisOutbox);
      expect(item.Uuid, uuid);
      final data = jsonDecode(item.Data) as Map<String, Object?>;
      expect(data, containsPair('UuidSaldoSesi', paket.uuid));
      expect(data, containsPair('UuidProduk', creambath));
      expect(data, containsPair('Jumlah', 2));
      expect(data, containsPair('UuidPengguna', rina.uuid));
    },
  );

  test('paket "semua layanan": layanan = produk Jasa tampil selain paket sesi', () async {
    final katalog = await u.MuatKatalog();
    final layanan = LayananSesi.AmbilLayanan(PaketUji(semua: true), katalog).map((l) => l.uuid).toSet();
    expect(layanan, {creambath, potongRambut});
    expect(LayananSesi.AmbilLayanan(PaketUji(), katalog).map((l) => l.uuid), [creambath]);
  });
}
