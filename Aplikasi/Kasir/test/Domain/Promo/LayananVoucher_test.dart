import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananVoucher.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Rincian F-16c bagian 2 di perangkat: voucher wajib online (dipesan untuk Uuid penjualan yang akan dibuat), promo
/// voucher dihitung `MesinPromo` hanya dengan voucher itu, dan `Penjualan.Buat` membawa kode voucher + Uuid yang sama.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  late LayananVoucher layanan;

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  Map<String, Object?> PromoVoucher() => {
    'Uuid': '01K5PROMO00000000000000009',
    'Kode': 'VCR-HEMAT',
    'Nama': 'Voucher hemat Rp 10.000',
    'Prioritas': 0,
    'Eksklusif': false,
    'MulaiPada': null,
    'SelesaiPada': null,
    'KuotaTersisa': null,
    'Definisi': {
      'WajibVoucher': true,
      'Aksi': {'Jenis': 'DiskonTetapPesanan', 'Jumlah': '10000'},
    },
  };

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    layanan = LayananVoucher(klien: u.klien);
  });
  tearDown(() => u.Tutup());

  test('offline → PerluOnline. Ditolak server → pesan server. Kode kosong ditolak tanpa permintaan', () async {
    await expectLater(
      layanan.Pesan('  ', Keranjang.kosong),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'KodeVoucherWajib')),
    );
    expect(u.server.permintaan, isEmpty);

    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(
      layanan.Pesan('HEMAT10K', Keranjang.kosong),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
    );

    u.server.penangan = (p) async => Json({
      'Galat': {'Kode': 'VoucherHabis', 'Pesan': 'Voucher HEMAT10K sudah habis dipakai.'},
    }, 409);
    await expectLater(
      layanan.Pesan('HEMAT10K', Keranjang.kosong),
      throwsA(
        isA<GalatKasir>()
            .having((g) => g.kode, 'kode', 'VoucherHabis')
            .having((g) => g.pesan, 'pesan', 'Voucher HEMAT10K sudah habis dipakai.'),
      ),
    );
  });

  test('voucher dipesan → promo voucher berlaku, tanpa voucher tidak; Penjualan.Buat membawa Voucher & Uuid', () async {
    await u.SiapkanKatalog();
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    final keranjang = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
      katalog,
      k,
    );
    expect(u.penjualan.Hitung(keranjang, k).promoTerpakai, isEmpty);

    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/voucher/lepas')) {
        return http.Response('', 204);
      }
      final isi = jsonDecode(p.body) as Map<String, Object?>;
      return Json({
        'Voucher': {
          'Kode': (isi['Kode']! as String).toUpperCase(),
          'UuidPromo': '01K5PROMO00000000000000009',
          'DipesanSampai': '2026-10-05T06:00:00Z',
          'SisaPakai': 0,
        },
        'Promo': PromoVoucher(),
      }, 200);
    };
    final voucher = await layanan.Pesan(' hemat10k ', keranjang);
    final kirim = jsonDecode(u.server.permintaan.single.body) as Map<String, Object?>;
    expect(kirim['Kode'], 'HEMAT10K');
    expect(kirim['UuidPenjualan'], voucher.uuidPenjualan);
    expect(voucher.kode, 'HEMAT10K');

    // Kode lain untuk keranjang yang sama: Uuid penjualan tetap, voucher lama dilepas.
    final ganti = await layanan.Pesan('HEMAT20K', keranjang.Salin(voucher: () => voucher));
    expect(ganti.uuidPenjualan, voucher.uuidPenjualan);
    expect(u.server.permintaan.last.url.path, endsWith('/voucher/lepas'));

    final bervoucher = keranjang.Salin(voucher: () => voucher);
    expect(Keranjang.DariJson(bervoucher.KeJson()).voucher?.kode, 'HEMAT10K');
    final hitungan = u.penjualan.Hitung(bervoucher, k);
    final promo = hitungan.promoTerpakai.single;
    expect(promo.kode, 'VCR-HEMAT');
    expect(promo.diskonPesanan, Uang.Dari('10000.00'));
    expect(hitungan.AmbilNamaPromo(promo), 'Voucher hemat Rp 10.000');

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final hasil = await u.penjualan.Bayar(
      keranjang: bervoucher,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
      kasir: rina,
      k: k,
    );
    expect(hasil.uuid, voucher.uuidPenjualan);
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.last.Uuid, voucher.uuidPenjualan);
    final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
    expect(data['Voucher'], 'HEMAT10K');
    expect((data['Promo']! as List<Object?>).single, containsPair('UuidPromo', '01K5PROMO00000000000000009'));
  });
}
