import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// F-05g/F-05h di perangkat: produk ber-batch dijual tanpa pilihan batch (server memilih FEFO), produk bernomor seri
/// wajib membawa satu nomor per unit, jumlah mengikuti banyaknya nomor, dan nomor ikut terkirim di `Penjualan.Buat`.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog(KatalogPonselUji());
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('produk ber-batch kini bisa dijual (batch dipilih server, FEFO). Produk bernomor seri ditandai', () {
    final susu = katalog.CariProduk(UuidUji.susuUht)!;
    expect(susu.pelacakan, 'Batch');
    expect(susu.AmbilAlasanTidakBisaDijual(), isNull);
    expect(susu.bernomorSeri, isFalse);

    final hp = katalog.CariProduk(ponsel)!;
    expect(hp.bernomorSeri, isTrue);
    expect(hp.AmbilAlasanTidakBisaDijual(), isNull);
    expect(u.penjualan.BuatBaris(katalog, k, susu).jumlah, Kuantitas.DariBulat(1));
  });

  test('jumlah baris = banyaknya nomor seri. Jumlah tidak bisa diubah terpisah. Baris dengan nomor tidak digabung', () {
    final hp = katalog.CariProduk(ponsel)!;
    final baris = u.penjualan.BuatBaris(katalog, k, hp, nomorSeri: ['IMEI-0001', 'IMEI-0002']);
    expect(baris.jumlah, Kuantitas.DariBulat(2));
    expect(baris.nomorSeri, ['IMEI-0001', 'IMEI-0002']);

    var keranjang = u.penjualan.TambahBaris(Keranjang.kosong, baris, katalog, k);
    expect(
      () => u.penjualan.UbahJumlah(keranjang, baris.uuid, Kuantitas.DariBulat(3), katalog, k),
      GalatDengan('NomorSeriTidakSesuai'),
    );

    final lain = u.penjualan.BuatBaris(katalog, k, hp, nomorSeri: ['IMEI-0003']);
    keranjang = u.penjualan.TambahBaris(keranjang, lain, katalog, k);
    expect(keranjang.baris, hasLength(2));

    keranjang = u.penjualan.AturNomorSeri(keranjang, baris.uuid, ['IMEI-0001', 'IMEI-0002', 'IMEI-0004'], katalog, k);
    expect(keranjang.baris.first.jumlah, Kuantitas.DariBulat(3));
    expect(keranjang.baris.first.nomorSeri, hasLength(3));

    keranjang = u.penjualan.AturNomorSeri(keranjang, baris.uuid, const [], katalog, k);
    expect(keranjang.baris.map((b) => b.uuid), [lain.uuid]);
  });

  test(
    'ValidasiNomorSeri: jumlah nomor harus sama dengan jumlah unit dan nomor tidak ganda (tanpa membedakan huruf)',
    () {
      final hp = katalog.CariProduk(ponsel)!;
      final lengkap = u.penjualan.BuatBaris(katalog, k, hp, nomorSeri: ['IMEI-0001']);
      LayananPenjualan.ValidasiNomorSeri(Keranjang(baris: [lengkap]), katalog);

      final tanpaNomor = u.penjualan.BuatBaris(katalog, k, hp);
      expect(
        () => LayananPenjualan.ValidasiNomorSeri(Keranjang(baris: [tanpaNomor]), katalog),
        GalatDengan('NomorSeriTidakSesuai'),
      );

      final ganda = u.penjualan.BuatBaris(katalog, k, hp, nomorSeri: ['imei-0001']);
      expect(
        () => LayananPenjualan.ValidasiNomorSeri(Keranjang(baris: [lengkap, ganda]), katalog),
        GalatDengan('NomorSeriGanda'),
      );
    },
  );

  test(
    'bayar mengirim Baris[].NomorSeri di outbox. Baris tanpa nomor seri tidak membawa kuncinya; Keranjang JSON utuh',
    () async {
      final hp = katalog.CariProduk(ponsel)!;
      final susu = katalog.CariProduk(UuidUji.susuUht)!;
      var keranjang = u.penjualan.TambahBaris(
        Keranjang.kosong,
        u.penjualan.BuatBaris(katalog, k, hp, nomorSeri: ['IMEI-0001', 'IMEI-0002']),
        katalog,
        k,
      );
      keranjang = u.penjualan.TambahBaris(keranjang, u.penjualan.BuatBaris(katalog, k, susu), katalog, k);

      final pulih = Keranjang.DariJson(keranjang.KeJson());
      expect(pulih.baris.first.nomorSeri, ['IMEI-0001', 'IMEI-0002']);

      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(15000000))],
        kasir: rina,
        k: k,
        katalog: katalog,
      );
      final baris = (await u.db.select(u.db.outbox).get()).firstWhere((o) => o.Uuid == hasil.uuid);
      final data = jsonDecode(baris.Data) as Map<String, Object?>;
      final barisData = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
      expect(barisData.first['NomorSeri'], ['IMEI-0001', 'IMEI-0002']);
      expect(barisData.last.containsKey('NomorSeri'), isFalse);

      // Nomor kurang dari jumlah ditolak sebelum tersimpan.
      final rusak = keranjang.Salin(
        baris: [
          keranjang.baris.first.Salin(nomorSeri: ['IMEI-0001']),
          keranjang.baris.last,
        ],
      );
      await expectLater(
        u.penjualan.Bayar(
          keranjang: rusak,
          pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(15000000))],
          kasir: rina,
          k: k,
          katalog: katalog,
        ),
        GalatDengan('NomorSeriTidakSesuai'),
      );
    },
  );
}
