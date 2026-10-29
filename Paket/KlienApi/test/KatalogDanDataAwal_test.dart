import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:test/test.dart';

KlienPos BuatKlien(Future<http.Response> Function(http.Request permintaan) penangan) => KlienPos(
  alamatDasar: Uri.parse('https://kasir.contoh.id/'),
  versiAplikasi: '0.1.0',
  ambilToken: () => 'Tkn',
  klien: MockClient(penangan),
);

http.Response Json(Object isi, [int status = 200]) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

Map<String, Object?> DataAwalF06() => {
  'Pengaturan': {'BatasKasKeluar': '200000.00', 'ShiftBersama': false},
  'KategoriKas': <Object?>[],
  'Staf': <Object?>[],
  'PinOffline': {'Tersedia': false, 'Parameter': <String, Object?>{}, 'BatasSalah': 5, 'MenitKunci': 5},
  'WaktuServer': '2026-09-24T01:00:00Z',
};

void main() {
  group('AmbilKatalog (F-03 D.3)', () {
    test('tanpa kursor meminta katalog lengkap. Bagian & tipe dipetakan, uang tetap string', () async {
      late http.Request dikirim;
      final klien = BuatKlien((p) async {
        dikirim = p;
        return Json({
          'Skema': 1,
          'Lengkap': true,
          'Kursor': 'a3Vyc29y',
          'WaktuServer': '2026-09-24T01:00:00Z',
          'Kategori': [
            {'Uuid': 'K1', 'UuidInduk': null, 'Nama': 'Kopi', 'Urutan': 1},
          ],
          'Satuan': [
            {'Uuid': 'S1', 'Nama': 'Cangkir', 'Simbol': 'cup', 'BolehDesimal': false},
          ],
          'KelompokPajak': [
            {
              'Uuid': 'KP1',
              'Nama': 'Makanan & minuman',
              'Kategori': 'KenaPbjt',
              'Pajak': [
                {'KodeJenisPajak': 'PbjtMakananMinuman', 'DasarPengenaan': 'SubtotalPlusLayanan', 'Urutan': 1},
              ],
            },
          ],
          'Produk': [
            {
              'Uuid': 'P1',
              'Sku': 'KSA-001',
              'Nama': 'Es Kopi Susu Aren Gula Semut Ukuran Besar',
              'Jenis': 'Resep',
              'UuidKategori': 'K1',
              'UuidSatuanDasar': 'S1',
              'Pelacakan': 'Tidak',
              'UuidKelompokPajak': 'KP1',
              'HargaTermasukPajak': null,
              'TampilDiPos': true,
              'Aktif': true,
              'Dihapus': false,
            },
          ],
          'ProdukSatuan': [
            {'Uuid': 'PS1', 'UuidProduk': 'P1', 'UuidSatuan': 'S1', 'KonversiKeDasar': '1.0000', 'DefaultJual': true},
          ],
          'ProdukBarcode': [
            {'Uuid': 'B1', 'UuidProduk': 'P1', 'UuidProdukSatuan': 'PS1', 'Barcode': '8991234567890'},
          ],
          'DaftarHarga': [
            {
              'Uuid': 'DH1',
              'Nama': 'Ojol',
              'UuidOutlet': null,
              'Kanal': 'Online',
              'Prioritas': 10,
              'Aktif': true,
              'MulaiPada': null,
              'SelesaiPada': null,
            },
          ],
          'ProdukHarga': [
            {
              'Uuid': 'H1',
              'UuidProduk': 'P1',
              'UuidProdukSatuan': 'PS1',
              'UuidDaftarHarga': null,
              'JumlahMinimum': '1.0000',
              'Harga': '28000.00',
            },
          ],
          'KelompokPilihan': [
            {'Uuid': 'KL1', 'Nama': 'Level gula', 'MinimalPilih': 1, 'MaksimalPilih': 1, 'Urutan': 1},
          ],
          'Pilihan': [
            {
              'Uuid': 'PL1',
              'UuidKelompokPilihan': 'KL1',
              'Nama': 'Kurang manis',
              'Harga': '0.00',
              'Aktif': true,
              'Urutan': 1,
            },
          ],
          'ProdukKelompokPilihan': [
            {'Uuid': 'PK1', 'UuidProduk': 'P1', 'UuidKelompokPilihan': 'KL1', 'Urutan': 1},
          ],
          'Resep': <Object?>[],
          'Terhapus': <Object?>[],
        });
      });

      final katalog = await klien.AmbilKatalog();

      expect(dikirim.url.toString(), 'https://kasir.contoh.id/api/pos/v1/katalog');
      expect(dikirim.headers['Authorization'], 'Bearer Tkn');
      expect(katalog.lengkap, isTrue);
      expect(katalog.kursor, 'a3Vyc29y');
      expect(katalog.produk.single.hargaTermasukPajak, isNull);
      expect(katalog.produk.single.jenis, 'Resep');
      expect(katalog.produk.single.jumlahSesiPaket, isNull);
      expect(katalog.produk.single.favorit, isFalse);
      expect(katalog.produkHarga.single.harga, '28000.00');
      expect(katalog.kelompokPajak.single.pajak.single.dasarPengenaan, 'SubtotalPlusLayanan');
      expect(katalog.kelompokPajak.single.pajak.single.kategori, isNull, reason: 'Server lama tanpa Kategori.');
      expect(katalog.daftarHarga.single.uuidOutlet, isNull);
      expect(katalog.kelompokPilihan.single.maksimalPilih, 1);
      expect(katalog.produkBarcode.single.barcode, '8991234567890');
    });

    test('dengan kursor mengirim ?sejak= ter-encode. Delta membawa Terhapus', () async {
      late http.Request dikirim;
      final klien = BuatKlien((p) async {
        dikirim = p;
        return Json({
          'Skema': 1,
          'Lengkap': false,
          'Kursor': 'baru',
          'WaktuServer': '2026-09-24T01:01:00Z',
          'Produk': [
            {'Uuid': 'P1', 'Nama': 'Teh', 'Dihapus': true},
            {
              'Uuid': 'P2',
              'Nama': 'Paket Creambath 10x',
              'Jenis': 'Jasa',
              'PaketSesi': {'JumlahSesi': 10, 'MasaBerlakuHari': 90, 'Aktif': true},
            },
            {
              'Uuid': 'P3',
              'Nama': 'Ponsel',
              'Pelacakan': 'Seri',
              'MasaGaransiBulan': 12,
              'HargaTerbuka': true,
              'Favorit': true,
            },
          ],
          'Terhapus': [
            {'Entitas': 'ProdukBarcode', 'Uuid': 'B1'},
          ],
        });
      });

      final katalog = await klien.AmbilKatalog(kursor: 'a+b/c=');

      expect(dikirim.url.queryParameters['sejak'], 'a+b/c=');
      expect(katalog.lengkap, isFalse);
      expect(katalog.produk.first.dihapus, isTrue);
      expect(katalog.produk[1].jumlahSesiPaket, 10);
      expect(katalog.produk.last.masaGaransiBulan, 12);
      expect(katalog.produk[1].masaGaransiBulan, isNull, reason: 'Server lama atau produk tanpa garansi.');
      expect(katalog.produk.map((p) => p.hargaTerbuka), [false, false, true], reason: 'K-25. Server lama = false.');
      expect(katalog.produk.map((p) => p.favorit), [
        false,
        false,
        true,
      ], reason: 'K-16. Server lama tanpa Favorit = false.');
      expect(katalog.terhapus.single.entitas, 'ProdukBarcode');
    });

    test('kursor tidak valid → GalatApi KursorTidakValid', () async {
      final klien = BuatKlien(
        (_) async => Json({
          'Galat': {'Kode': 'KursorTidakValid', 'Pesan': 'Kursor katalog tidak valid.'},
        }, 422),
      );
      expect(
        () => klien.AmbilKatalog(kursor: 'rusak'),
        throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'KursorTidakValid')),
      );
    });
  });

  group('DataAwal F-07b', () {
    test('server lama tanpa kunci F-07b → nilai bawaan (kompatibel mundur)', () {
      final data = DataAwal.DariJson(DataAwalF06());
      expect(data.batasDiskonManual, '10');
      expect(data.batasDiskonPenyetuju, '30');
      expect(data.pembulatanTunai, isNull);
      expect(data.outlet, isNull);
      expect(data.perangkat, isNull);
      expect(data.profilPajak.pkp, isFalse);
      expect(data.profilPajak.persenBiayaLayanan, '0');
      expect(data.tarifPajak, isEmpty);
      expect(data.metodePembayaran, isEmpty);
    });

    test('kunci F-07b dipetakan: pengaturan, outlet, perangkat, profil pajak, tarif, metode', () {
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Pengaturan': {
          'BatasKasKeluar': '200000.00',
          'ShiftBersama': false,
          'BatasDiskonManual': '15.00',
          'BatasDiskonPenyetuju': 40,
          'PembulatanTunai': {'Kelipatan': 100, 'Arah': 'Bawah'},
        },
        'Outlet': {'Uuid': 'O1', 'Kode': 'SLB', 'Nama': 'Solo Baru', 'Alamat': null, 'Telepon': '0271'},
        'Perangkat': {'Uuid': 'D1', 'Kode': 'K02'},
        'ProfilPajak': {
          'Pkp': true,
          'PungutPbjt': false,
          'HargaTermasukPajak': true,
          'BiayaLayanan': {'Aktif': true, 'Persen': '5.00'},
        },
        'TarifPajak': [
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
          {
            'Uuid': 'M1',
            'Jenis': 'QrisStatis',
            'Nama': 'QRIS BCA',
            'NomorRekening': null,
            'NamaPemilikRekening': null,
            'AdaGambarQris': true,
            'Urutan': 2,
            // D-189: teks QRIS statis untuk nominal otomatis di kasir.
            'TeksQris': '000201010211',
          },
          // X8: metode platform ojol membawa kanalnya.
          {
            'Uuid': 'M2',
            'Jenis': 'Marketplace',
            'Nama': 'GoFood',
            'AdaGambarQris': false,
            'Urutan': 3,
            'Kanal': 'GoFood',
          },
        ],
      });
      expect(data.batasDiskonManual, '15.00');
      expect(data.batasDiskonPenyetuju, '40');
      expect(data.pembulatanTunai!.kelipatan, 100);
      expect(data.pembulatanTunai!.arah, 'Bawah');
      expect(data.outlet!.kode, 'SLB');
      expect(data.perangkat!.kode, 'K02');
      expect(data.profilPajak.pkp, isTrue);
      expect(data.profilPajak.biayaLayananAktif, isTrue);
      expect(data.profilPajak.persenBiayaLayanan, '5.00');
      expect(data.tarifPajak.single.pengaliDppPembilang, 11);
      expect(data.tarifPajak.single.berlakuSampai, isNull);
      expect(data.metodePembayaran.first.adaGambarQris, isTrue);
      expect(data.metodePembayaran.first.kanal, isNull);
      expect(data.metodePembayaran.first.teksQris, '000201010211');
      expect(data.metodePembayaran.last.teksQris, isNull, reason: 'Server lama / metode lain tanpa TeksQris.');
      expect(data.metodePembayaran.last.kanal, 'GoFood');
      // Server lama: tanpa ZonaWaktu & NomorUrutPenjualan.
      expect(data.outlet!.zonaWaktu, isNull);
      expect(data.perangkat!.nomorUrutPenjualan, isEmpty);
    });

    test('PRD v1.46: Outlet.ZonaWaktu & Perangkat.NomorUrutPenjualan dipetakan. Entri tidak valid diabaikan', () {
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Outlet': {'Uuid': 'O1', 'Kode': 'MKS', 'Nama': 'Makassar', 'ZonaWaktu': 'Asia/Makassar'},
        'Perangkat': {
          'Uuid': 'D1',
          'Kode': 'K02',
          'NomorUrutPenjualan': {'260924': 12, '260925': '3', '2609': 5, '260926': 'x', '260927': 0, '260928': null},
        },
      });
      expect(data.outlet!.zonaWaktu, 'Asia/Makassar');
      expect(data.perangkat!.nomorUrutPenjualan, {'260924': 12, '260925': 3});

      final bukanPeta = DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {'Uuid': 'D1', 'Kode': 'K02', 'NomorUrutPenjualan': <Object?>[]},
      });
      expect(bukanPeta.perangkat!.nomorUrutPenjualan, isEmpty);
    });

    test('F-09: Perangkat.NomorUrutRetur dipetakan seperti NomorUrutPenjualan. Absen/tidak valid → kosong', () {
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {
          'Uuid': 'D1',
          'Kode': 'K02',
          'NomorUrutRetur': {'260924': 5, '260925': '2', '2609': 5, '260926': 'x', '260927': -1, '260928': null},
        },
      });
      expect(data.perangkat!.nomorUrutRetur, {'260924': 5, '260925': 2});
      expect(data.perangkat!.nomorUrutPenjualan, isEmpty);

      final lama = DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {'Uuid': 'D1', 'Kode': 'K02'},
      });
      expect(lama.perangkat!.nomorUrutRetur, isEmpty);
      final bukanPeta = DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {'Uuid': 'D1', 'Kode': 'K02', 'NomorUrutRetur': 'rusak'},
      });
      expect(bukanPeta.perangkat!.nomorUrutRetur, isEmpty);
    });

    test('K26: Perangkat.AwalanAntrian hanya 1–2 huruf (huruf besar), selain itu atau server lama → null', () {
      PerangkatPos? Baca(Object? nilai) => DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {'Uuid': 'D1', 'Kode': 'K02', 'AwalanAntrian': nilai},
      }).perangkat;

      expect(Baca('a')!.awalanAntrian, 'A');
      expect(Baca(' bc ')!.awalanAntrian, 'BC');
      expect(Baca('ABC')!.awalanAntrian, isNull);
      expect(Baca('1')!.awalanAntrian, isNull);
      expect(Baca('')!.awalanAntrian, isNull);
      expect(Baca(null)!.awalanAntrian, isNull);
      expect(
        DataAwal.DariJson({
          ...DataAwalF06(),
          'Perangkat': {'Uuid': 'D1', 'Kode': 'K02'},
        }).perangkat!.awalanAntrian,
        isNull,
      );
    });

    test('F-16d: Deposit & Perangkat.NomorUrutIsiDeposit dipetakan. Server lama → tidak berlaku & kosong', () {
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Perangkat': {
          'Uuid': 'D1',
          'Kode': 'K02',
          'NomorUrutIsiDeposit': {'260924': 3, '2609': 1},
        },
        'Deposit': {'Berlaku': true, 'MinimalIsi': '5000.00', 'MaksimalIsi': '2000000.00'},
      });
      expect(data.perangkat!.nomorUrutIsiDeposit, {'260924': 3});
      expect(data.deposit.berlaku, isTrue);
      expect(data.deposit.minimalIsi, '5000.00');
      expect(data.deposit.maksimalIsi, '2000000.00');
      expect(DepositPos.DariJson(data.deposit.KeJson()).maksimalIsi, '2000000.00');

      final lama = DataAwal.DariJson(DataAwalF06());
      expect(lama.deposit.berlaku, isFalse);
      expect(lama.deposit.minimalIsi, DepositPos.minimalIsiBawaan);
    });

    test('F-09: baris penjualan/cari memetakan BolehDesimal & UuidProdukSatuan. Absen → null', () {
      Map<String, Object?> Baris([Map<String, Object?> tambahan = const {}]) => {
        'Uuid': 'B1',
        'NamaProduk': 'Biji Kopi Arabika Gayo',
        'SimbolSatuan': 'kg',
        'Jumlah': '2.5000',
        'TotalBaris': '187500.00',
        ...tambahan,
      };
      final baru = BarisPenjualanCariPos.DariJson(Baris({'BolehDesimal': true, 'UuidProdukSatuan': 'PS1'}));
      expect(baru.bolehDesimal, isTrue);
      expect(baru.uuidProdukSatuan, 'PS1');
      expect(BarisPenjualanCariPos.DariJson(Baris({'BolehDesimal': false})).bolehDesimal, isFalse);

      final lama = BarisPenjualanCariPos.DariJson(Baris());
      expect(lama.bolehDesimal, isNull);
      expect(lama.uuidProdukSatuan, isNull);
      final salahTipe = BarisPenjualanCariPos.DariJson(Baris({'BolehDesimal': 'ya', 'UuidProdukSatuan': null}));
      expect(salahTipe.bolehDesimal, isNull);
      expect(salahTipe.uuidProdukSatuan, isNull);
    });
  });

  group('DataAwal F-11', () {
    test('server lama tanpa kunci F-11 → tutup shift buta & toleransi Rp 10.000 (kompatibel mundur)', () {
      final data = DataAwal.DariJson(DataAwalF06());
      expect(data.tutupShiftButa, isTrue);
      expect(data.toleransiSelisihKas, '10000');
    });

    test('TutupShiftButa & ToleransiSelisihKas dipetakan dari Pengaturan', () {
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Pengaturan': {
          'BatasKasKeluar': '200000.00',
          'ShiftBersama': false,
          'TutupShiftButa': false,
          'ToleransiSelisihKas': '25000.00',
        },
      });
      expect(data.tutupShiftButa, isFalse);
      expect(data.toleransiSelisihKas, '25000.00');
    });
  });

  group('DataAwal F-09', () {
    test('F-18: daftar Karyawan (server lama = kosong)', () {
      expect(DataAwal.DariJson(DataAwalF06()).karyawan, isEmpty);
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Karyawan': [
          {'Uuid': 'K1', 'Nama': 'Maya Senior', 'Jabatan': 'Stylist'},
          {'Uuid': 'K2', 'Nama': 'Dewi', 'Jabatan': null},
        ],
      });
      expect(data.karyawan.map((k) => (k.uuid, k.nama, k.jabatan)), [
        ('K1', 'Maya Senior', 'Stylist'),
        ('K2', 'Dewi', null),
      ]);
    });

    test('F-12: BatasHariLewatJatuhTempo (server lama = 0) & posisi kredit pelanggan POS', () {
      expect(DataAwal.DariJson(DataAwalF06()).batasHariLewatJatuhTempo, 0);
      expect(
        DataAwal.DariJson({
          ...DataAwalF06(),
          'Pengaturan': {'BatasKasKeluar': '200000.00', 'ShiftBersama': false, 'BatasHariLewatJatuhTempo': 14},
        }).batasHariLewatJatuhTempo,
        14,
      );
      final lama = PelangganPos.DariJson({'Uuid': 'P1', 'Nama': 'Ani', 'NoHp': '0812****7890'});
      expect([lama.limitKredit, lama.sisaPiutang, lama.hariLewatJatuhTempo], [null, '0', 0]);
      final baru = PelangganPos.DariJson({
        'Uuid': 'P2',
        'Nama': 'Toko Makmur Jaya',
        'NoHp': '0813****0001',
        'LimitKredit': '5000000.00',
        'SisaPiutang': '77000.00',
        'HariLewatJatuhTempo': 12,
      });
      expect([baru.limitKredit, baru.sisaPiutang, baru.hariLewatJatuhTempo], ['5000000.00', '77000.00', 12]);
    });

    test('server lama tanpa BatasHariRetur → 7 hari. Nilai server dipetakan', () {
      expect(DataAwal.DariJson(DataAwalF06()).batasHariRetur, 7);
      final data = DataAwal.DariJson({
        ...DataAwalF06(),
        'Pengaturan': {'BatasKasKeluar': '200000.00', 'ShiftBersama': false, 'BatasHariRetur': 14},
      });
      expect(data.batasHariRetur, 14);
    });
  });

  group('penjualan/cari (F-09 retur)', () {
    Map<String, Object?> Struk() => {
      'Penjualan': {
        'Uuid': '01K5PNJ0000000000000000007',
        'Nomor': 'INV/SLB/260920/POS-002-0007',
        'Status': 'DireturSebagian',
        'LabelStatus': 'Diretur sebagian',
        'TanggalBisnis': '2026-09-20',
        'DibuatPada': '2026-09-20T05:12:00Z',
        'NamaKasir': 'Sari Lestari',
        'HargaTermasukPajak': true,
        'Subtotal': '287500.00',
        'TotalDiskon': '0.00',
        'BiayaLayanan': '0.00',
        'TotalPajak': '0.00',
        'Pembulatan': '0.00',
        'TotalAkhir': '287500.00',
        'TotalDibayar': '300000.00',
        'Kembalian': '12500.00',
        'BatasHariRetur': 7,
        'BatasReturSampai': '2026-09-27',
        'BisaDiretur': true,
        'AlasanTidakBisaDiretur': null,
      },
      'Baris': [
        {
          'Uuid': '01K5BRS0000000000000000001',
          'UuidProduk': '01K5PRD0000000000000000001',
          'NamaProduk': 'Kopi Susu Literan 1 L',
          'SimbolSatuan': 'btl',
          'Jumlah': '3.0000',
          'HargaSatuan': '33333.33',
          'HargaPilihan': '0.00',
          'Pilihan': [
            {'UuidPilihan': 'P1', 'Nama': 'Kurang manis', 'Harga': '0.00'},
          ],
          'Bruto': '100000.00',
          'JumlahDiskon': '0.00',
          'JumlahDiskonPesanan': '0.00',
          'BiayaLayanan': '0.00',
          'JumlahPajak': '9909.91',
          'TotalBaris': '100000.00',
          'SnapshotPajak': <Object?>[],
          'JumlahSudahDiretur': '1.0000',
          'JumlahBisaDiretur': '2.0000',
          'NilaiBisaDiretur': '66666.67',
        },
      ],
      'Pembayaran': [
        {
          'Uuid': 'B1',
          'UuidMetodePembayaran': 'M1',
          'JenisMetode': 'Tunai',
          'NamaMetode': 'Tunai',
          'Jumlah': '300000.00',
          'Referensi': null,
        },
      ],
      'Retur': [
        {
          'Uuid': 'R1',
          'Nomor': 'RJ/SLB/260921/POS-002-0001',
          'DibuatPada': '2026-09-21T02:00:00Z',
          'TotalRefund': '33333.33',
        },
      ],
    };

    test('nomor dikodekan di query. Respons dipetakan (uang & jumlah tetap teks desimal)', () async {
      late http.Request dikirim;
      final klien = BuatKlien((p) async {
        dikirim = p;
        return Json(Struk(), 200);
      });

      final hasil = await klien.CariPenjualan(' INV/SLB/260920/POS-002-0007 ');

      expect(dikirim.method, 'GET');
      expect(dikirim.url.path, '/api/pos/v1/penjualan/cari');
      expect(dikirim.url.queryParameters, {'nomor': 'INV/SLB/260920/POS-002-0007'});
      expect(dikirim.headers['Authorization'], startsWith('Bearer '));
      expect(hasil.penjualan.labelStatus, 'Diretur sebagian');
      expect((hasil.penjualan.bisaDiretur, hasil.penjualan.batasReturSampai), (true, '2026-09-27'));
      final b = hasil.baris.single;
      expect(
        (b.jumlah, b.jumlahSudahDiretur, b.jumlahBisaDiretur, b.nilaiBisaDiretur, b.totalBaris),
        ('3.0000', '1.0000', '2.0000', '66666.67', '100000.00'),
      );
      expect(b.pilihan, ['Kurang manis']);
      expect(hasil.pembayaran.single.jenisMetode, 'Tunai');
      expect(hasil.retur.single.totalRefund, '33333.33');
    });

    test('404 PenjualanTidakDitemukan → GalatApi. Server tak terjangkau → GalatJaringan', () async {
      final tidakAda = BuatKlien(
        (_) async => Json({
          'Galat': {'Kode': 'PenjualanTidakDitemukan', 'Pesan': 'Penjualan tidak ditemukan.'},
        }, 404),
      );
      await expectLater(
        tidakAda.CariPenjualan('INV/X'),
        throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'PenjualanTidakDitemukan')),
      );
      final offline = BuatKlien((_) async => throw http.ClientException('offline'));
      await expectLater(offline.CariPenjualan('INV/X'), throwsA(isA<GalatJaringan>()));
    });
  });

  test('AmbilGambarQris mengembalikan bait gambar; 404 → GalatApi', () async {
    final klien = BuatKlien(
      (p) async => p.url.path.endsWith('/M1/gambar-qris')
          ? http.Response.bytes([137, 80, 78, 71], 200, headers: {'content-type': 'image/png'})
          : Json({
              'Galat': {'Kode': 'GambarQrisTidakAda', 'Pesan': 'Gambar QRIS belum diunggah.'},
            }, 404),
    );
    expect(await klien.AmbilGambarQris('M1'), [137, 80, 78, 71]);
    expect(() => klien.AmbilGambarQris('M2'), throwsA(isA<GalatApi>()));
  });

  test('AmbilGambarProduk memakai alamat perangkat + token; 404 → null', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return p.url.path.endsWith('/P1')
          ? http.Response.bytes([137, 80, 78, 71], 200, headers: {'content-type': 'image/png'})
          : Json({}, 404);
    });
    const urlInternal = 'http://web-internal/api/pos/v1/katalog/gambar/P1?ukuran=kecil&versi=V1';
    expect(await klien.AmbilGambarProduk(urlInternal), [137, 80, 78, 71]);
    expect(dikirim.url.toString(), 'https://kasir.contoh.id/api/pos/v1/katalog/gambar/P1?ukuran=kecil&versi=V1');
    expect(dikirim.headers['Authorization'], 'Bearer Tkn');
    expect(await klien.AmbilGambarProduk(urlInternal.replaceFirst('/P1?', '/P2?')), isNull);
    expect(() => klien.AmbilGambarProduk('https://contoh.id/bukan-pos/P1'), throwsA(isA<GalatJaringan>()));
  });

  test('PRD v1.46: Pajak[].Kategori (Ppn/Pbjt/Lainnya) dipetakan. Nilai lain → null', () {
    PajakKelompokPos Urai(Object? kategori) => PajakKelompokPos.DariJson({
      'KodeJenisPajak': 'PbjtMakananMinuman',
      'DasarPengenaan': 'Subtotal',
      'Urutan': 1,
      'Kategori': kategori,
    });
    expect(Urai('Ppn').kategori, 'Ppn');
    expect(Urai('Pbjt').kategori, 'Pbjt');
    expect(Urai('Lainnya').kategori, 'Lainnya');
    expect(Urai('PPN').kategori, isNull);
    expect(Urai(3).kategori, isNull);
  });

  test('PRD v1.79: Struk dipetakan. Server lama tanpa Struk → null. Saklar absen → aktif', () {
    final struk = StrukPos.DariJson({
      'NamaUsaha': 'Kopi Senja',
      'Npwp': '0123456789012345',
      'AdaLogo': true,
      'TandaAir': true,
      'NamaDicetak': 'Senja Coffee',
      'TeksKepala': ['Buka 07.00', 3],
      'TampilkanKasir': false,
      'CatatanKaki': 'Tukar 7 hari',
    })!;
    expect(struk.namaDicetak, 'Senja Coffee');
    expect(struk.teksKepala, ['Buka 07.00']);
    expect((struk.tampilkanKasir, struk.tampilkanAlamat, struk.adaLogo, struk.tandaAir), (false, true, true, true));
    expect(StrukPos.DariJson(struk.KeJson())!.KeJson(), struk.KeJson());
    expect(StrukPos.DariJson(null), isNull);
  });

  test('AmbilLogoStruk: bait gambar; 404 → null', () async {
    var ada = true;
    final klien = BuatKlien(
      (p) async => ada
          ? http.Response.bytes([137, 80, 78, 71], 200, headers: {'content-type': 'image/png'})
          : Json({
              'Galat': {'Kode': 'TidakDitemukan', 'Pesan': 'Tidak ada.'},
            }, 404),
    );
    expect(await klien.AmbilLogoStruk(), [137, 80, 78, 71]);
    ada = false;
    expect(await klien.AmbilLogoStruk(), isNull);
  });

  test('D-48: KodeSektor dipetakan. Server lama tanpa kunci → kosong (semua fitur sektor tampil)', () {
    final data = DataAwal.DariJson({
      ...DataAwalF06(),
      'KodeSektor': ['RTL-GEN', 'SVC-WRK'],
    });
    expect(data.kodeSektor, ['RTL-GEN', 'SVC-WRK']);
    expect(DataAwal.DariJson(DataAwalF06()).kodeSektor, isEmpty);
  });
}
