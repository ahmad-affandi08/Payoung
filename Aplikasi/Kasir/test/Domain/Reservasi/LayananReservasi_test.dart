import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-07 mode service bagian 2 di perangkat: antrian reservasi dibaca online (offline → `PerluOnline`), check-in butuh
/// izin, layanan + staf + pelanggan dimuat ke keranjang, lalu `Penjualan.Buat` membawa `UuidReservasi`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  Map<String, Object?> Baris({String status = 'Dikonfirmasi', String? uuidProduk}) => {
    'Uuid': '01K5RESERVASI0000000000001',
    'Nomor': 'RS/2026/09/0001',
    'MulaiPada': '2026-09-26T03:00:00Z',
    'SelesaiPada': '2026-09-26T04:00:00Z',
    'NamaPelanggan': 'Ibu Ratna Sari',
    'NoHp': '0813-5555-0077',
    'Pelanggan': {'Uuid': '01K5PELANGGAN0000000000001', 'Nama': 'Ibu Ratna Sari', 'NoHp': '0813****0077'},
    'UuidProduk': uuidProduk ?? UuidUji.americano,
    'NamaLayanan': 'Americano Panas',
    'UuidStaf': '01K5KARYAWAN00000000000001',
    'NamaStaf': 'Maya',
    'Status': status,
    'LabelStatus': status,
    'Catatan': null,
  };

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('offline ditolak; check-in lalu muat: staf & pelanggan ikut. Bayar membawa UuidReservasi', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(u.reservasi.Ambil(), throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')));

    u.server.penangan = (p) async => p.method == 'GET'
        ? Json({
            'Reservasi': [Baris()],
          }, 200)
        : Json({'Reservasi': Baris(status: 'Hadir')}, 200);
    final daftar = await u.reservasi.Ambil();
    final hadir = await u.reservasi.Hadir(daftar.single, kasir: rina);
    expect(u.server.permintaan.last.url.path, endsWith('/reservasi/01K5RESERVASI0000000000001/hadir'));
    expect(jsonDecode(u.server.permintaan.last.body), {'UuidPengguna': rina.uuid});

    final keranjang = u.reservasi.MuatKeKeranjang(hadir, katalog, k);
    expect(keranjang.baris.single.staf, ['01K5KARYAWAN00000000000001']);
    expect(keranjang.pelanggan?.nama, 'Ibu Ratna Sari');
    expect(Keranjang.DariJson(keranjang.KeJson()).reservasi?.nomor, 'RS/2026/09/0001');

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: total)],
      kasir: rina,
      k: k,
    );
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
    expect(data['UuidReservasi'], '01K5RESERVASI0000000000001');
    expect(data['UuidPelanggan'], '01K5PELANGGAN0000000000001');
  });

  test('reservasi selesai, layanan tidak di katalog, dan kasir tanpa izin ditolak', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    expect(
      () => u.reservasi.MuatKeKeranjang(ReservasiPos.DariJson(Baris(status: 'Selesai')), katalog, k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ReservasiSelesai')),
    );
    expect(
      () => u.reservasi.MuatKeKeranjang(
        ReservasiPos.DariJson(Baris(uuidProduk: '01K5TIDAKADA00000000000001')),
        katalog,
        k,
      ),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ProdukTidakDikenal')),
    );
    const tamu = StafLokal(uuid: '01K5TAMU000000000000000001', nama: 'Dodi', pemilik: false, izin: []);
    expect(
      () => u.reservasi.Hadir(ReservasiPos.DariJson(Baris()), kasir: tamu),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
    );
  });
}
