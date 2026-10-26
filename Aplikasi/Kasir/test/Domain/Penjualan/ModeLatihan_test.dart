import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
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

/// K-23 (POS-18): mode latihan menghitung & memvalidasi seperti biasa tetapi tidak menyimpan apa pun.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  BarisMetodePembayaran Metode(String jenis) => k.metodePembayaran.firstWhere((m) => m.Jenis == jenis);

  Keranjang Croissant() => u.penjualan.TambahBaris(
    Keranjang.kosong,
    u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
    katalog,
    k,
  );

  test('bayar latihan: kembalian dihitung, nomor LATIHAN, tanpa penjualan/outbox. Validasi tetap jalan', () async {
    final outboxAwal = (await u.db.select(u.db.outbox).get()).length;
    final hasil = await u.penjualan.Bayar(
      keranjang: Croissant(),
      pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000))],
      kasir: rina,
      k: k,
      latihan: true,
    );
    expect(hasil.latihan, isTrue);
    expect(hasil.nomor, startsWith('LATIHAN-'));
    expect(hasil.kembalian.Bandingkan(Uang.Nol()) > 0, isTrue);
    expect(await u.db.select(u.db.penjualan).get(), isEmpty);
    expect(await u.db.select(u.db.outbox).get(), hasLength(outboxAwal));

    await expectLater(
      u.penjualan.Bayar(
        keranjang: Croissant(),
        pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(1000))],
        kasir: rina,
        k: k,
        latihan: true,
      ),
      GalatDengan('PembayaranKurang'),
    );
    await expectLater(
      u.penjualan.Bayar(
        keranjang: Croissant(),
        pembayaran: [
          PembayaranMasukan(
            metode: Metode('Tunai').copyWith(Jenis: 'QrisDinamis', Nama: 'QRIS Dinamis'),
            jumlah: hasil.totalAkhir,
          ),
        ],
        kasir: rina,
        k: k,
        latihan: true,
      ),
      GalatDengan('TidakUntukLatihan'),
    );
  });
}
