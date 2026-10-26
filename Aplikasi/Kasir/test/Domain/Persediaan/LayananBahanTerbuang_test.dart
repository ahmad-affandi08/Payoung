import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Persediaan/LayananBahanTerbuang.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-05f bagian 2 (INV-10 F&B) di perangkat: pilihan produk yang bisa dicatat terbuang, konversi satuan ke satuan
/// dasar, validasi jumlah & izin, dan pencatatan offline = baris lokal + item outbox `BahanTerbuang.Catat` dalam satu
/// transaksi, dengan status kirim yang tetap terbaca setelah item outbox terkirim.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late StafLokal budi;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    budi = await u.Staf('Budi Santoso');
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  Future<Map<String, Object?>> AmbilOutbox(String uuid) async {
    final baris = (await u.db.select(u.db.outbox).get()).singleWhere((o) => o.Uuid == uuid);
    expect(baris.Jenis, 'BahanTerbuang.Catat');
    return jsonDecode(baris.Data) as Map<String, Object?>;
  }

  test('hanya produk berstok/beresep tanpa batch/seri yang ditawarkan. Bahan baku ikut walau tidak tampil di POS', () {
    final semua = LayananBahanTerbuang.CariProduk(katalog, '').map((p) => p.uuid).toSet();
    expect(
      semua,
      containsAll([UuidUji.kopiSusu, UuidUji.americano, UuidUji.croissant, UuidUji.roti, UuidUji.gulaAren]),
    );
    // Induk varian dan produk berpelacakan batch ditolak server, jadi tidak ditawarkan.
    expect(semua, isNot(contains(UuidUji.kaos)));
    expect(semua, isNot(contains(UuidUji.susuUht)));
    expect(LayananBahanTerbuang.CariProduk(katalog, 'gula').single.uuid, UuidUji.gulaAren);
    expect(LayananBahanTerbuang.CariProduk(katalog, 'rtg').single.uuid, UuidUji.roti);
    // Barcode persis (dari pemindai).
    expect(LayananBahanTerbuang.CariProduk(katalog, UuidUji.barcodeKopiSusu).first.uuid, UuidUji.kopiSusu);
    expect(LayananBahanTerbuang.CariProduk(katalog, 'tidak ada'), isEmpty);

    // Bahan baku tanpa baris ProdukSatuan tetap bisa dicatat dengan satuan dasarnya; roti bawaan satuan dasar (Pcs).
    final gula = katalog.CariProduk(UuidUji.gulaAren)!;
    expect(LayananBahanTerbuang.AmbilPilihanSatuan(gula).map((s) => (s.nama, s.konversiKeDasar)), [('Pcs', '1')]);
    expect(LayananBahanTerbuang.AmbilSatuanBawaan(gula)?.nama, 'Pcs');
    final roti = katalog.CariProduk(UuidUji.roti)!;
    expect(LayananBahanTerbuang.AmbilPilihanSatuan(roti).map((s) => s.nama), ['Pcs', 'Lusin']);
    expect(LayananBahanTerbuang.AmbilSatuanBawaan(roti)?.nama, 'Pcs');
  });

  test(
    'jumlah dibaca dengan koma desimal. Kosong, bukan angka, nol, >4 desimal, dan desimal di satuan bulat ditolak',
    () {
      final roti = katalog.CariProduk(UuidUji.roti)!;
      final pcs = roti.satuan.firstWhere((s) => s.nama == 'Pcs');
      const kg = SatuanJual(
        uuid: 'SAT-KG',
        nama: 'Kilogram',
        konversiKeDasar: '1',
        defaultJual: true,
        bolehDesimal: true,
      );

      expect(LayananBahanTerbuang.BacaJumlah('0,25', kg), Kuantitas.Dari('0.25'));
      expect(LayananBahanTerbuang.BacaJumlah(' 3 ', pcs), Kuantitas.DariBulat(3));
      for (final (teks, satuan) in [('', pcs), ('dua', pcs), ('0', pcs), ('0,00001', kg), ('1,5', pcs), ('-2', pcs)]) {
        expect(
          () => LayananBahanTerbuang.BacaJumlah(teks, satuan),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', startsWith('Jumlah'))),
          reason: '"$teks" harus ditolak',
        );
      }
    },
  );

  test('catat offline: baris lokal + outbox satuan dasar. Satuan lusin dikonversi ×12. Catatan kosong tidak dikirim', () async {
    final roti = katalog.CariProduk(UuidUji.roti)!;
    final lusin = roti.satuan.firstWhere((s) => s.nama == 'Lusin');

    final uuid = await u.bahanTerbuang.Catat(
      produk: roti,
      satuan: lusin,
      jumlah: Kuantitas.DariBulat(2),
      alasan: AlasanBahanTerbuang.Kedaluwarsa,
      pencatat: budi,
      tanggalBisnis: '2026-09-24',
      catatan: '   ',
    );

    final data = await AmbilOutbox(uuid);
    expect(data, {
      'UuidProduk': UuidUji.roti,
      'Jumlah': '24.0000',
      'Alasan': 'Kedaluwarsa',
      'UuidPengguna': budi.uuid,
      'DibuatPada': '2026-09-24T01:00:00.000Z',
    });
    final lokal = (await u.db.select(u.db.bahanTerbuangLokal).get()).single;
    expect(lokal.Uuid, uuid);
    expect((lokal.Jumlah, lokal.NamaSatuan, lokal.JumlahDasar), ('2', 'Lusin', '24.0000'));
    expect((lokal.NamaPengguna, lokal.Catatan, lokal.TanggalBisnis), ('Budi Santoso', null, '2026-09-24'));
    // Uuid ULID dengan waktu pembuatan = jam perangkat (dipakai server untuk pemulihan perangkat dicabut, audit F-01).
    expect(uuid.length, 26);
  });

  test('status kirim: belum terkirim → perlu tindakan (pesan server) → terkirim setelah outbox dihapus', () async {
    final croissant = katalog.CariProduk(UuidUji.croissant)!;
    final uuid = await u.bahanTerbuang.Catat(
      produk: croissant,
      satuan: croissant.satuan.single,
      jumlah: Kuantitas.DariBulat(3),
      alasan: AlasanBahanTerbuang.Rusak,
      pencatat: budi,
      tanggalBisnis: '2026-09-24',
      catatan: 'Jatuh saat dipajang',
    );

    Future<RiwayatSingkat> Baca() async {
      final daftar = await u.repositoriPersediaan.PantauBahanTerbuang('2026-09-24').first;
      return (status: daftar.single.status, pesan: daftar.single.pesanGalat, catatan: daftar.single.baris.Catatan);
    }

    expect(await Baca(), (status: StatusSinkronPenjualan.BelumTerkirim, pesan: null, catatan: 'Jatuh saat dipajang'));
    await u.repositori.TandaiPerluTindakan(uuid, 'ProdukTanpaStok', 'Croissant tidak punya stok atau resep.');
    expect((await Baca()).status, StatusSinkronPenjualan.PerluTindakan);
    expect((await Baca()).pesan, 'Croissant tidak punya stok atau resep.');
    await u.repositori.HapusOutbox([uuid]);
    expect((await Baca()).status, StatusSinkronPenjualan.Terkirim);
    // Tanggal bisnis lain tidak ikut.
    expect(await u.repositoriPersediaan.PantauBahanTerbuang('2026-09-25').first, isEmpty);
  });

  test('tanpa izin, produk non-stok, satuan asing, dan catatan > 255 ditolak tanpa menulis apa pun', () async {
    final croissant = katalog.CariProduk(UuidUji.croissant)!;
    final kaos = katalog.CariProduk(UuidUji.kaos)!;
    final roti = katalog.CariProduk(UuidUji.roti)!;

    Future<String> Coba({
      StafLokal? pencatat,
      ProdukJual? produk,
      SatuanJual? satuan,
      Kuantitas? jumlah,
      String? catatan,
    }) async {
      try {
        await u.bahanTerbuang.Catat(
          produk: produk ?? croissant,
          satuan: satuan ?? (produk ?? croissant).satuan.first,
          jumlah: jumlah ?? Kuantitas.DariBulat(1),
          alasan: AlasanBahanTerbuang.Lainnya,
          pencatat: pencatat ?? budi,
          tanggalBisnis: '2026-09-24',
          catatan: catatan,
        );
        return 'Tersimpan';
      } on GalatKasir catch (galat) {
        return galat.kode;
      }
    }

    expect(await Coba(pencatat: rina), 'TanpaIzin');
    expect(await Coba(produk: kaos), 'ProdukTidakBisaDicatat');
    expect(await Coba(satuan: roti.satuan.first), 'SatuanTidakValid');
    expect(await Coba(jumlah: Kuantitas.Nol()), 'JumlahTidakValid');
    expect(await Coba(catatan: 'x' * 256), 'CatatanTerlaluPanjang');
    expect(await u.db.select(u.db.outbox).get(), isEmpty);
    expect(await u.db.select(u.db.bahanTerbuangLokal).get(), isEmpty);

    // Pemilik selalu boleh.
    const pemilik = StafLokal(uuid: '01K5STAF0000000000000PEM01', nama: 'Pak Harto', pemilik: true, izin: []);
    expect(await Coba(pencatat: pemilik), 'Tersimpan');
  });

  test('catatan lokal lewat 30 hari yang sudah terkirim dibuang saat mencatat, yang belum terkirim disimpan', () async {
    final croissant = katalog.CariProduk(UuidUji.croissant)!;
    Future<String> Catat() => u.bahanTerbuang.Catat(
      produk: croissant,
      satuan: croissant.satuan.single,
      jumlah: Kuantitas.DariBulat(1),
      alasan: AlasanBahanTerbuang.TidakTerjual,
      pencatat: budi,
      tanggalBisnis: '2026-09-24',
    );

    final terkirim = await Catat();
    final tertunda = await Catat();
    await u.repositori.HapusOutbox([terkirim]);
    u.jam = u.jam.add(const Duration(days: 31));
    final baru = await Catat();

    final sisa = (await u.db.select(u.db.bahanTerbuangLokal).get()).map((b) => b.Uuid).toSet();
    expect(sisa, {tertunda, baru});
  });
}

typedef RiwayatSingkat = ({StatusSinkronPenjualan status, String? pesan, String? catatan});
