import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Harga manual (lapis 1 price engine, izin `pos.harga.timpa`): kasir ber-izin, atau penyetuju ber-PIN, mengubah harga
/// satu baris dari harga daftar. Alasan wajib, harga tidak ditentukan ulang saat jumlah berubah, baris tidak digabung,
/// dan `Penjualan.Buat` membawa `HargaManual {HargaAsli, Alasan}` serta `UuidPenyetujuHarga`.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late StafLokal supervisor;

  const penyetujuTerpilih = PenyetujuDiskon(uuid: '01K5USR000000000SUPERV1S01', nama: 'Sari Supervisor', pemilik: false);

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    supervisor = StafLokal(
      uuid: penyetujuTerpilih.uuid,
      nama: penyetujuTerpilih.nama,
      pemilik: false,
      izin: const [IzinKasir.posHargaTimpa],
    );
  });

  tearDown(() => u.Tutup());

  Keranjang IsiCroissant() => u.penjualan.TambahBaris(
    Keranjang.kosong,
    u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
    katalog,
    k,
  );

  Matcher Kode(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

  test('kasir ber-izin mengubah harga: harga baru dipakai, harga asal & alasan tersimpan, tanpa penyetuju', () {
    final keranjang = IsiCroissant();
    final baris = keranjang.baris.single;
    final asal = baris.hargaSatuan;

    final ubah = u.penjualan.UbahHargaItem(
      keranjang,
      baris.uuid,
      Uang.DariBulat(12000),
      '  Kemasan penyok  ',
      kasir: supervisor,
    );

    final item = ubah.baris.single;
    expect(item.hargaSatuan.KeString(), '12000.00');
    expect(item.hargaAsli, asal);
    expect(item.alasanHarga, 'Kemasan penyok');
    expect(item.penyetujuHarga, isNull);
    expect(item.CekHargaDikunci, isTrue);
  });

  test('kasir tanpa izin: butuh penyetuju ber-PIN; dengan penyetuju, penyetuju dicatat di baris', () {
    final keranjang = IsiCroissant();
    final uuid = keranjang.baris.single.uuid;

    expect(
      () => u.penjualan.UbahHargaItem(keranjang, uuid, Uang.DariBulat(12000), 'Tawar menawar', kasir: rina),
      Kode('HargaTanpaIzin'),
    );

    final ubah = u.penjualan.UbahHargaItem(
      keranjang,
      uuid,
      Uang.DariBulat(12000),
      'Tawar menawar',
      kasir: rina,
      penyetuju: penyetujuTerpilih,
    );
    expect(ubah.baris.single.penyetujuHarga?.uuid, penyetujuTerpilih.uuid);
  });

  test('alasan wajib dan maksimal 100 karakter; harga harus lebih dari nol', () {
    final keranjang = IsiCroissant();
    final uuid = keranjang.baris.single.uuid;

    expect(
      () => u.penjualan.UbahHargaItem(keranjang, uuid, Uang.DariBulat(12000), '   ', kasir: supervisor),
      Kode('AlasanHargaWajib'),
    );
    expect(
      () => u.penjualan.UbahHargaItem(keranjang, uuid, Uang.DariBulat(12000), 'x' * 101, kasir: supervisor),
      Kode('AlasanHargaTerlaluPanjang'),
    );
    expect(
      () => u.penjualan.UbahHargaItem(keranjang, uuid, Uang.Nol(), 'Gratis', kasir: supervisor),
      Kode('HargaTidakValid'),
    );
  });

  test('harga manual tetap saat jumlah diubah atau harga dihitung ulang, dan baris tidak digabung', () {
    var keranjang = IsiCroissant();
    final uuid = keranjang.baris.single.uuid;
    keranjang = u.penjualan.UbahHargaItem(keranjang, uuid, Uang.DariBulat(12000), 'Kemasan penyok', kasir: supervisor);

    keranjang = u.penjualan.UbahJumlah(keranjang, uuid, Kuantitas.DariBulat(3), katalog, k);
    keranjang = u.penjualan.HitungUlangHarga(keranjang, katalog, k);
    expect(keranjang.baris.single.hargaSatuan.KeString(), '12000.00');
    expect(keranjang.baris.single.jumlah.KeString(), '3');

    // Croissant yang sama ditambah lagi menjadi baris terpisah (tidak ikut harga manual).
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
      katalog,
      k,
    );
    expect(keranjang.baris, hasLength(2));
    expect(keranjang.baris.first.hargaSatuan.KeString(), '12000.00');
    expect(keranjang.baris.last.hargaAsli, isNull);
  });

  test('kembalikan harga: lewat PulihkanHargaItem atau mengetik harga asal; harga & penanda pulih', () {
    var keranjang = IsiCroissant();
    final uuid = keranjang.baris.single.uuid;
    final asal = keranjang.baris.single.hargaSatuan;
    final diubah = u.penjualan.UbahHargaItem(keranjang, uuid, Uang.DariBulat(12000), 'Kemasan penyok', kasir: supervisor);

    for (final pulih in [
      u.penjualan.PulihkanHargaItem(diubah, uuid),
      u.penjualan.UbahHargaItem(diubah, uuid, asal, '', kasir: rina),
    ]) {
      final item = pulih.baris.single;
      expect(item.hargaSatuan, asal);
      expect(item.hargaAsli, isNull);
      expect(item.alasanHarga, isNull);
      expect(item.penyetujuHarga, isNull);
      expect(item.CekHargaDikunci, isFalse);
    }

    // Mengubah harga dua kali tetap mengingat harga daftar yang asli.
    keranjang = u.penjualan.UbahHargaItem(diubah, uuid, Uang.DariBulat(11000), 'Tawar menawar', kasir: supervisor);
    expect(keranjang.baris.single.hargaAsli, asal);
    expect(keranjang.baris.single.alasanHarga, 'Tawar menawar');
  });

  test('keranjang tersimpan (K-4) memulihkan harga asal, alasan, dan penyetuju', () {
    final isi = IsiCroissant();
    final keranjang = u.penjualan.UbahHargaItem(
      isi,
      isi.baris.single.uuid,
      Uang.DariBulat(12000),
      'Kemasan penyok',
      kasir: rina,
      penyetuju: penyetujuTerpilih,
    );
    expect(keranjang.baris.single.hargaAsli, isNotNull);

    final pulih = Keranjang.DariJson(jsonDecode(jsonEncode(keranjang.KeJson())) as Map<String, Object?>);
    final item = pulih.baris.single;
    expect((item.hargaSatuan.KeString(), item.alasanHarga, item.penyetujuHarga?.uuid), (
      '12000.00',
      'Kemasan penyok',
      penyetujuTerpilih.uuid,
    ));
    expect(item.hargaAsli, keranjang.baris.single.hargaAsli);
  });

  test('bayar: Penjualan.Buat membawa HargaManual & UuidPenyetujuHarga; baris tanpa perubahan tidak membawanya', () async {
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    var keranjang = IsiCroissant();
    final asal = keranjang.baris.single.hargaSatuan;
    keranjang = u.penjualan.UbahHargaItem(
      keranjang,
      keranjang.baris.single.uuid,
      Uang.DariBulat(12000),
      'Kemasan penyok',
      kasir: rina,
      penyetuju: penyetujuTerpilih,
    );
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
      katalog,
      k,
    );

    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(
          metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
          jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir,
        ),
      ],
      kasir: rina,
      k: k,
    );

    final outbox = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == hasil.uuid);
    final data = jsonDecode(outbox.Data) as Map<String, Object?>;
    final baris = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
    expect(baris.first['HargaSatuan'], '12000.00');
    expect(baris.first['HargaManual'], {'HargaAsli': asal.KeString(), 'Alasan': 'Kemasan penyok'});
    expect(baris.last.containsKey('HargaManual'), isFalse);
    expect(data['UuidPenyetujuHarga'], penyetujuTerpilih.uuid);
  });

  test('bayar tanpa harga manual: muatan tidak membawa UuidPenyetujuHarga', () async {
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    final keranjang = IsiCroissant();

    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(
          metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
          jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir,
        ),
      ],
      kasir: rina,
      k: k,
    );

    final outbox = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == hasil.uuid);
    expect((jsonDecode(outbox.Data) as Map<String, Object?>).containsKey('UuidPenyetujuHarga'), isFalse);
  });
}
