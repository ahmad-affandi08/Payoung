import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/LayananReservasi.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// K-20: rentang kosong per staf (jam kerja − reservasi aktif) dan validasi booking dari kasir sebelum ke server.
void main() {
  ReservasiPos R(String mulai, String selesai, {String staf = 'S1', String status = 'Dikonfirmasi'}) => ReservasiPos(
    uuid: 'R',
    nomor: 'RS/1',
    mulaiPada: DateTime.parse(mulai),
    selesaiPada: DateTime.parse(selesai),
    namaPelanggan: 'Rina',
    noHp: '0812',
    pelanggan: null,
    uuidProduk: null,
    namaLayanan: 'Creambath',
    uuidStaf: staf,
    namaStaf: 'Maya',
    status: status,
    labelStatus: status,
    catatan: null,
  );
  const maya = StafReservasiPos(uuid: 'S1', nama: 'Maya', jamMulai: '09:00', jamSelesai: '17:00');

  test('rentang kosong mengabaikan reservasi batal/tidak datang & staf lain. Tumpang tindih digabung', () {
    final kosong = LayananReservasi.HitungKosong(maya, [
      R('2026-10-13T03:00:00Z', '2026-10-13T04:00:00Z'), // 10:00–11:00 WIB
      R('2026-10-13T03:30:00Z', '2026-10-13T04:30:00Z'), // 10:30–11:30 WIB (tumpang tindih)
      R('2026-10-13T06:00:00Z', '2026-10-13T07:00:00Z', status: 'Batal'),
      R('2026-10-13T07:00:00Z', '2026-10-13T08:00:00Z', staf: 'S2'),
      R('2026-10-13T09:00:00Z', '2026-10-13T10:00:00Z'), // 16:00–17:00 WIB
    ], 'Asia/Jakarta');
    expect(kosong.map((k) => '${k.mulai}–${k.selesai}'), ['09:00–10:00', '11:30–16:00']);
    expect(LayananReservasi.HitungKosong(maya, const [], 'Asia/Makassar').single, (mulai: '09:00', selesai: '17:00'));
  });

  test('booking ditolak tanpa izin, tanpa nama, atau HP terlalu pendek. Offline = PerluOnline', () async {
    final u = LingkunganUji.Buat();
    addTearDown(u.Tutup);
    await u.SiapkanAktif();
    u.server.penangan = (_) async => throw http.ClientException('offline');
    final rina = await u.Staf('Rina Wulandari');
    Future<ReservasiPos> Buat({String nama = 'Dina', String hp = '081299991234'}) => u.reservasi.Buat(
      kasir: rina,
      uuidLayanan: 'L1',
      tanggal: '2026-09-24',
      jam: '11:00',
      namaPelanggan: nama,
      noHp: hp,
    );

    expect(() => Buat(nama: '  '), GalatDengan('NamaWajib'));
    expect(() => Buat(hp: '0812-34'), GalatDengan('NoHpTidakValid'));
    await expectLater(Buat(), GalatDengan('PerluOnline'));
  });
}
