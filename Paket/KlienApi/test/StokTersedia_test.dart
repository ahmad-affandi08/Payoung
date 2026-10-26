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

/// BR-05.2: salinan stok tersedia untuk kasir offline-first (`GET /api/pos/v1/stok-tersedia`).
void main() {
  test('BR-05.2: stok tersedia dibaca apa adanya sebagai string desimal, termasuk nol dan negatif', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'WaktuServer': '2026-10-08T03:15:00Z',
        'Produk': [
          {'UuidProduk': 'P1', 'Tersedia': '12.0000'},
          {'UuidProduk': 'P2', 'Tersedia': '0.0000'},
          {'UuidProduk': 'P3', 'Tersedia': '-3.5000'},
        ],
      }, 200);
    });

    final hasil = await klien.AmbilStokTersedia();

    expect(dikirim.single.method, 'GET');
    expect(dikirim.single.url.path, '/api/pos/v1/stok-tersedia');
    expect(dikirim.single.headers['Authorization'], 'Bearer Tkn');
    expect(hasil.lengkap, isTrue);
    expect(hasil.waktuServer, DateTime.utc(2026, 10, 8, 3, 15));
    expect(hasil.tersedia, {'P1': '12.0000', 'P2': '0.0000', 'P3': '-3.5000'});
  });

  test('BR-05.2: angka JSON jadi teks. Baris tanpa Uuid atau tanpa jumlah dilewati, bukan dianggap nol', () {
    final hasil = StokTersediaPos.DariJson({
      'Produk': [
        {'UuidProduk': 'P1', 'Tersedia': 5},
        {'UuidProduk': 'P2'},
        {'UuidProduk': 'P3', 'Tersedia': ''},
        {'UuidProduk': 'P4', 'Tersedia': null},
        {'Tersedia': '9.0000'},
        'bukan-peta',
      ],
    });

    expect(hasil.lengkap, isTrue);
    expect(hasil.tersedia, {'P1': '5'});
  });

  test('BR-05.2: jawaban tak terduga tidak membuat galat dan ditandai tidak lengkap', () async {
    final kosong = await BuatKlien((_) async => Json(<String, Object?>{}, 200)).AmbilStokTersedia();
    final bukanJson = await BuatKlien((_) async => http.Response('<html>proxy</html>', 200)).AmbilStokTersedia();
    final dataLain = await BuatKlien(
      (_) async => Json({
        'Pengaturan': {'BatasKasKeluar': '200000.00'},
        'Produk': 'bukan-larik',
      }, 200),
    ).AmbilStokTersedia();
    final waktuRusak = StokTersediaPos.DariJson({'WaktuServer': 'kemarin', 'Produk': <Object?>[]});

    for (final hasil in [kosong, bukanJson, dataLain]) {
      expect(hasil.lengkap, isFalse, reason: 'Jawaban begini tidak boleh dianggap "semua produk tanpa batas".');
      expect(hasil.tersedia, isEmpty);
      expect(hasil.waktuServer, isNull);
    }
    expect(waktuRusak.lengkap, isTrue);
    expect(waktuRusak.waktuServer, isNull);
    expect(waktuRusak.tersedia, isEmpty);
  });

  test('BR-05.2: daftar kosong yang sah (semua produk tanpa batas) tetap lengkap', () async {
    final hasil = await BuatKlien(
      (_) async => Json({'WaktuServer': '2026-10-08T03:15:00Z', 'Produk': <Object?>[]}, 200),
    ).AmbilStokTersedia();

    expect(hasil.lengkap, isTrue);
    expect(hasil.tersedia, isEmpty);
  });

  test('BR-05.2: galat server menjadi GalatApi dan server tak terjangkau menjadi GalatJaringan', () async {
    final ditolak = BuatKlien(
      (_) async => Json({
        'Galat': {'Kode': 'FiturTidakTersedia', 'Pesan': 'Belum tersedia.'},
      }, 404),
    );
    final mati = BuatKlien((_) async => throw http.ClientException('offline'));

    await expectLater(ditolak.AmbilStokTersedia(), throwsA(isA<GalatApi>()));
    await expectLater(mati.AmbilStokTersedia(), throwsA(isA<GalatJaringan>()));
  });
}
