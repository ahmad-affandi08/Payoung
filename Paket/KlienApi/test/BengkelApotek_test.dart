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

http.Response Json(Object isi, int status) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

/// Bentuk persis `PerintahKerjaPos::Petakan` di server (Bengkel bagian 1, §9.10).
Map<String, Object?> PerintahKerjaJson() => {
  'Uuid': '01K6PK000000000000000000A1',
  'Nomor': 'WO/SLO/2610/0007',
  'Status': 'Selesai',
  'LabelStatus': 'Selesai',
  'SiapTagih': true,
  'DibuatPada': '2026-10-02T01:15:00Z',
  'Pelanggan': {
    'Uuid': '01K6PLG00000000000000000A1',
    'Nama': 'Bambang Sutrisno',
    'NoHp': '0812****7890',
    'KodeTier': 'GOLD',
  },
  'Kendaraan': {'Uuid': '01K6KND00000000000000000A1', 'NomorPolisi': 'AD 1234 XY', 'Label': 'Honda Vario 125 Hitam'},
  'KmMasuk': 23450,
  'Keluhan': 'Rem belakang bunyi, tarikan berat',
  'CatatanQc': 'Rem & tarikan sudah normal',
  'TotalDisetujui': '105000.00',
  'Baris': [
    {
      'Uuid': '01K6PKD0000000000000000001',
      'Jenis': 'Jasa',
      'UuidProduk': '01K6PRD000000000000SERV1S1',
      'UuidProdukSatuan': '01K6PS0000000000000SERV1S1',
      'NamaProduk': 'Servis rutin motor matic',
      'Jumlah': '1.0000',
      'HargaSatuan': '50000.00',
      'Diskon': '0.00',
      'UuidKaryawan': '01K6KRY00000000000000MKN01',
      'NamaKaryawan': 'Joko Mekanik',
      'Catatan': null,
    },
    {
      'Uuid': '01K6PKD0000000000000000002',
      'Jenis': 'Sparepart',
      'UuidProduk': '01K6PRD0000000000000OL1001',
      'UuidProdukSatuan': '01K6PS00000000000000OL1001',
      'NamaProduk': 'Oli MPX2 0,8 L',
      'Jumlah': '1.0000',
      'HargaSatuan': '55000.00',
      'Diskon': '5000.00',
      'UuidKaryawan': null,
      'NamaKaryawan': null,
      'Catatan': 'Ganti baru',
    },
  ],
};

void main() {
  test('Bengkel: daftar perintah kerja siap tagih / aktif dan satu perintah kerja dipetakan utuh', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.url.path.endsWith('/perintah-kerja')) {
        return Json({
          'PerintahKerja': [PerintahKerjaJson()],
        }, 200);
      }
      return Json({'PerintahKerja': PerintahKerjaJson()}, 200);
    });

    final daftar = await klien.AmbilPerintahKerja();
    expect(dikirim.last.url.path, '/api/pos/v1/perintah-kerja');
    expect(dikirim.last.url.queryParameters, {'status': 'siap-tagih'});
    final pk = daftar.single;
    expect(
      (pk.nomor, pk.siapTagih, pk.nomorPolisi, pk.labelKendaraan),
      ('WO/SLO/2610/0007', true, 'AD 1234 XY', 'Honda Vario 125 Hitam'),
    );
    expect((pk.namaPelanggan, pk.kmMasuk, pk.totalDisetujui), ('Bambang Sutrisno', 23450, '105000.00'));
    expect(pk.dibuatPada, DateTime.utc(2026, 10, 2, 1, 15));
    final jasa = pk.baris.first;
    expect(
      (jasa.CekJasa, jasa.uuidKaryawan, jasa.namaKaryawan, jasa.hargaSatuan),
      (true, '01K6KRY00000000000000MKN01', 'Joko Mekanik', '50000.00'),
    );
    final oli = pk.baris.last;
    expect(
      (oli.CekJasa, oli.uuidKaryawan, oli.diskon, oli.uuidProdukSatuan, oli.catatan),
      (false, null, '5000.00', '01K6PS00000000000000OL1001', 'Ganti baru'),
    );

    await klien.AmbilPerintahKerja(semuaAktif: true);
    expect(dikirim.last.url.queryParameters, {'status': 'aktif'});

    final satu = await klien.AmbilSatuPerintahKerja('01K6PK000000000000000000A1');
    expect(dikirim.last.url.path, '/api/pos/v1/perintah-kerja/01K6PK000000000000000000A1');
    expect(satu.baris, hasLength(2));
  });

  test('Bengkel: perintah kerja dari server lama / kosong tidak membuat urai gagal', () {
    final pk = PerintahKerjaPos.DariJson({'Uuid': 'A', 'Nomor': 'WO/1', 'Baris': null, 'Pelanggan': null});
    expect((pk.siapTagih, pk.nomorPolisi, pk.namaPelanggan, pk.totalDisetujui), (false, null, null, '0'));
    expect(pk.baris, isEmpty);
  });

  test('Servis elektronik (D-142): unit Perangkat memakai merek & tipe sebagai pengenal; IMEI boleh kosong', () {
    final perangkat = PerintahKerjaPos.DariJson({
      'Uuid': 'A',
      'Nomor': 'WO/1',
      'Kendaraan': {'Uuid': 'U1', 'Jenis': 'Perangkat', 'NomorPolisi': null, 'Label': 'Samsung Galaxy A54'},
    });
    expect(
      (perangkat.unitPerangkat, perangkat.jenisUnit, perangkat.pengenalUnit),
      (true, 'Perangkat', 'Samsung Galaxy A54'),
    );

    // Server lama tanpa `Jenis` = kendaraan; pengenalnya plat.
    final kendaraan = PerintahKerjaPos.DariJson({
      'Uuid': 'B',
      'Nomor': 'WO/2',
      'Kendaraan': {'Uuid': 'U2', 'NomorPolisi': 'AD 1234 XY', 'Label': 'Honda Vario 125'},
    });
    expect((kendaraan.unitPerangkat, kendaraan.jenisUnit, kendaraan.pengenalUnit), (false, 'Kendaraan', 'AD 1234 XY'));
  });

  test('Apotek: golongan obat, OWA, prekursor, wajib resep dari katalog; server lama = bukan obat', () {
    final keras = ProdukPos.DariJson({
      'Uuid': 'P1',
      'Nama': 'Amoxicillin 500 mg Kapsul',
      'GolonganObat': 'Keras',
      'ObatWajibApotek': false,
      'Prekursor': false,
      'WajibResep': true,
    });
    expect(
      (keras.golonganObat, keras.obatWajibApotek, keras.prekursor, keras.wajibResep),
      ('Keras', false, false, true),
    );
    final owa = ProdukPos.DariJson({
      'Uuid': 'P2',
      'Nama': 'Asam Mefenamat',
      'GolonganObat': 'Keras',
      'ObatWajibApotek': true,
    });
    expect((owa.obatWajibApotek, owa.wajibResep), (true, false));
    final pseudo = ProdukPos.DariJson({
      'Uuid': 'P3',
      'Nama': 'Pseudoefedrin',
      'GolonganObat': 'BebasTerbatas',
      'Prekursor': true,
    });
    expect(pseudo.prekursor, isTrue);
    final lama = ProdukPos.DariJson({'Uuid': 'P4', 'Nama': 'Roti Tawar'});
    expect((lama.golonganObat, lama.obatWajibApotek, lama.prekursor, lama.wajibResep), (null, false, false, false));
  });
}
