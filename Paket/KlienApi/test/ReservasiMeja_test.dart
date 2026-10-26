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

/// Bentuk persis `ReservasiMejaPos::Ambil` di server (FnB reservasi meja + DP, D-145).
Map<String, Object?> ReservasiMejaJson({
  String status = 'Dikonfirmasi',
  String? uuidMeja = '01K7MEJA000000000000000007',
}) => {
  'Uuid': '01K7RM00000000000000000001',
  'Nomor': 'RM/2026/10/0001',
  'MulaiPada': '2026-10-13T12:00:00Z',
  'SelesaiPada': '2026-10-13T14:00:00Z',
  'NamaPemesan': 'Bapak Hendra Wijaya',
  'NoHp': '0812-3456-7890',
  'JumlahTamu': 4,
  'UuidMeja': uuidMeja,
  'NamaMeja': uuidMeja == null ? null : '7',
  'Status': status,
  'LabelStatus': status,
  'Catatan': 'Ulang tahun',
  'UangMuka': {'Dicatat': '30000.00', 'Terpakai': '0.00', 'Sisa': '30000.00'},
};

void main() {
  test('AmbilReservasiMeja: antrian, uang muka, dan metode sistem DP terbaca', () async {
    late http.Request diminta;
    final klien = BuatKlien((p) async {
      diminta = p;
      return Json({
        'Reservasi': [ReservasiMejaJson(), ReservasiMejaJson(status: 'Selesai', uuidMeja: null)],
        'MetodeUangMuka': {'Uuid': '01K7MTD0000000000000000001', 'Nama': 'Uang muka (DP)'},
      }, 200);
    });

    final antrian = await klien.AmbilReservasiMeja(tanggal: '2026-10-13');

    expect(diminta.method, 'GET');
    expect(diminta.url.path, '/api/pos/v1/reservasi-meja');
    expect(diminta.url.queryParameters['tanggal'], '2026-10-13');
    expect(antrian.reservasi, hasLength(2));
    final satu = antrian.reservasi.first;
    expect(satu.nomor, 'RM/2026/10/0001');
    expect(satu.mulaiPada, DateTime.utc(2026, 10, 13, 12));
    expect(satu.namaMeja, '7');
    expect(satu.jumlahTamu, 4);
    expect(satu.uangMuka.sisa, '30000.00');
    expect(satu.BisaDilayani, isTrue);
    expect(satu.BelumDatang, isTrue);
    expect(antrian.reservasi.last.BisaDilayani, isFalse);
    expect(antrian.reservasi.last.uuidMeja, isNull);
    expect(antrian.uuidMetodeUangMuka, '01K7MTD0000000000000000001');
    expect(antrian.namaMetodeUangMuka, 'Uang muka (DP)');
  });

  test('AmbilReservasiMeja tanpa DP: metode uang muka null. Server lama tanpa kolom tetap terbaca', () async {
    final klien = BuatKlien(
      (p) async => Json({
        'Reservasi': [
          {'Uuid': 'A', 'Nomor': 'RM/1', 'Status': 'Hadir'},
        ],
        'MetodeUangMuka': null,
      }, 200),
    );

    final antrian = await klien.AmbilReservasiMeja();

    expect(antrian.uuidMetodeUangMuka, isNull);
    expect(antrian.reservasi.single.uangMuka.sisa, '0.00');
    expect(antrian.reservasi.single.BelumDatang, isFalse);
    expect(antrian.reservasi.single.BisaDilayani, isTrue);
  });

  test('HadirReservasiMeja mengirim pengguna & meja (opsional). Galat kapasitas dilempar sebagai GalatApi', () async {
    final isi = <Map<String, Object?>>[];
    final klien = BuatKlien((p) async {
      isi.add(jsonDecode(p.body) as Map<String, Object?>);
      expect(p.url.path, '/api/pos/v1/reservasi-meja/01K7RM00000000000000000001/hadir');
      if (isi.length == 3) {
        return Json({
          'Galat': {'Kode': 'KapasitasKurang', 'Pesan': 'Meja 9 berkapasitas 2 orang, tamu 3.'},
        }, 422);
      }
      return Json({'Reservasi': ReservasiMejaJson(status: 'Hadir'), 'MetodeUangMuka': null}, 200);
    });

    final hasil = await klien.HadirReservasiMeja('01K7RM00000000000000000001', uuidPengguna: 'KASIR');
    await klien.HadirReservasiMeja('01K7RM00000000000000000001', uuidPengguna: 'KASIR', uuidMeja: 'MEJA');

    expect(hasil.reservasi.status, 'Hadir');
    expect(isi[0], {'UuidPengguna': 'KASIR'});
    expect(isi[1], {'UuidPengguna': 'KASIR', 'UuidMeja': 'MEJA'});
    await expectLater(
      klien.HadirReservasiMeja('01K7RM00000000000000000001', uuidPengguna: 'KASIR', uuidMeja: 'MEJA9'),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'KapasitasKurang')),
    );
  });
}
