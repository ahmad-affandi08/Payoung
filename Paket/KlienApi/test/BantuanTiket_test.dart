import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:test/test.dart';

KlienPos BuatKlien(Future<http.Response> Function(http.Request permintaan) penangan) => KlienPos(
  alamatDasar: Uri.parse('https://kasir.contoh.id/'),
  versiAplikasi: '1.0.0+7',
  ambilToken: () => 'Tkn',
  klien: MockClient(penangan),
);

http.Response Json(Object isi, int status) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

Map<String, Object?> TiketUji({String status = 'Baru', List<Map<String, Object?>> pesan = const []}) => {
  'Uuid': '01K5TIKET00000000000000001',
  'Nomor': 'TKT-2026-000012',
  'Judul': 'Printer struk tidak mencetak',
  'LabelKategori': 'Perangkat & printer',
  'LabelPrioritas': 'Tinggi',
  'Status': status,
  'LabelStatus': status == 'Baru' ? 'Baru' : 'Ditangani',
  'DibuatPada': '2026-09-24T02:00:00Z',
  'BatasSlaPada': '2026-09-24T10:00:00Z',
  'ResponsPertamaPada': null,
  'LewatSla': false,
  'SlaJamKerja': true,
  'BisaDibalas': true,
  'Pesan': pesan,
};

/// P-09: tombol Bantuan di Aplikasi Kasir dan Owner memakai model & klien bersama.
void main() {
  test('model tiket: waktu, status terbuka, SLA jam kerja, dan percakapan dengan pengirim tim', () {
    final tiket = TiketBantuan.DariJson(
      TiketUji(
        status: 'MenungguPelanggan',
        pesan: [
          {
            'Uuid': 'P1',
            'JenisPengirim': 'Pengguna',
            'NamaPengirim': 'Rina',
            'Isi': 'Printer mati.',
            'DibuatPada': '2026-09-24T02:00:00Z',
          },
          {
            'Uuid': 'P2',
            'JenisPengirim': 'Pengelola',
            'NamaPengirim': 'Dewi | Tim Dukungan',
            'Isi': 'Sudah dicoba kabel lain?',
            'DibuatPada': '2026-09-24T03:00:00Z',
          },
        ],
      ),
    );

    expect(tiket.nomor, 'TKT-2026-000012');
    expect(tiket.terbuka && tiket.menungguBalasan && tiket.slaJamKerja && tiket.bisaDibalas, isTrue);
    expect(tiket.batasSlaPada, DateTime.utc(2026, 9, 24, 10).toLocal());
    expect(tiket.pesan.map((p) => p.dariTim), [false, true]);
    expect(TiketBantuan.DariJson(TiketUji(status: 'Ditutup')).terbuka, isFalse);
    expect(
      TiketBantuan.DariJson(const <String, Object?>{}).status,
      'Baru',
      reason: 'Server lama/rusak tidak boleh melempar.',
    );
  });

  test('daftar tiket mengirim UuidPengguna dan memetakan baris. Baris rusak dibuang', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Tiket': [TiketUji(), 'bukan-peta'],
      }, 200);
    });

    final daftar = await klien.DaftarTiketBantuan('01K5USR00000000000000KASIR1');

    expect(dikirim.method, 'GET');
    expect(dikirim.url.path, '/api/pos/v1/bantuan/tiket');
    expect(dikirim.url.queryParameters['UuidPengguna'], '01K5USR00000000000000KASIR1');
    expect(daftar, hasLength(1));
  });

  test('buat tiket: isian lengkap dikirim, prioritas/layar/log opsional hanya bila ada', () async {
    late Map<String, Object?> badan;
    final klien = BuatKlien((p) async {
      badan = jsonDecode(p.body) as Map<String, Object?>;
      return Json({'Tiket': TiketUji()}, 201);
    });

    final tiket = await klien.BuatTiketBantuan(
      uuidPengguna: 'U1',
      kategori: 'Perangkat',
      judul: 'Printer struk tidak mencetak',
      isi: 'Sejak pagi printer tidak mencetak.',
      layarAsal: 'Pengaturan > Printer',
      logSingkat: const ['09:01 cetak gagal'],
    );

    expect(tiket.nomor, 'TKT-2026-000012');
    expect(badan, {
      'UuidPengguna': 'U1',
      'Kategori': 'Perangkat',
      'Judul': 'Printer struk tidak mencetak',
      'Isi': 'Sejak pagi printer tidak mencetak.',
      'LayarAsal': 'Pengaturan > Printer',
      'LogSingkat': ['09:01 cetak gagal'],
    });

    await klien.BuatTiketBantuan(
      uuidPengguna: 'U1',
      kategori: 'Lainnya',
      judul: 'Judul tiket',
      isi: 'Isi tiket cukup panjang.',
    );
    expect(
      badan.containsKey('LogSingkat') || badan.containsKey('LayarAsal') || badan.containsKey('Prioritas'),
      isFalse,
    );
  });

  test('balas tiket: jalur dan badan benar, hasil percakapan terbaru', () async {
    late http.Request dikirim;
    final klien = BuatKlien((p) async {
      dikirim = p;
      return Json({
        'Tiket': TiketUji(
          pesan: [
            {
              'Uuid': 'P1',
              'JenisPengirim': 'Pengguna',
              'NamaPengirim': 'Rina',
              'Isi': 'Sudah dicoba.',
              'DibuatPada': '2026-09-24T04:00:00Z',
            },
          ],
        ),
      }, 200);
    });

    final tiket = await klien.BalasTiketBantuan('01K5TIKET00000000000000001', 'U1', 'Sudah dicoba.');

    expect(dikirim.method, 'POST');
    expect(dikirim.url.path, '/api/pos/v1/bantuan/tiket/01K5TIKET00000000000000001/balas');
    expect(jsonDecode(dikirim.body), {'UuidPengguna': 'U1', 'Isi': 'Sudah dicoba.'});
    expect(tiket.pesan.single.isi, 'Sudah dicoba.');
  });

  test('kategori bantuan: kunci cocok dengan KategoriTiketDukungan server dan label tidak kosong', () {
    expect(KategoriBantuan.values.map((k) => k.kunci), [
      'Perangkat',
      'Penjualan',
      'Persediaan',
      'Laporan',
      'AkunLangganan',
      'Tagihan',
      'Lainnya',
    ]);
    expect(KategoriBantuan.values.every((k) => k.label.isNotEmpty), isTrue);
  });
}
