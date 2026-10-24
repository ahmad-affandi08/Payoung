import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Perangkat/PenentuLokasi.dart';
import 'package:kasir/Domain/Salesman/LayananSalesman.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Tampilan/Jual/PanelPesananGrosir.dart';
import 'package:kasir/Tampilan/RuangKerja/ItemNavigasi.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';
import '../../Pendukung/SalesmanUji.dart';

/// Pola server yang harus dipenuhi item outbox (`ValidasiItemSinkron::POLA_WAKTU`,
/// `PenanganSinkronCatatKunjungan::POLA_LATITUDE/LONGITUDE`, jumlah `PenanganSinkronBuatPesananGrosir`).
final RegExp polaWaktu = RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$');
final RegExp polaLatitude = RegExp(r'^-?(90(\.0{1,7})?|[1-8]?\d(\.\d{1,7})?)$');
final RegExp polaLongitude = RegExp(r'^-?(180(\.0{1,7})?|(1[0-7]\d|[1-9]?\d)(\.\d{1,7})?)$');
final RegExp polaJumlah = RegExp(r'^\d{1,14}(\.\d{1,4})?$');

/// Modul Salesman bagian 2 (§9.7, SLS-11): logika domain aplikasi salesman — perkiraan Decimal, format koordinat,
/// bentuk item outbox persis server, urutan pesanan sebelum kunjungan, cache pelanggan offline, dan izin.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late StafLokal dewi;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif(dataAwal: DataAwalSalesmanUji());
    await u.SiapkanKatalog(KatalogTimbanganUji());
    katalog = await u.MuatKatalog();
    dewi = await u.Staf('Dewi Kartika Sari');
  });
  tearDown(() => u.Tutup());

  ProdukJual Produk(String uuid) => katalog.CariProduk(uuid)!;
  SatuanJual Satuan(String uuidProduk, String uuidSatuan) =>
      Produk(uuidProduk).satuan.firstWhere((s) => s.uuid == uuidSatuan);

  PelangganSalesman Toko() =>
      PelangganSalesman.DariBaris(_Baris(PelangganSalesmanPos.DariJson(PelangganSalesmanUji().first)));

  group('koordinat lokasi (string desimal di batas platform)', () {
    test('7 desimal persis, rentang & pola server terpenuhi, akurasi dibulatkan ke meter', () {
      final lokasi = LokasiPerangkat.DariPlatform(-7.56660012345, 110.81660024, akurasi: 12.4)!;
      expect((lokasi.latitude, lokasi.longitude, lokasi.akurasiMeter), ('-7.5666001', '110.8166002', 12));
      expect(polaLatitude.hasMatch(lokasi.latitude), isTrue);
      expect(polaLongitude.hasMatch(lokasi.longitude), isTrue);

      final ujung = LokasiPerangkat.DariPlatform(90, -180)!;
      expect((ujung.latitude, ujung.longitude, ujung.akurasiMeter), ('90.0000000', '-180.0000000', null));
      expect(polaLatitude.hasMatch(ujung.latitude) && polaLongitude.hasMatch(ujung.longitude), isTrue);
    });

    test('nol negatif dirapikan; NaN, tak hingga, dan di luar rentang = lokasi tidak tersedia', () {
      expect(LokasiPerangkat.FormatKoordinat(-0.00000001, batas: 90), '0.0000000');
      expect(LokasiPerangkat.DariPlatform(double.nan, 110), isNull);
      expect(LokasiPerangkat.DariPlatform(-7.5, double.infinity), isNull);
      expect(LokasiPerangkat.DariPlatform(90.5, 110), isNull);
      expect(LokasiPerangkat.DariPlatform(-7.5, 180.01), isNull);
    });

    test('akurasi negatif/NaN diabaikan, akurasi sangat besar dibatasi 100.000 m (batas server)', () {
      expect(LokasiPerangkat.DariPlatform(-7.5, 110.8, akurasi: -1)!.akurasiMeter, isNull);
      expect(LokasiPerangkat.DariPlatform(-7.5, 110.8, akurasi: double.nan)!.akurasiMeter, isNull);
      expect(LokasiPerangkat.DariPlatform(-7.5, 110.8, akurasi: 250000)!.akurasiMeter, 100000);
    });
  });

  group('jumlah & produk pesanan', () {
    test('jumlah dibaca sebagai Kuantitas: koma desimal, bulat untuk satuan bulat, maks. 4 desimal & 14 digit', () {
      final pcs = Satuan(UuidUji.roti, UuidUji.psRotiPcs);
      final kg = Satuan(jerukMedan, psJeruk);
      expect(LayananSalesman.BacaJumlah('12', pcs), Kuantitas.DariBulat(12));
      expect(LayananSalesman.BacaJumlah(' 2,5 ', kg).KeString(), '2.5000');
      expect(LayananSalesman.BacaJumlah('0.1235', kg).KeString(), '0.1235');
      for (final (teks, satuan, kode) in [
        ('', pcs, 'JumlahKosong'),
        ('abc', pcs, 'JumlahTidakValid'),
        ('0', pcs, 'JumlahTidakValid'),
        ('-2', pcs, 'JumlahTidakValid'),
        ('2,5', pcs, 'JumlahTidakValid'),
        ('1,12345', kg, 'JumlahTidakValid'),
        ('123456789012345', pcs, 'JumlahTidakValid'),
      ]) {
        expect(
          () => LayananSalesman.BacaJumlah(teks, satuan),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode)),
          reason: '"$teks" pada ${satuan.nama}',
        );
      }
    });

    test('hanya produk berstok biasa (jenis Stok, tanpa batch/seri) yang bisa dipesan; cari nama, SKU, barcode', () {
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.roti)), isTrue);
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.croissant)), isTrue);
      expect(LayananSalesman.CekBolehDipesan(Produk(jerukMedan)), isTrue);
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.susuUht)), isFalse, reason: 'Ber-batch');
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.kopiSusu)), isFalse, reason: 'Resep');
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.kaos)), isFalse, reason: 'Induk varian');
      expect(LayananSalesman.CekBolehDipesan(Produk(UuidUji.gulaAren)), isFalse, reason: 'Bahan baku');

      expect(LayananSalesman.CariProduk(katalog, '').map((p) => p.uuid).toSet(), {
        UuidUji.croissant,
        UuidUji.roti,
        jerukMedan,
      });
      expect(LayananSalesman.CariProduk(katalog, 'rtg').single.uuid, UuidUji.roti);
      expect(LayananSalesman.CariProduk(katalog, UuidUji.barcodeRotiLusin).first.uuid, UuidUji.roti);
      expect(LayananSalesman.CariProduk(katalog, 'UHT'), isEmpty, reason: 'Susu UHT ber-batch difilter.');
      final barcode = katalog.CariKode(UuidUji.barcodeRotiLusin)!;
      expect(LayananSalesman.AmbilSatuanBawaan(barcode.produk, dariBarcode: barcode.satuan).uuid, UuidUji.psRotiLusin);
      expect(LayananSalesman.AmbilSatuanBawaan(Produk(UuidUji.roti)).uuid, UuidUji.psRotiPcs);
    });
  });

  group('perkiraan total (Decimal, harga katalog lokal)', () {
    test('harga bertingkat, multi-satuan, & jumlah desimal dijumlah tanpa pecahan biner', () {
      final baris = [
        // 12 pcs roti: harga bertingkat minimum 10 → Rp 11.000.
        BarisPesananSalesman(
          produk: Produk(UuidUji.roti),
          satuan: Satuan(UuidUji.roti, UuidUji.psRotiPcs),
          jumlah: Kuantitas.DariBulat(12),
        ),
        BarisPesananSalesman(
          produk: Produk(UuidUji.roti),
          satuan: Satuan(UuidUji.roti, UuidUji.psRotiLusin),
          jumlah: Kuantitas.DariBulat(2),
        ),
        BarisPesananSalesman(
          produk: Produk(jerukMedan),
          satuan: Satuan(jerukMedan, psJeruk),
          jumlah: Kuantitas.Dari('2.3750'),
        ),
      ];
      final perkiraan = LayananSalesman.HitungPerkiraan(baris, katalog, waktu: u.jam);

      expect(perkiraan.hargaSatuan, [Uang.DariBulat(11000), Uang.DariBulat(130000), Uang.DariBulat(32000)]);
      // 132.000 + 260.000 + 76.000 (2,375 kg × 32.000).
      expect(perkiraan.total, Uang.DariBulat(468000));
      expect(perkiraan.adaTanpaHarga, isFalse);
      expect(LayananSalesman.FormatJumlah(baris.last.jumlah), '2,375');
      expect(baris[1].AmbilJumlahDasar(), Kuantitas.DariBulat(24), reason: '2 lusin = 24 pcs dasar');
    });

    test('produk tanpa harga di perangkat dilewati dan ditandai, total tetap dari baris berharga', () async {
      final tanpaHarga = KatalogTimbanganUji();
      tanpaHarga['ProdukHarga'] = [
        for (final h in (tanpaHarga['ProdukHarga']! as List<Object?>).cast<Map<String, Object?>>())
          if (h['UuidProduk'] != UuidUji.croissant) h,
      ];
      await u.SiapkanKatalog(tanpaHarga);
      final k = await u.MuatKatalog();
      final perkiraan = LayananSalesman.HitungPerkiraan(
        [
          BarisPesananSalesman(
            produk: k.CariProduk(UuidUji.croissant)!,
            satuan: k.CariProduk(UuidUji.croissant)!.satuan.single,
            jumlah: Kuantitas.DariBulat(5),
          ),
          BarisPesananSalesman(
            produk: k.CariProduk(UuidUji.roti)!,
            satuan: k.CariProduk(UuidUji.roti)!.satuan.first,
            jumlah: Kuantitas.DariBulat(1),
          ),
        ],
        k,
        waktu: u.jam,
      );
      expect(perkiraan.hargaSatuan, [null, Uang.DariBulat(12000)]);
      expect(perkiraan.total, Uang.DariBulat(12000));
      expect(perkiraan.adaTanpaHarga, isTrue);
    });
  });

  group('bentuk item outbox persis server', () {
    test('PesananGrosir.Buat: tanpa harga, jumlah 4 desimal, satuan ProdukSatuan, waktu ISO UTC', () {
      final data = LayananSalesman.SusunDataPesanan(
        uuidPelanggan: UuidSalesmanUji.tokoMakmur,
        uuidPengguna: UuidSalesmanUji.dewi,
        dibuatPada: DateTime.utc(2026, 10, 2, 3, 4, 5),
        catatan: 'Kirim Kamis pagi sebelum jam 10',
        uuidKunjungan: '01K5KNJ0000000000000000001',
        baris: [
          BarisPesananSalesman(
            produk: Produk(UuidUji.roti),
            satuan: Satuan(UuidUji.roti, UuidUji.psRotiLusin),
            jumlah: Kuantitas.DariBulat(2),
          ),
          BarisPesananSalesman(
            produk: Produk(jerukMedan),
            satuan: Satuan(jerukMedan, psJeruk),
            jumlah: Kuantitas.Dari('1.25'),
          ),
        ],
      );
      expect(data, {
        'UuidPelanggan': UuidSalesmanUji.tokoMakmur,
        'UuidPengguna': UuidSalesmanUji.dewi,
        'DibuatPada': '2026-10-02T03:04:05.000Z',
        'Catatan': 'Kirim Kamis pagi sebelum jam 10',
        'UuidKunjungan': '01K5KNJ0000000000000000001',
        'Baris': [
          {'UuidProduk': UuidUji.roti, 'UuidSatuan': UuidUji.psRotiLusin, 'Jumlah': '2.0000'},
          {'UuidProduk': jerukMedan, 'UuidSatuan': psJeruk, 'Jumlah': '1.2500'},
        ],
      });
      expect(polaWaktu.hasMatch(data['DibuatPada']! as String), isTrue);
      for (final b in (data['Baris']! as List<Object?>).cast<Map<String, Object?>>()) {
        expect(polaJumlah.hasMatch(b['Jumlah']! as String), isTrue);
        expect(b.keys, isNot(contains('Harga')));
      }

      final minimal = LayananSalesman.SusunDataPesanan(
        uuidPelanggan: UuidSalesmanUji.warungSri,
        uuidPengguna: UuidSalesmanUji.dewi,
        dibuatPada: DateTime.utc(2026, 10, 2, 3),
        baris: [
          BarisPesananSalesman(
            produk: Produk(UuidUji.croissant),
            satuan: Produk(UuidUji.croissant).satuan.single,
            jumlah: Kuantitas.DariBulat(10),
          ),
        ],
      );
      expect(minimal.keys, ['UuidPelanggan', 'UuidPengguna', 'DibuatPada', 'Baris']);
    });

    test('Kunjungan.Catat: koordinat berpasangan string desimal; tanpa lokasi = tanpa kunci lokasi', () {
      final dengan = LayananSalesman.SusunDataKunjungan(
        uuidPelanggan: UuidSalesmanUji.tokoMakmur,
        uuidPengguna: UuidSalesmanUji.dewi,
        masukPada: DateTime.utc(2026, 10, 2, 2, 10),
        keluarPada: DateTime.utc(2026, 10, 2, 2, 35),
        lokasi: LokasiPerangkat.DariPlatform(-7.5666001, 110.8166002, akurasi: 12),
        hasil: HasilKunjungan.PesananDibuat,
        catatan: 'Pemilik toko minta harga khusus bulan depan',
        uuidPesananGrosir: '01K5PG00000000000000000001',
      );
      expect(dengan, {
        'UuidPelanggan': UuidSalesmanUji.tokoMakmur,
        'UuidPengguna': UuidSalesmanUji.dewi,
        'MasukPada': '2026-10-02T02:10:00.000Z',
        'KeluarPada': '2026-10-02T02:35:00.000Z',
        'Latitude': '-7.5666001',
        'Longitude': '110.8166002',
        'AkurasiMeter': 12,
        'Hasil': 'PesananDibuat',
        'Catatan': 'Pemilik toko minta harga khusus bulan depan',
        'UuidPesananGrosir': '01K5PG00000000000000000001',
      });

      final tanpa = LayananSalesman.SusunDataKunjungan(
        uuidPelanggan: UuidSalesmanUji.warungSri,
        uuidPengguna: UuidSalesmanUji.dewi,
        masukPada: DateTime.utc(2026, 10, 2, 4),
        keluarPada: DateTime.utc(2026, 10, 2, 4, 5),
        hasil: HasilKunjungan.TokoTutup,
      );
      expect(tanpa, {
        'UuidPelanggan': UuidSalesmanUji.warungSri,
        'UuidPengguna': UuidSalesmanUji.dewi,
        'MasukPada': '2026-10-02T04:00:00.000Z',
        'KeluarPada': '2026-10-02T04:05:00.000Z',
        'Hasil': 'TokoTutup',
      });
      expect(HasilKunjungan.values.map((h) => h.name), ['PesananDibuat', 'TidakPesan', 'TokoTutup', 'Lainnya']);
    });
  });

  group('kunjungan & pesanan offline (Drift + outbox satu transaksi)', () {
    test('pesanan selama kunjungan tertaut dua arah dan masuk outbox SEBELUM kunjungannya (batch FIFO)', () async {
      u.lokasi = PenentuLokasiTiruan();
      await u.repositoriSalesman.GantiPelanggan([
        for (final p in PelangganSalesmanUji()) PelangganSalesmanPos.DariJson(p),
      ], u.jam);
      final toko = Toko();

      final uuidKunjungan = await u.salesman.MulaiKunjungan(toko, dewi);
      final lokasi = await u.salesman.CatatLokasi(uuidKunjungan);
      expect(lokasi!.latitude, '-7.5666001');

      u.jam = u.jam.add(const Duration(minutes: 10));
      final baris = [
        BarisPesananSalesman(
          produk: Produk(UuidUji.roti),
          satuan: Satuan(UuidUji.roti, UuidUji.psRotiLusin),
          jumlah: Kuantitas.DariBulat(3),
        ),
      ];
      final uuidPesanan = await u.salesman.KirimPesanan(
        pelanggan: toko,
        baris: baris,
        staf: dewi,
        perkiraan: LayananSalesman.HitungPerkiraan(baris, katalog, waktu: u.jam),
        catatan: '  Kirim Kamis pagi  ',
      );

      u.jam = u.jam.add(const Duration(minutes: 15));
      final berjalan = (await u.repositoriSalesman.AmbilKunjunganBerjalan(dewi.uuid))!;
      expect(berjalan.UuidPesananGrosir, uuidPesanan);
      expect(LayananSalesman.AmbilHasilBawaan(berjalan), HasilKunjungan.PesananDibuat);
      await u.salesman.SelesaikanKunjungan(berjalan, hasil: HasilKunjungan.PesananDibuat, staf: dewi);

      final outbox = await u.repositori.AmbilOutboxSiapKirim(50, u.jam);
      expect(outbox.map((o) => o.Jenis), ['PesananGrosir.Buat', 'Kunjungan.Catat']);
      expect(outbox.map((o) => o.Uuid), [uuidPesanan, uuidKunjungan]);
      final pesanan = jsonDecode(outbox.first.Data) as Map<String, Object?>;
      final kunjungan = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(pesanan['UuidKunjungan'], uuidKunjungan);
      expect(pesanan['Catatan'], 'Kirim Kamis pagi');
      expect(pesanan['Baris'], [
        {'UuidProduk': UuidUji.roti, 'UuidSatuan': UuidUji.psRotiLusin, 'Jumlah': '3.0000'},
      ]);
      expect(kunjungan, {
        'UuidPelanggan': UuidSalesmanUji.tokoMakmur,
        'UuidPengguna': UuidSalesmanUji.dewi,
        'MasukPada': '2026-09-24T01:00:00.000Z',
        'KeluarPada': '2026-09-24T01:25:00.000Z',
        'Latitude': '-7.5666001',
        'Longitude': '110.8166002',
        'AkurasiMeter': 12,
        'Hasil': 'PesananDibuat',
        'UuidPesananGrosir': uuidPesanan,
      });

      // Riwayat lokal: pesanan dengan perkiraan Rp 390.000 (3 lusin) & kunjungan selesai, keduanya belum terkirim.
      final riwayatPesanan = await u.repositoriSalesman.PantauPesanan(dewi.uuid, DateTime.utc(2026, 9, 18)).first;
      expect(riwayatPesanan.single.baris.PerkiraanTotal, '390000.00');
      expect(riwayatPesanan.single.status, StatusSinkronPenjualan.BelumTerkirim);
      final riwayatKunjungan = await u.repositoriSalesman
          .PantauKunjunganSelesai(dewi.uuid, DateTime.utc(2026, 9, 18))
          .first;
      expect(riwayatKunjungan.single.baris.Hasil, 'PesananDibuat');
      expect(await u.repositoriSalesman.AmbilKunjunganBerjalan(dewi.uuid), isNull);

      // Selesai dua kali tidak menggandakan item outbox.
      await expectLater(
        u.salesman.SelesaikanKunjungan(berjalan, hasil: HasilKunjungan.Lainnya, staf: dewi),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'KunjunganSudahSelesai')),
      );
      expect(await u.repositori.HitungJumlahTertunda(), 2);
    });

    test(
      'lokasi tidak tersedia tidak menahan kunjungan; kunjungan kedua ditolak selama yang pertama berjalan',
      () async {
        u.lokasi = PenentuLokasiTiruan(tersedia: false);
        await u.repositoriSalesman.GantiPelanggan([
          for (final p in PelangganSalesmanUji()) PelangganSalesmanPos.DariJson(p),
        ], u.jam);
        final uuid = await u.salesman.MulaiKunjungan(Toko(), dewi);
        expect(await u.salesman.CatatLokasi(uuid), isNull);
        await expectLater(
          u.salesman.MulaiKunjungan(Toko(), dewi),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'KunjunganBerjalan')),
        );

        final berjalan = (await u.repositoriSalesman.AmbilKunjunganBerjalan(dewi.uuid))!;
        expect(LayananSalesman.AmbilHasilBawaan(berjalan), HasilKunjungan.TidakPesan);
        await u.salesman.SelesaikanKunjungan(berjalan, hasil: HasilKunjungan.TokoTutup, catatan: ' ', staf: dewi);
        final data =
            jsonDecode((await u.repositori.AmbilOutboxSiapKirim(50, u.jam)).single.Data) as Map<String, Object?>;
        expect(data.keys, ['UuidPelanggan', 'UuidPengguna', 'MasukPada', 'KeluarPada', 'Hasil']);
        expect(data['Hasil'], 'TokoTutup');
      },
    );

    test('pesanan untuk pelanggan lain tidak ditautkan ke kunjungan berjalan', () async {
      await u.repositoriSalesman.GantiPelanggan([
        for (final p in PelangganSalesmanUji()) PelangganSalesmanPos.DariJson(p),
      ], u.jam);
      final pelanggan = (await u.repositoriSalesman.PantauPelanggan().first).map(PelangganSalesman.DariBaris).toList();
      await u.salesman.MulaiKunjungan(pelanggan.firstWhere((p) => p.uuid == UuidSalesmanUji.tokoMakmur), dewi);
      final uuid = await u.salesman.KirimPesanan(
        pelanggan: pelanggan.firstWhere((p) => p.uuid == UuidSalesmanUji.warungSri),
        baris: [
          BarisPesananSalesman(
            produk: Produk(UuidUji.croissant),
            satuan: Produk(UuidUji.croissant).satuan.single,
            jumlah: Kuantitas.DariBulat(10),
          ),
        ],
        staf: dewi,
      );
      final item = (await u.repositori.AmbilOutboxSiapKirim(50, u.jam)).single;
      expect(item.Uuid, uuid);
      expect((jsonDecode(item.Data) as Map<String, Object?>).containsKey('UuidKunjungan'), isFalse);
      expect((await u.repositoriSalesman.AmbilKunjunganBerjalan(dewi.uuid))!.UuidPesananGrosir, isNull);
    });

    test('pesanan ditolak bila kosong, produk di luar cakupan grosir, atau satuan bukan milik produk', () async {
      final toko = Toko();
      await expectLater(
        u.salesman.KirimPesanan(pelanggan: toko, baris: const [], staf: dewi),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PesananKosong')),
      );
      await expectLater(
        u.salesman.KirimPesanan(
          pelanggan: toko,
          baris: [
            BarisPesananSalesman(
              produk: Produk(UuidUji.susuUht),
              satuan: Produk(UuidUji.susuUht).satuan.single,
              jumlah: Kuantitas.DariBulat(1),
            ),
          ],
          staf: dewi,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ProdukTidakBisaDipesan')),
      );
      await expectLater(
        u.salesman.KirimPesanan(
          pelanggan: toko,
          baris: [
            BarisPesananSalesman(
              produk: Produk(UuidUji.croissant),
              satuan: Satuan(UuidUji.roti, UuidUji.psRotiLusin),
              jumlah: Kuantitas.DariBulat(1),
            ),
          ],
          staf: dewi,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'SatuanTidakValid')),
      );
      expect(await u.repositori.HitungJumlahTertunda(), 0);
    });

    test('tanpa izin salesman.kunjungan: kunjungan & pesanan ditolak', () async {
      final rina = await u.Staf('Rina Wulandari');
      await expectLater(
        u.salesman.MulaiKunjungan(Toko(), rina),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
      );
      await expectLater(
        u.salesman.PerbaruiPelanggan(rina),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
      );
    });

    test('sinkron mengirim pesanan lebih dulu, lalu kunjungan; penolakan server tampil di riwayat', () async {
      await u.repositoriSalesman.GantiPelanggan([
        for (final p in PelangganSalesmanUji()) PelangganSalesmanPos.DariJson(p),
      ], u.jam);
      final uuidKunjungan = await u.salesman.MulaiKunjungan(Toko(), dewi);
      final uuidPesanan = await u.salesman.KirimPesanan(
        pelanggan: Toko(),
        baris: [
          BarisPesananSalesman(
            produk: Produk(UuidUji.croissant),
            satuan: Produk(UuidUji.croissant).satuan.single,
            jumlah: Kuantitas.DariBulat(4),
          ),
        ],
        staf: dewi,
      );
      await u.salesman.SelesaikanKunjungan(
        (await u.repositoriSalesman.AmbilKunjunganBerjalan(dewi.uuid))!,
        hasil: HasilKunjungan.PesananDibuat,
        staf: dewi,
      );

      final dikirim = <List<String>>[];
      u.server.penangan = (p) async {
        final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
            .cast<Map<String, Object?>>();
        dikirim.add([for (final i in item) '${i['Jenis']}']);
        return JsonUji({
          'Hasil': [
            for (final i in item)
              i['Jenis'] == 'PesananGrosir.Buat'
                  ? {
                      'Uuid': i['Uuid'],
                      'Jenis': i['Jenis'],
                      'Status': 'Ditolak',
                      'Galat': {'Kode': 'HargaBelumDiatur', 'Pesan': 'Harga Croissant belum diatur di kantor.'},
                    }
                  : {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
        });
      };
      final hasil = await u.sinkron.KirimTertunda();

      expect(dikirim, [
        ['PesananGrosir.Buat', 'Kunjungan.Catat'],
      ]);
      expect((hasil.terkirim, hasil.ditolak), (1, 1));
      final pesanan = (await u.repositoriSalesman.PantauPesanan(dewi.uuid, DateTime.utc(2026, 9, 18)).first).single;
      expect(pesanan.baris.Uuid, uuidPesanan);
      expect(pesanan.status, StatusSinkronPenjualan.PerluTindakan);
      expect(pesanan.pesanGalat, 'Harga Croissant belum diatur di kantor.');
      final kunjungan =
          (await u.repositoriSalesman.PantauKunjunganSelesai(dewi.uuid, DateTime.utc(2026, 9, 18)).first).single;
      expect((kunjungan.baris.Uuid, kunjungan.status), (uuidKunjungan, StatusSinkronPenjualan.Terkirim));
    });
  });

  group('cache pelanggan offline', () {
    test('unduh halaman demi halaman lalu ganti utuh; offline = cache lama tetap; cari nama & nomor HP', () async {
      final halaman = <String>[];
      u.server.penangan = (p) async {
        expect(p.headers['X-Id-Kasir'], UuidSalesmanUji.dewi);
        final ke = p.url.queryParameters['halaman']!;
        halaman.add(ke);
        final semua = PelangganSalesmanUji();
        return JsonUji({
          'Pelanggan': ke == '1' ? [semua.first] : [semua.last],
          'Halaman': int.parse(ke),
          'AdaBerikutnya': ke == '1',
        });
      };
      expect(await u.salesman.PerbaruiPelanggan(dewi), 2);
      expect(halaman, ['1', '2']);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.pelangganSalesmanDiperbaruiPada), isNotNull);

      final daftar = (await u.repositoriSalesman.PantauPelanggan().first).map(PelangganSalesman.DariBaris).toList();
      expect(daftar.map((p) => p.nama), ['Toko Kelontong Makmur Jaya Abadi Sentosa', 'Warung Bu Sri']);
      final toko = daftar.first;
      expect(toko.adaLewatJatuhTempo, isTrue);
      expect(toko.sisaLimit, Uang.DariBulat(12250000));
      expect(toko.noHp, '6281355550001');

      for (final (kata, hasil) in [
        ('makmur', ['Toko Kelontong Makmur Jaya Abadi Sentosa']),
        ('0812 9999', ['Warung Bu Sri']),
        ('+62 813-5555', ['Toko Kelontong Makmur Jaya Abadi Sentosa']),
        ('', ['Toko Kelontong Makmur Jaya Abadi Sentosa', 'Warung Bu Sri']),
        ('apotek', <String>[]),
      ]) {
        expect(LayananSalesman.SaringPelanggan(daftar, kata).map((p) => p.nama), hasil, reason: kata);
      }

      u.server.penangan = (_) async => throw http.ClientException('offline');
      await expectLater(
        u.salesman.PerbaruiPelanggan(dewi),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );
      expect(await u.repositoriSalesman.PantauPelanggan().first, hasLength(2));
    });

    test('kunjungan lokal yang lebih baru menggantikan "terakhir dikunjungi" dari server', () async {
      final baris = _Baris(PelangganSalesmanPos.DariJson(PelangganSalesmanUji().first));
      final lokal = DateTime.utc(2026, 9, 24, 1);
      expect(PelangganSalesman.DariBaris(baris).terakhirDikunjungiPada, DateTime.utc(2026, 9, 20, 3));
      expect(PelangganSalesman.DariBaris(baris, kunjunganLokal: lokal).terakhirDikunjungiPada, lokal);
      expect(
        PelangganSalesman.DariBaris(baris, kunjunganLokal: DateTime.utc(2026, 9, 1)).terakhirDikunjungiPada,
        DateTime.utc(2026, 9, 20, 3),
      );
    });

    test('perangkat dicabut: cache pelanggan (nomor HP penuh) dihapus, kunjungan & outbox tetap', () async {
      await u.repositoriSalesman.GantiPelanggan([
        for (final p in PelangganSalesmanUji()) PelangganSalesmanPos.DariJson(p),
      ], u.jam);
      await u.salesman.MulaiKunjungan(Toko(), dewi);
      await u.repositori.HapusDataSensitif();
      expect(await u.repositoriSalesman.PantauPelanggan().first, isEmpty);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.pelangganSalesmanDiperbaruiPada), isNull);
      expect(await u.repositoriSalesman.AmbilKunjunganBerjalan(UuidSalesmanUji.dewi), isNotNull);
    });
  });

  group('mode ruang kerja & rel navigasi', () {
    test(
      'hanya salesman → mode Salesman tanpa shift; kasir & pemilik tetap alur kasir kecuali di HP Salesman',
      () async {
        final rina = await u.Staf('Rina Wulandari');
        const pemilik = StafLokal(uuid: 'P', nama: 'Pemilik', pemilik: true, izin: []);
        expect(LayananSalesman.CekHanyaSalesman(dewi), isTrue);
        expect(LayananSalesman.CekModeSalesman(dewi, 'Kasir'), isTrue);
        expect(LayananSalesman.CekModeSalesman(rina, 'Kasir'), isFalse);
        expect(LayananSalesman.CekModeSalesman(rina, 'Salesman'), isTrue);
        expect(LayananSalesman.CekModeSalesman(pemilik, 'Kasir'), isFalse);
        expect(LayananSalesman.CekModeSalesman(pemilik, 'Salesman'), isTrue);
        const kasirSalesman = StafLokal(
          uuid: 'K',
          nama: 'Kasir',
          pemilik: false,
          izin: ['penjualan.buat', 'salesman.kunjungan'],
        );
        expect(LayananSalesman.CekHanyaSalesman(kasirSalesman), isFalse);
      },
    );

    test('rel kasir memuat Salesman bila berizin; melebihi 8 item → Salesman dilepas, Pengaturan tetap', () {
      const kasirSalesman = StafLokal(
        uuid: 'K',
        nama: 'Kasir',
        pemilik: false,
        izin: ['penjualan.buat', 'salesman.kunjungan'],
      );
      expect(ItemNavigasi.Saring(kasirSalesman).map((i) => i.label), [
        'Jual',
        'Riwayat',
        'Salesman',
        'Kas',
        'Shift',
        'Sinkron',
        'Pengaturan',
      ]);
      const pemilik = StafLokal(uuid: 'P', nama: 'Pemilik', pemilik: true, izin: []);
      final relPemilikMeja = ItemNavigasi.Saring(pemilik, modulAktif: {ItemNavigasi.modulMeja});
      expect(relPemilikMeja, hasLength(ItemNavigasi.batasItem));
      expect(relPemilikMeja.map((i) => i.label), isNot(contains('Salesman')));
      expect(relPemilikMeja.last.label, 'Pengaturan');
      expect(ItemNavigasi.Saring(pemilik).map((i) => i.label), contains('Salesman'));
      expect(ItemNavigasi.Saring(dewi, daftar: ItemNavigasi.salesman).map((i) => i.label), [
        'Salesman',
        'Sinkron',
        'Pengaturan',
      ]);
    });
  });
  group('SO cepat dari keranjang kasir (POS-05)', () {
    const toko = PelangganTerpilih(
      uuid: UuidSalesmanUji.tokoMakmur,
      nama: 'Toko Kelontong Makmur Jaya Abadi Sentosa',
      noHpSamar: '0813****0001',
      kodeTier: 'Grosir',
      limitKredit: '25000000.00',
      sisaPiutang: '24000000.00',
    );

    ItemKeranjang Baris(String uuidProduk, String uuidSatuan, int jumlah, {Uang? hargaSatuan, bool diskon = false}) {
      final produk = Produk(uuidProduk);
      final satuan = Satuan(uuidProduk, uuidSatuan);
      return ItemKeranjang(
        uuid: 'B$uuidProduk',
        uuidProduk: uuidProduk,
        nama: produk.nama,
        uuidProdukSatuan: satuan.uuid,
        namaSatuan: satuan.nama,
        bolehDesimal: satuan.bolehDesimal,
        jumlah: Kuantitas.DariBulat(jumlah),
        hargaSatuan: hargaSatuan ?? Uang.DariBulat(9000),
        diskon: diskon ? DiskonManual.DariJumlah(Uang.DariBulat(1000)) : null,
      );
    }

    Keranjang Isi({PelangganTerpilih? pelanggan = toko, List<ItemKeranjang>? baris}) =>
        Keranjang(baris: baris ?? [Baris(UuidUji.roti, UuidUji.psRotiLusin, 3)], pelanggan: pelanggan);

    test('izin: kasir berizin jual atau salesman boleh; staf tanpa keduanya tidak', () async {
      final rina = await u.Staf('Rina Wulandari');
      const gudang = StafLokal(uuid: 'G', nama: 'Staf Gudang', pemilik: false, izin: ['produk.lihat']);

      expect(LayananSalesman.CekBolehBuatSo(rina), isTrue);
      expect(LayananSalesman.CekBolehBuatSo(dewi), isTrue);
      expect(LayananSalesman.CekBolehBuatSo(gudang), isFalse);
      await expectLater(
        u.salesman.KirimPesananDariKeranjang(keranjang: Isi(), katalog: katalog, staf: gudang),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
      );
      expect(await u.repositori.HitungJumlahTertunda(), 0);
    });

    test('alasan penolakan jelas untuk keranjang yang tidak bisa jadi pesanan grosir', () {
      String? Alasan(Keranjang k) => LayananSalesman.AlasanKeranjangTidakBisaJadiSo(k, katalog);

      expect(Alasan(Isi()), isNull);
      expect(Alasan(Keranjang(pelanggan: toko)), contains('Keranjang masih kosong'));
      expect(Alasan(Isi(pelanggan: null)), contains('Pilih pelanggan dulu'));
      expect(
        Alasan(Isi(baris: [Baris(UuidUji.roti, UuidUji.psRotiLusin, 3, diskon: true)])),
        contains('harga dari server'),
      );
      expect(
        Alasan(Keranjang(baris: Isi().baris, pelanggan: toko, biayaKirim: Uang.DariBulat(10000))),
        contains('ongkir'),
      );
      expect(
        Alasan(Isi(baris: [Baris(UuidUji.susuUht, Produk(UuidUji.susuUht).satuan.single.uuid, 1)])),
        contains('hanya produk berstok biasa'),
      );
    });

    test(
      'simpan: outbox PesananGrosir.Buat tanpa harga, tanpa kunjungan, plus baris lokal berperkiraan harga',
      () async {
        u.jam = DateTime.utc(2026, 9, 25, 3);
        final rina = await u.Staf('Rina Wulandari');
        final keranjang = Isi(
          baris: [Baris(UuidUji.roti, UuidUji.psRotiLusin, 2), Baris(UuidUji.roti, UuidUji.psRotiPcs, 12)],
        );

        final uuid = await u.salesman.KirimPesananDariKeranjang(
          keranjang: keranjang,
          katalog: katalog,
          staf: rina,
          catatan: '  Kirim Kamis pagi  ',
        );

        final outbox = (await u.db.select(u.db.outbox).get()).single;
        expect(outbox.Jenis, 'PesananGrosir.Buat');
        expect(outbox.Uuid, uuid);
        final data = jsonDecode(outbox.Data) as Map<String, Object?>;
        expect(data['UuidPelanggan'], UuidSalesmanUji.tokoMakmur);
        expect(data['UuidPengguna'], rina.uuid);
        expect(data['Catatan'], 'Kirim Kamis pagi');
        expect(data.containsKey('UuidKunjungan'), isFalse);
        expect(polaWaktu.hasMatch(data['DibuatPada']! as String), isTrue);
        final barisData = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(
          [for (final b in barisData) (b['UuidProduk'], b['UuidSatuan'], b['Jumlah'])],
          [(UuidUji.roti, UuidUji.psRotiLusin, '2.0000'), (UuidUji.roti, UuidUji.psRotiPcs, '12.0000')],
        );
        for (final b in barisData) {
          expect(polaJumlah.hasMatch(b['Jumlah']! as String), isTrue);
          expect(b.keys.toSet(), {'UuidProduk', 'UuidSatuan', 'Jumlah'}, reason: 'Klien tidak pernah mengirim harga.');
        }

        final lokal = (await u.db.select(u.db.pesananGrosirLokal).get()).single;
        expect(lokal.Uuid, uuid);
        expect(lokal.NamaPelanggan, 'Toko Kelontong Makmur Jaya Abadi Sentosa');
        expect(lokal.JumlahBaris, 2);
        // Harga katalog roti: lusin 130.000, pcs bertingkat 11.000 (≥ 10) → 260.000 + 132.000.
        expect(lokal.PerkiraanTotal, '392000.00');
      },
    );

    test('keranjang yang ditolak tidak meninggalkan outbox', () async {
      final rina = await u.Staf('Rina Wulandari');

      await expectLater(
        u.salesman.KirimPesananDariKeranjang(keranjang: Isi(pelanggan: null), katalog: katalog, staf: rina),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'KeranjangBelumDidukung')),
      );
      expect(await u.repositori.HitungJumlahTertunda(), 0);
      expect(await u.db.select(u.db.pesananGrosirLokal).get(), isEmpty);
    });

    test('selisih limit kredit: perkiraan melebihi sisa limit bernilai negatif (hanya peringatan)', () {
      // Limit 25 jt − piutang 24 jt = sisa 1 jt.
      expect(SelisihLimitKredit(toko, Uang.DariBulat(392000)), Uang.DariBulat(608000));
      expect(SelisihLimitKredit(toko, Uang.DariBulat(1500000))!.BernilaiNegatif(), isTrue);
      expect(
        SelisihLimitKredit(const PelangganTerpilih(uuid: 'X', nama: 'Tanpa limit', noHpSamar: ''), Uang.DariBulat(1)),
        isNull,
      );
    });
  });
}

/// Baris cache lokal dari model API (bentuk yang ditulis `RepositoriSalesman.GantiPelanggan`).
BarisPelangganSalesmanLokal _Baris(PelangganSalesmanPos p) => BarisPelangganSalesmanLokal(
  Uuid: p.uuid,
  Nama: p.nama,
  NoHp: p.noHp,
  Alamat: p.alamat,
  KodeTier: p.kodeTier,
  NamaTier: p.namaTier,
  LimitKredit: p.limitKredit?.KeString(),
  TerminHari: p.terminHari,
  SisaPiutang: p.sisaPiutang.KeString(),
  JumlahPiutangJatuhTempo: p.jumlahPiutangJatuhTempo.KeString(),
  HariLewatJatuhTempo: p.hariLewatJatuhTempo,
  TerakhirDikunjungiPada: p.terakhirDikunjungiPada,
);
