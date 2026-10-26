import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Katalog/LayananStokTersedia.dart';
import 'package:kasir/Domain/Meja/KonteksPesananMeja.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatStok(String pesan) =>
    throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'StokTidakCukup').having((g) => g.pesan, 'pesan', pesan));

/// F-07 + F-05, BR-05.2: keranjang kasir menolak menambah jumlah produk berstok melebihi sisa stok Toko, tetapi tidak
/// memblokir pengurangan, produk di luar daftar server, dan keranjang yang menagih dokumen yang sudah ada.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late LayananStokTersedia stok;
  late LayananPenjualan penjualan;
  var jawaban = <String, String>{};

  Future<void> AturStok(Map<String, String> produk) async {
    jawaban = produk;
    expect(await stok.Segarkan(), isTrue);
  }

  ProdukJual Produk(String uuid) => katalog.CariProduk(uuid)!;

  SatuanJual Satuan(String nama) => Produk(UuidUji.roti).satuan.firstWhere((s) => s.nama == nama);

  Keranjang Tambah(Keranjang keranjang, String uuidProduk, {int jumlah = 1, String? satuan, String? catatan}) =>
      penjualan.TambahBaris(
        keranjang,
        penjualan.BuatBaris(
          katalog,
          k,
          Produk(uuidProduk),
          satuan: satuan == null ? null : Satuan(satuan),
          jumlah: Kuantitas.DariBulat(jumlah),
          catatan: catatan,
        ),
        katalog,
        k,
      );

  Keranjang TambahRoti(Keranjang keranjang, {int jumlah = 1, String? satuan, String? catatan}) =>
      Tambah(keranjang, UuidUji.roti, jumlah: jumlah, satuan: satuan, catatan: catatan);

  setUp(() async {
    u = LingkunganUji.Buat();
    jawaban = {};
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/stok-tersedia')) {
        return JsonUji({
          'WaktuServer': '2026-09-24T01:00:00Z',
          'Produk': [
            for (final e in jawaban.entries) {'UuidProduk': e.key, 'Tersedia': e.value},
          ],
        });
      }
      throw http.ClientException('offline');
    };
    stok = LayananStokTersedia(
      klien: u.klien,
      repositori: u.repositori,
      repositoriPenjualan: u.repositoriPenjualan,
      jam: () => u.jam,
    );
    penjualan = LayananPenjualan(
      repositori: u.repositori,
      repositoriPenjualan: u.repositoriPenjualan,
      repositoriPelanggan: u.repositoriPelanggan,
      stokTersedia: stok,
      jam: () => u.jam,
    );
  });
  tearDown(() => u.Tutup());

  group('menambah ke keranjang', () {
    test('BR-05.2: menambah sampai batas stok boleh, melebihinya ditolak dengan pesan ramah', () async {
      await AturStok({UuidUji.roti: '3.0000'});

      var keranjang = TambahRoti(Keranjang.kosong, jumlah: 2);
      keranjang = TambahRoti(keranjang);
      expect(keranjang.baris.single.jumlah, Kuantitas.DariBulat(3));

      expect(() => TambahRoti(keranjang), GalatStok('Stok Roti Tawar Gandum hanya 3 Pcs.'));
      expect(keranjang.baris.single.jumlah, Kuantitas.DariBulat(3), reason: 'Keranjang tidak berubah.');
    });

    test('BR-05.2: satu penambahan besar yang melebihi stok ditolak utuh, bukan dipotong', () async {
      await AturStok({UuidUji.roti: '5.0000'});

      expect(() => TambahRoti(Keranjang.kosong, jumlah: 6), GalatStok('Stok Roti Tawar Gandum hanya 5 Pcs.'));
      expect(TambahRoti(Keranjang.kosong, jumlah: 5).baris.single.jumlah, Kuantitas.DariBulat(5));
    });

    test('BR-05.2: stok nol atau negatif dari server: "sudah habis"', () async {
      await AturStok({UuidUji.roti: '0.0000', UuidUji.croissant: '-2.0000'});

      expect(() => TambahRoti(Keranjang.kosong), GalatStok('Stok Roti Tawar Gandum sudah habis.'));
      expect(
        () => Tambah(Keranjang.kosong, UuidUji.croissant),
        GalatStok('Stok Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo sudah habis.'),
      );
    });

    test('BR-05.2: baris terpisah (catatan berbeda) tetap dihitung bersama. Pesan menyebut stok keseluruhan', () async {
      await AturStok({UuidUji.roti: '4.0000'});

      var keranjang = TambahRoti(Keranjang.kosong, jumlah: 3);
      keranjang = TambahRoti(keranjang, catatan: 'dipotong');
      expect(keranjang.baris, hasLength(2));

      expect(
        () => TambahRoti(keranjang, catatan: 'dipanggang'),
        GalatStok('Stok Roti Tawar Gandum hanya 4 Pcs, semuanya sudah ada di keranjang.'),
      );
    });

    test('BR-05.2: satuan jual dikonversi: 1 lusin = 12 pcs dihitung terhadap stok satuan dasar', () async {
      await AturStok({UuidUji.roti: '14.0000'});

      final keranjang = TambahRoti(Keranjang.kosong, satuan: 'Lusin');
      const pesan = 'Stok Roti Tawar Gandum hanya 14 Pcs.';
      expect(() => TambahRoti(keranjang, jumlah: 3, satuan: 'Pcs'), GalatStok(pesan));
      expect(TambahRoti(keranjang, jumlah: 2, satuan: 'Pcs').baris, hasLength(2));
      expect(() => TambahRoti(Keranjang.kosong, jumlah: 2, satuan: 'Lusin'), GalatStok(pesan));
    });

    test('BR-05.2: produk di luar daftar server tanpa batas. Perangkat tanpa salinan tidak membatasi', () async {
      await AturStok({UuidUji.roti: '1.0000'});
      expect(Tambah(Keranjang.kosong, UuidUji.croissant, jumlah: 500).baris.single.jumlah, Kuantitas.DariBulat(500));

      // Penjualan tanpa layanan stok (perangkat belum pernah online / pengaturan lama) tidak membatasi.
      final tanpaStok = u.penjualan;
      final baris = tanpaStok.BuatBaris(katalog, k, Produk(UuidUji.roti), jumlah: Kuantitas.DariBulat(500));
      expect(tanpaStok.TambahBaris(Keranjang.kosong, baris, katalog, k).baris.single.jumlah, Kuantitas.DariBulat(500));

      // Layanan stok ada tetapi belum punya salinan: sama saja.
      final belumAdaSalinan = LayananPenjualan(
        repositori: u.repositori,
        repositoriPenjualan: u.repositoriPenjualan,
        stokTersedia: LayananStokTersedia(
          klien: u.klien,
          repositori: u.repositori,
          repositoriPenjualan: u.repositoriPenjualan,
        ),
      );
      expect(belumAdaSalinan.TambahBaris(Keranjang.kosong, baris, katalog, k).baris, hasLength(1));
    });

    test('BR-05.2: penjualan lokal yang belum tercakup ikut mengurangi batas', () async {
      await AturStok({UuidUji.roti: '5.0000'});
      u.jam = u.jam.add(const Duration(minutes: 1));
      await penjualan.Bayar(
        keranjang: TambahRoti(Keranjang.kosong, jumlah: 3),
        pembayaran: [
          PembayaranMasukan(
            metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
            jumlah: Uang.DariBulat(100000),
          ),
        ],
        kasir: rina,
        k: k,
      );
      await stok.HitungUlangTerjual();

      expect(() => TambahRoti(Keranjang.kosong, jumlah: 3), GalatStok('Stok Roti Tawar Gandum hanya 2 Pcs.'));
      expect(TambahRoti(Keranjang.kosong, jumlah: 2).baris.single.jumlah, Kuantitas.DariBulat(2));
    });
  });

  group('mengubah baris', () {
    test('BR-05.2: UbahJumlah naik melebihi stok ditolak. Turun dan tetap diizinkan walau salinan turun', () async {
      await AturStok({UuidUji.roti: '5.0000'});
      final keranjang = TambahRoti(Keranjang.kosong, jumlah: 4);
      final uuidBaris = keranjang.baris.single.uuid;

      expect(
        () => penjualan.UbahJumlah(keranjang, uuidBaris, Jml(6), katalog, k),
        GalatStok('Stok Roti Tawar Gandum hanya 5 Pcs.'),
      );
      expect(penjualan.UbahJumlah(keranjang, uuidBaris, Jml(5), katalog, k).baris.single.jumlah, Jml(5));

      // Stok di server turun jadi 2 setelah baris masuk (penjualan di perangkat lain): keranjang tetap bisa dirapikan.
      await AturStok({UuidUji.roti: '2.0000'});
      expect(penjualan.UbahJumlah(keranjang, uuidBaris, Jml(4), katalog, k).baris.single.jumlah, Jml(4));
      expect(penjualan.UbahJumlah(keranjang, uuidBaris, Jml(3), katalog, k).baris.single.jumlah, Jml(3));
      expect(
        () => penjualan.UbahJumlah(keranjang, uuidBaris, Jml(5), katalog, k),
        GalatStok('Stok Roti Tawar Gandum hanya 2 Pcs.'),
      );
      expect(penjualan.UbahJumlah(keranjang, uuidBaris, Kuantitas.Nol(), katalog, k).baris, isEmpty);
    });

    test('BR-05.2: UbahJumlah memperhitungkan baris lain produk yang sama, tidak dirinya sendiri', () async {
      await AturStok({UuidUji.roti: '6.0000'});
      var keranjang = TambahRoti(Keranjang.kosong, jumlah: 2, catatan: 'A');
      keranjang = TambahRoti(keranjang, jumlah: 2, catatan: 'B');
      final barisA = keranjang.baris.first.uuid;

      expect(penjualan.UbahJumlah(keranjang, barisA, Jml(4), katalog, k).baris.first.jumlah, Jml(4));
      expect(
        () => penjualan.UbahJumlah(keranjang, barisA, Jml(5), katalog, k),
        GalatStok('Stok Roti Tawar Gandum hanya 6 Pcs.'),
      );
    });

    test('BR-05.2: GantiSatuan ke satuan yang lebih besar ditolak bila melebihi stok', () async {
      await AturStok({UuidUji.roti: '10.0000'});
      final keranjang = TambahRoti(Keranjang.kosong);
      final uuidBaris = keranjang.baris.single.uuid;

      expect(
        () => penjualan.GantiSatuan(keranjang, uuidBaris, Satuan('Lusin'), katalog, k),
        GalatStok('Stok Roti Tawar Gandum hanya 10 Pcs.'),
      );
      expect(
        penjualan.GantiSatuan(keranjang, uuidBaris, Satuan('Pcs'), katalog, k).baris.single.namaSatuan,
        'Pcs',
        reason: 'Satuan yang sama tidak menambah jumlah dasar.',
      );

      await AturStok({UuidUji.roti: '30.0000'});
      final lusin = penjualan.GantiSatuan(keranjang, uuidBaris, Satuan('Lusin'), katalog, k);
      expect(lusin.baris.single.namaSatuan, 'Lusin');
      expect(lusin.baris.single.jumlah, Kuantitas.DariBulat(1));
    });

    test('BR-05.2: GantiSatuan dengan jumlahBaru menilai jumlah akhir, bukan jumlah lama dalam satuan baru', () async {
      await AturStok({UuidUji.roti: '30.0000'});
      var keranjang = TambahRoti(Keranjang.kosong, jumlah: 20);
      final uuidBaris = keranjang.baris.single.uuid;

      // Jumlah lama 20 dalam lusin = 240 pcs: melebihi stok. Panel item mengganti satuan sekaligus jumlah jadi 2 lusin.
      expect(
        () => penjualan.GantiSatuan(keranjang, uuidBaris, Satuan('Lusin'), katalog, k),
        GalatStok('Stok Roti Tawar Gandum hanya 30 Pcs.'),
      );
      keranjang = penjualan.GantiSatuan(keranjang, uuidBaris, Satuan('Lusin'), katalog, k, jumlahBaru: Jml(2));
      expect(keranjang.baris.single.namaSatuan, 'Lusin');
      expect(keranjang.baris.single.jumlah, Kuantitas.DariBulat(2));
    });
  });

  group('tidak memblokir', () {
    test('BR-05.2: keranjang yang menagih pre-order / pesanan online (uang muka) tidak dibatasi', () async {
      await AturStok({UuidUji.roti: '1.0000'});
      final keranjang = Keranjang(
        praPesan: PraPesananKeranjang(
          uuid: '01K5PO0000000000000000001',
          nomor: 'PO/2026/09/0001',
          sisaUangMuka: Uang.DariBulat(50000),
          uuidMetode: '01K5MTD0000000000000000010',
          namaMetode: 'Uang muka (DP)',
        ),
      );

      expect(TambahRoti(keranjang, jumlah: 24).baris.single.jumlah, Kuantitas.DariBulat(24));
      expect(penjualan.CekPencegahStokBerlaku(keranjang), isFalse);
      expect(penjualan.CekPencegahStokBerlaku(Keranjang.kosong), isTrue);
    });

    test('BR-05.2: keranjang reservasi atau perintah kerja tidak dibatasi', () async {
      await AturStok({UuidUji.roti: '1.0000'});
      final reservasi = Keranjang(
        reservasi: const ReservasiKeranjang(uuid: '01K5RS00000000000000000001', nomor: 'RS/1'),
      );
      final kerja = Keranjang(
        perintahKerja: const PerintahKerjaKeranjang(uuid: '01K5PK00000000000000000001', nomor: 'PK/1'),
      );

      expect(TambahRoti(reservasi, jumlah: 5).baris.single.jumlah, Kuantitas.DariBulat(5));
      expect(TambahRoti(kerja, jumlah: 5).baris.single.jumlah, Kuantitas.DariBulat(5));
    });

    test('BR-05.2: pesanan meja (baris baru) dan tukar barang (barang pengganti) tetap dibatasi', () async {
      await AturStok({UuidUji.roti: '1.0000'});
      const meja = KonteksPesananMeja(
        uuid: '01K5PESANAN0000000000000001',
        nomor: 'PM/0001',
        uuidMeja: '01K5MEJA0000000000000D0101',
        namaMeja: 'D-01',
        label: null,
      );
      final penukaran = TukarKeranjang(
        uuidRetur: '01K5RETUR0000000000000001',
        nomorPenjualanAsal: 'INV/SLB/260924/POS-001-0001',
        nilai: Uang.DariBulat(12000),
        uuidMetode: '01K5MTD000000000000T0KAR01',
        namaMetode: 'Tukar barang',
        simpanRetur: ({required Uang tukar, required Uang tunai}) async => 'RET/1',
        penyetuju: PenyetujuTukar(izin: 'penjualan.retur', pesan: 'Retur butuh persetujuan.'),
      );

      for (final keranjang in [Keranjang(pesananMeja: meja), Keranjang(tukar: penukaran)]) {
        expect(penjualan.CekPencegahStokBerlaku(keranjang), isTrue);
        expect(() => TambahRoti(keranjang, jumlah: 2), GalatStok('Stok Roti Tawar Gandum hanya 1 Pcs.'));
        expect(TambahRoti(keranjang).baris.single.jumlah, Kuantitas.DariBulat(1));
      }
    });

    test('BR-05.2: BuatBaris (dipakai pre-order, perintah kerja, pesan sendiri, retur) tidak memeriksa stok', () async {
      await AturStok({UuidUji.roti: '0.0000'});

      final baris = penjualan.BuatBaris(katalog, k, Produk(UuidUji.roti), jumlah: Kuantitas.DariBulat(50));

      expect(baris.jumlah, Kuantitas.DariBulat(50));
    });

    test('BR-05.2: CekStokHabis konsisten dengan penolakan', () async {
      await AturStok({UuidUji.roti: '2.0000'});
      final penuh = TambahRoti(Keranjang.kosong, jumlah: 2);

      expect(penjualan.CekStokHabis(Keranjang.kosong, katalog, UuidUji.roti), isFalse);
      expect(penjualan.CekStokHabis(penuh, katalog, UuidUji.roti), isTrue);
      expect(penjualan.CekStokHabis(penuh, katalog, UuidUji.croissant), isFalse);
      expect(u.penjualan.CekStokHabis(penuh, katalog, UuidUji.roti), isFalse, reason: 'Tanpa layanan stok.');
    });
  });
}

Kuantitas Jml(int n) => Kuantitas.DariBulat(n);
