import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:inti/Inti.dart';
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

const String uuidSalesman = '01K5STAF00000000000SALES01';

/// Modul Salesman bagian 2 (§9.7, SLS-11): klien `GET /api/pos/v1/salesman/*` memakai device token + `X-Id-Kasir`
/// (pengguna aktif), uang & jumlah diurai sebagai desimal (bukan pecahan biner).
void main() {
  test('pelanggan salesman: X-Id-Kasir, halaman & kata di kueri, uang diurai desimal, waktu UTC', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Pelanggan': [
          {
            'Uuid': '01K5PLG0000000000000T0K001',
            'Nama': 'Toko Kelontong Makmur Jaya Abadi',
            'NoHp': '6281355550001',
            'Alamat': 'Jl. Slamet Riyadi No. 212, Purwosari, Laweyan, Surakarta',
            'KodeTier': 'GROSIR',
            'NamaTier': 'Grosir',
            'LimitKredit': '25000000.00',
            'TerminHari': 30,
            'SisaPiutang': '12750000.50',
            'JumlahPiutangJatuhTempo': '3250000.00',
            'HariLewatJatuhTempo': 12,
            'TerakhirDikunjungiPada': '2026-10-01T03:15:00Z',
          },
          {
            'Uuid': '01K5PLG0000000000000T0K002',
            'Nama': 'Warung Bu Sri',
            'NoHp': null,
            'Alamat': null,
            'KodeTier': null,
            'NamaTier': null,
            'LimitKredit': null,
            'TerminHari': 0,
            'SisaPiutang': '0.00',
            'JumlahPiutangJatuhTempo': '0.00',
            'HariLewatJatuhTempo': 0,
            'TerakhirDikunjungiPada': null,
          },
        ],
        'Halaman': 2,
        'AdaBerikutnya': true,
      });
    });

    final hasil = await klien.AmbilPelangganSalesman(uuidPengguna: uuidSalesman, kata: ' makmur ', halaman: 2);

    expect(dikirim.method, 'GET');
    expect(dikirim.url.path, '/api/pos/v1/salesman/pelanggan');
    expect(dikirim.url.queryParameters, {'halaman': '2', 'kata': 'makmur'});
    expect(dikirim.headers['X-Id-Kasir'], uuidSalesman);
    expect(dikirim.headers['Authorization'], 'Bearer Tkn');
    expect(dikirim.headers.containsKey('Idempotency-Key'), isFalse, reason: 'GET tidak membawa kunci idempotensi.');
    expect((hasil.halaman, hasil.adaBerikutnya, hasil.pelanggan.length), (2, true, 2));

    final toko = hasil.pelanggan.first;
    expect(toko.noHp, '6281355550001');
    expect(toko.limitKredit, Uang.Dari('25000000.00'));
    expect(toko.sisaPiutang.KeString(), '12750000.50');
    expect(toko.jumlahPiutangJatuhTempo, Uang.DariBulat(3250000));
    expect((toko.terminHari, toko.hariLewatJatuhTempo, toko.kodeTier), (30, 12, 'GROSIR'));
    expect(toko.terakhirDikunjungiPada, DateTime.utc(2026, 10, 1, 3, 15));
    expect(toko.terakhirDikunjungiPada!.isUtc, isTrue);

    final warung = hasil.pelanggan.last;
    expect((warung.noHp, warung.alamat, warung.limitKredit, warung.terakhirDikunjungiPada), (null, null, null, null));
    expect(warung.sisaPiutang.BernilaiNol(), isTrue);
  });

  test('pelanggan salesman tanpa kata: hanya halaman. Nilai uang rusak dibaca nol, bukan gagal', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Pelanggan': [
          {'Uuid': 'A', 'Nama': 'Toko A', 'SisaPiutang': 'bukan angka', 'JumlahPiutangJatuhTempo': 1500},
        ],
        'Halaman': 1,
        'AdaBerikutnya': false,
      });
    });

    final hasil = await klien.AmbilPelangganSalesman(uuidPengguna: uuidSalesman);

    expect(dikirim.url.query, 'halaman=1');
    expect(hasil.pelanggan.single.sisaPiutang.BernilaiNol(), isTrue);
    expect(hasil.pelanggan.single.jumlahPiutangJatuhTempo, Uang.DariBulat(1500));
  });

  test('piutang pelanggan: jalur ber-Uuid, jumlah & sisa desimal, umur hari menandai lewat jatuh tempo', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Piutang': [
          {
            'Uuid': '01K5PIU0000000000000000001',
            'Nomor': 'FJ/SLO/2609/0004',
            'Tanggal': '2026-09-01',
            'JatuhTempo': '2026-09-20',
            'Jumlah': '5000000.00',
            'Sisa': '3250000.00',
            'UmurHari': 12,
            'Status': 'DibayarSebagian',
          },
          {
            'Uuid': '01K5PIU0000000000000000002',
            'Nomor': 'PJ/SLO/K01/260925/0012',
            'Tanggal': '2026-09-25',
            'JatuhTempo': '2026-10-25',
            'Jumlah': '9500000.50',
            'Sisa': '9500000.50',
            'UmurHari': -23,
            'Status': 'BelumLunas',
          },
        ],
      });
    });

    final piutang = await klien.AmbilPiutangSalesman('01K5PLG0000000000000T0K001', uuidPengguna: uuidSalesman);

    expect(dikirim.url.path, '/api/pos/v1/salesman/pelanggan/01K5PLG0000000000000T0K001/piutang');
    expect(dikirim.headers['X-Id-Kasir'], uuidSalesman);
    expect(piutang.map((p) => p.lewatJatuhTempo), [true, false]);
    expect(piutang.first.sisa, Uang.DariBulat(3250000));
    expect(piutang.last.jumlah.KeString(), '9500000.50');
    expect(piutang.last.jatuhTempo, '2026-10-25');
  });

  test('stok salesman: jumlah tersedia sebagai Kuantitas per produk (stok minus tetap terbaca)', () async {
    final klien = BuatKlien(
      (p) async => Json({
        'Stok': [
          {'UuidProduk': 'GULA', 'JumlahTersedia': '480.0000'},
          {'UuidProduk': 'MINYAK', 'JumlahTersedia': '-3.5000'},
          {'UuidProduk': '', 'JumlahTersedia': '1.0000'},
        ],
        'DiambilPada': '2026-10-02T02:00:00Z',
      }),
    );

    final stok = await klien.AmbilStokSalesman(uuidPengguna: uuidSalesman);

    expect(stok.stok.keys, ['GULA', 'MINYAK']);
    expect(stok.stok['GULA'], Kuantitas.DariBulat(480));
    expect(stok.stok['MINYAK']!.BernilaiNegatif(), isTrue);
    expect(stok.diambilPada, DateTime.utc(2026, 10, 2, 2));
  });

  test('kunjungan salesman: tanggal di kueri, koordinat tetap teks, nomor pesanan dari server', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Tanggal': '2026-10-02',
        'Kunjungan': [
          {
            'Uuid': '01K5KNJ0000000000000000001',
            'UuidPelanggan': '01K5PLG0000000000000T0K001',
            'NamaPelanggan': 'Toko Kelontong Makmur Jaya Abadi',
            'MasukPada': '2026-10-02T02:10:00Z',
            'KeluarPada': '2026-10-02T02:35:00Z',
            'Latitude': '-7.5666001',
            'Longitude': '110.8166002',
            'AkurasiMeter': 12,
            'Hasil': 'PesananDibuat',
            'LabelHasil': 'Pesanan dibuat',
            'Catatan': null,
            'UuidPesananGrosir': '01K5PG00000000000000000001',
            'NomorPesananGrosir': 'PG/SLO/2610/0001',
          },
        ],
      });
    });

    final daftar = await klien.AmbilKunjunganSalesman(uuidPengguna: uuidSalesman, tanggal: '2026-10-02');

    expect(dikirim.url.path, '/api/pos/v1/salesman/kunjungan');
    expect(dikirim.url.queryParameters, {'tanggal': '2026-10-02'});
    expect(dikirim.headers['X-Id-Kasir'], uuidSalesman);
    expect(daftar.tanggal, '2026-10-02');
    final k = daftar.kunjungan.single;
    expect((k.latitude, k.longitude, k.akurasiMeter), ('-7.5666001', '110.8166002', 12));
    expect((k.hasil, k.labelHasil, k.nomorPesananGrosir), ('PesananDibuat', 'Pesanan dibuat', 'PG/SLO/2610/0001'));
    expect(k.keluarPada!.difference(k.masukPada!), const Duration(minutes: 25));
  });

  test('tanpa izin salesman: 403 TanpaIzin menjadi GalatApi', () async {
    final klien = BuatKlien(
      (p) async => Json({
        'Galat': {'Kode': 'TanpaIzin', 'Pesan': 'Pengguna ini tidak punya izin salesman di outlet ini.'},
      }, 403),
    );

    await expectLater(
      klien.AmbilStokSalesman(uuidPengguna: uuidSalesman),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'TanpaIzin').having((g) => g.statusHttp, 'status', 403)),
    );
  });
}
