import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/PesananMeja.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Meja/KonteksPesananMeja.dart';
import 'package:kasir/Domain/Meja/LayananPesananMeja.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// Rincian F-07 mode meja & F-10b fase 1 di perangkat: buka pesanan (BR-07.1 nomor OB), ronde & kirim ke dapur,
/// void item BR-07.5, pindah meja (last-writer-wins), bayar menutup pesanan, snapshot server + ETag, kunci bayar.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late StafLokal budi;
  late StafLokal sari;

  const mejaD01 = '01K5MEJA0000000000000D0101';
  const mejaT01 = '01K5MEJA0000000000000T0101';

  Future<void> Siapkan() async {
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    await u.SiapkanMeja();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    budi = await u.Staf('Budi Santoso');
    sari = await u.Staf('Sari Lestari');
  }

  Future<BarisMeja> Meja(String uuid) async => (await u.repositoriMeja.CariMeja(uuid))!;

  /// 2× Es Kopi Susu Aren (kurang manis) + 1× Croissant (Rp 18.000 & Rp 25.000).
  List<ItemKeranjang> DrafContoh() {
    final gula = [PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol())];
    final kopi = u.penjualan.BuatBaris(
      katalog,
      k,
      katalog.CariProduk(UuidUji.kopiSusu)!,
      pilihan: gula,
      jumlah: Kuantitas.DariBulat(2),
      catatan: 'Es dipisah',
    );
    return [kopi, u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!)];
  }

  Future<List<BarisOutbox>> Outbox() => (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();

  Map<String, Object?> Data(BarisOutbox o) => jsonDecode(o.Data) as Map<String, Object?>;

  setUp(() => u = LingkunganUji.Buat());
  tearDown(() => u.Tutup());

  group('buka & ubah pesanan', () {
    test('BR-07.1 nomor OB per perangkat; outbox Buka ber-Uuid pesanan; meja terisi & label wajib ditolak', () async {
      await Siapkan();
      final d01 = await Meja(mejaD01);
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: d01, jumlahTamu: 3);
      expect(pesanan.nomor, 'OB/SLB/260924/POS-001-0001');
      expect(pesanan.namaMeja, 'D-01');
      expect(pesanan.status, StatusPesananMeja.terbuka);

      final buka = (await Outbox()).single;
      expect(buka.Jenis, 'PesananTerbuka.Buka');
      expect(buka.Uuid, pesanan.uuid);
      expect(Data(buka), {
        'Nomor': 'OB/SLB/260924/POS-001-0001',
        'UuidMeja': mejaD01,
        'Label': null,
        'JumlahTamu': 3,
        'UuidPengguna': rina.uuid,
        'DibukaPada': '2026-09-24T01:00:00.000Z',
      });

      await expectLater(() => u.pesananMeja.Buka(kasir: budi, k: k, meja: d01), GalatDengan('MejaTerisi'));
      await expectLater(() => u.pesananMeja.Buka(kasir: rina, k: k, label: '  '), GalatDengan('LabelDiperlukan'));
      await expectLater(
        () => u.pesananMeja.Buka(
          kasir: const StafLokal(uuid: '01K5STAF000000000000000009', nama: 'Tamu', pemilik: false, izin: []),
          k: k,
          label: 'Bu Ani',
        ),
        GalatDengan('TanpaIzin'),
      );

      final antre = await u.pesananMeja.Buka(kasir: rina, k: k, label: 'Pak Joko (bungkus)');
      expect(antre.nomor, 'OB/SLB/260924/POS-001-0002');
      expect(antre.AmbilJudul(), 'Pak Joko (bungkus)');

      // v2.00: pelayan (hanya `pesanan.meja.catat`) boleh membuka & mengirim pesanan ke dapur.
      const pelayan = StafLokal(
        uuid: '01K5STAF000000000000000008',
        nama: 'Dewi Pelayan',
        pemilik: false,
        izin: [IzinKasir.pesananMejaCatat],
      );
      final olehPelayan = await u.pesananMeja.Buka(kasir: pelayan, k: k, label: 'Bu Ani');
      final dikirim = await u.pesananMeja.SimpanBaris(
        uuidPesanan: olehPelayan.uuid,
        draf: DrafContoh(),
        kasir: pelayan,
        kirimDapur: true,
      );
      expect(dikirim.AmbilBarisAktif().every((b) => b.dikirimKeDapur), isTrue);
    });

    test('pindah meja: meja lain yang terisi ditolak; perubahan header dikirim dengan DiubahPada', () async {
      await Siapkan();
      final a = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
      await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaT01));

      final t01 = await Meja(mejaT01);
      await expectLater(
        () => u.pesananMeja.Ubah(uuidPesanan: a.uuid, kasir: rina, meja: t01, jumlahTamu: 2),
        GalatDengan('MejaTerisi'),
      );
      u.jam = DateTime.utc(2026, 9, 24, 1, 20);
      final pindah = await u.pesananMeja.Ubah(
        uuidPesanan: a.uuid,
        kasir: rina,
        meja: await Meja('01K5MEJA0000000000000D0201'),
        jumlahTamu: 5,
      );
      expect(pindah.namaMeja, 'D-02');
      expect(pindah.jumlahTamu, 5);
      final ubah = (await Outbox()).last;
      expect(ubah.Jenis, 'PesananTerbuka.Ubah');
      expect(Data(ubah), {
        'UuidPesanan': a.uuid,
        'UuidMeja': '01K5MEJA0000000000000D0201',
        'Label': null,
        'JumlahTamu': 5,
        'UuidPengguna': rina.uuid,
        'DiubahPada': '2026-09-24T01:20:00.000Z',
      });
      expect(await u.repositoriMeja.CariPesananDiMeja(mejaD01), isNull);
    });
  });

  group('baris, ronde & dapur', () {
    test(
      'kirim ke dapur = ronde 1 terkirim; simpan tanpa kirim = ronde 2; kirim berikutnya memakai KirimDapur',
      () async {
        await Siapkan();
        final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
        final draf = DrafContoh();

        final r1 = await u.pesananMeja.SimpanBaris(
          uuidPesanan: pesanan.uuid,
          draf: draf,
          kasir: rina,
          kirimDapur: true,
        );
        expect(r1.baris.map((b) => (b.ronde, b.dikirimKeDapur)), [(1, true), (1, true)]);
        final tambah = (await Outbox()).last;
        expect(tambah.Jenis, 'PesananTerbuka.Tambah');
        expect(Data(tambah)['Ronde'], 1);
        expect(Data(tambah)['KirimDapur'], true);
        expect((Data(tambah)['Baris']! as List<Object?>).first, {
          'Uuid': draf.first.uuid,
          'UuidProduk': UuidUji.kopiSusu,
          'UuidProdukSatuan': UuidUji.psKopiSusu,
          'Jumlah': '2.0000',
          'HargaSatuan': '18000.00',
          'HargaPilihan': '0.00',
          'Pilihan': [
            {'UuidPilihan': UuidUji.gulaKurang, 'Nama': 'Kurang manis', 'Harga': '0.00'},
          ],
          'Catatan': 'Es dipisah',
        });

        final americano = u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!);
        final r2 = await u.pesananMeja.SimpanBaris(
          uuidPesanan: pesanan.uuid,
          draf: [americano],
          kasir: rina,
          kirimDapur: false,
        );
        expect(r2.baris.last.ronde, 2);
        expect(r2.baris.last.AmbilLabelStatus(), 'Belum dikirim');

        final r3 = await u.pesananMeja.SimpanBaris(
          uuidPesanan: pesanan.uuid,
          draf: const [],
          kasir: rina,
          kirimDapur: true,
        );
        expect(r3.baris.every((b) => b.dikirimKeDapur), isTrue);
        final kirim = (await Outbox()).last;
        expect(kirim.Jenis, 'PesananTerbuka.KirimDapur');
        expect(Data(kirim)['UuidBaris'], [americano.uuid]);
        expect(Data(kirim)['Ronde'], 3);

        await expectLater(
          () => u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: const [], kasir: rina, kirimDapur: true),
          GalatDengan('TidakAdaItemBaru'),
        );
        final diskon = americano.Salin(diskon: () => DiskonManual.DariPersen(Decimal.fromInt(10)));
        await expectLater(
          () => u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [diskon], kasir: rina, kirimDapur: true),
          GalatDengan('DiskonSaatBayar'),
        );
      },
    );

    test(
      'BR-07.5 batal item: belum dikirim cukup konfirmasi; terkirim butuh alasan & penyetuju penjualan.void',
      () async {
        await Siapkan();
        final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
        final draf = DrafContoh();
        await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [draf.first], kasir: rina, kirimDapur: true);
        await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [draf.last], kasir: rina, kirimDapur: false);

        // Croissant belum dikirim: kasir tanpa izin void boleh membatalkan tanpa alasan.
        await u.pesananMeja.BatalkanBaris(uuidPesanan: pesanan.uuid, uuidBaris: [draf.last.uuid], kasir: rina);
        final batalBelum = (await Outbox()).last;
        expect(Data(batalBelum)['Alasan'], isNull);
        expect(Data(batalBelum)['UuidPenyetuju'], isNull);

        // Kopi sudah di dapur.
        await expectLater(
          () => u.pesananMeja.BatalkanBaris(
            uuidPesanan: pesanan.uuid,
            uuidBaris: [draf.first.uuid],
            kasir: rina,
            alasan: 'x',
          ),
          GalatDengan('AlasanWajib'),
        );
        await expectLater(
          () => u.pesananMeja.BatalkanBaris(
            uuidPesanan: pesanan.uuid,
            uuidBaris: [draf.first.uuid],
            kasir: rina,
            alasan: 'Tamu ganti menu',
          ),
          GalatDengan('PersetujuanDiperlukan'),
        );
        final hasil = await u.pesananMeja.BatalkanBaris(
          uuidPesanan: pesanan.uuid,
          uuidBaris: [draf.first.uuid],
          kasir: rina,
          alasan: 'Tamu ganti menu',
          penyetuju: budi,
        );
        expect(hasil.AmbilBarisAktif(), isEmpty);
        expect(hasil.baris.first.AmbilLabelStatus(), 'Dibatalkan');
        final batal = (await Outbox()).last;
        expect(batal.Jenis, 'PesananTerbuka.BatalkanBaris');
        expect(Data(batal)['Alasan'], 'Tamu ganti menu');
        expect(Data(batal)['UuidPenyetuju'], budi.uuid);
        expect(Data(batal)['DibatalkanPada'], '2026-09-24T01:00:00.000Z');
      },
    );

    test(
      'void item: CatatTerbuang hanya ikut outbox bila ada item terkirim dan kasir menandainya sudah dibuat',
      () async {
        await Siapkan();
        final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
        final draf = DrafContoh();
        await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [draf.first], kasir: rina, kirimDapur: true);
        await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [draf.last], kasir: rina, kirimDapur: false);

        // Item belum dikirim: tidak ada yang bisa terbuang, kunci tidak dikirim walau diminta.
        await u.pesananMeja.BatalkanBaris(
          uuidPesanan: pesanan.uuid,
          uuidBaris: [draf.last.uuid],
          kasir: rina,
          catatTerbuang: true,
        );
        expect(Data((await Outbox()).last).containsKey('CatatTerbuang'), isFalse);

        // Item terkirim ditandai sudah dibuat → server mencatat bahan terbuang.
        await u.pesananMeja.BatalkanBaris(
          uuidPesanan: pesanan.uuid,
          uuidBaris: [draf.first.uuid],
          kasir: rina,
          alasan: 'Tamu ganti menu',
          penyetuju: budi,
          catatTerbuang: true,
        );
        expect(Data((await Outbox()).last)['CatatTerbuang'], isTrue);
      },
    );

    test('void item terkirim yang belum dibuat: kunci CatatTerbuang tidak dikirim', () async {
      await Siapkan();
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
      final draf = DrafContoh();
      await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [draf.first], kasir: rina, kirimDapur: true);
      await u.pesananMeja.BatalkanBaris(
        uuidPesanan: pesanan.uuid,
        uuidBaris: [draf.first.uuid],
        kasir: rina,
        alasan: 'Tamu ganti menu',
        penyetuju: budi,
      );
      expect(Data((await Outbox()).last).containsKey('CatatTerbuang'), isFalse);
    });

    test(
      'batal pesanan: ada item di dapur → kasir tanpa izin void butuh penyetuju; pesanan hilang dari daftar',
      () async {
        await Siapkan();
        final pesanan = await u.pesananMeja.Buka(kasir: sari, k: k, meja: await Meja(mejaD01));
        await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: DrafContoh(), kasir: sari, kirimDapur: true);

        await expectLater(
          () => u.pesananMeja.Batal(uuidPesanan: pesanan.uuid, alasan: 'Tamu pulang', kasir: sari),
          GalatDengan('PersetujuanDiperlukan'),
        );
        await u.pesananMeja.Batal(uuidPesanan: pesanan.uuid, alasan: 'Tamu pulang', kasir: sari, penyetuju: budi);
        final batal = (await Outbox()).last;
        expect(batal.Jenis, 'PesananTerbuka.Batal');
        expect(Data(batal)['UuidPenyetuju'], budi.uuid);
        expect(await u.repositoriMeja.PantauPesananTerbuka().first, isEmpty);
        await expectLater(
          () => u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: DrafContoh(), kasir: sari, kirimDapur: true),
          GalatDengan('PesananSudahDitutup'),
        );
      },
    );
  });

  group('bayar', () {
    test('bayar pesanan = satu Penjualan.Buat (MakanDiTempat, UuidPesananTerbuka); item batal tidak ditagih', () async {
      await Siapkan();
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
      final draf = DrafContoh();
      await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: draf, kasir: rina, kirimDapur: true);
      final americano = u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!);
      await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: [americano], kasir: rina, kirimDapur: false);
      await u.pesananMeja.BatalkanBaris(uuidPesanan: pesanan.uuid, uuidBaris: [americano.uuid], kasir: rina);

      final drafBayar = Keranjang(
        pesananMeja: KonteksPesananMeja.DariPesanan((await u.repositoriMeja.CariPesanan(pesanan.uuid))!),
      );
      final keranjang = LayananPesananMeja.SusunKeranjangEfektif(
        drafBayar,
        await u.repositoriMeja.CariPesanan(pesanan.uuid),
        katalog,
      );
      expect(keranjang.baris.map((b) => b.nama), [
        'Es Kopi Susu Aren',
        'Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo',
      ]);
      expect(keranjang.pesananMeja!.CekTersimpan(draf.first.uuid), isTrue);
      final hitungan = u.penjualan.Hitung(keranjang, k);
      expect(hitungan.hasil.totalAkhir, Uang.Dari('67100.00'));

      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
        kasir: rina,
        k: k,
      );
      final jual = (await Outbox()).firstWhere((o) => o.Uuid == hasil.uuid);
      expect(Data(jual)['UuidPesananTerbuka'], pesanan.uuid);
      expect(Data(jual)['Kanal'], 'MakanDiTempat');
      expect((await u.db.select(u.db.penjualan).get()).single.Kanal, 'MakanDiTempat');
      expect((await u.repositoriMeja.CariPesanan(pesanan.uuid))!.status, StatusPesananMeja.dibayar);
      expect(await u.repositoriMeja.PantauPesananTerbuka().first, isEmpty);
    });

    test('penjualan biasa tidak membawa UuidPesananTerbuka dan tetap BawaPulang', () async {
      await Siapkan();
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      final keranjang = u.penjualan.TambahBaris(Keranjang.kosong, DrafContoh().last, katalog, k);
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(50000))],
        kasir: rina,
        k: k,
      );
      final data = Data((await Outbox()).firstWhere((o) => o.Uuid == hasil.uuid));
      expect(data.containsKey('UuidPesananTerbuka'), isFalse);
      expect(data['Kanal'], 'BawaPulang');
      expect(LayananPenjualan.AmbilKanal(keranjang), KanalPenjualan.BawaPulang);
    });
  });

  group('server', () {
    Map<String, Object?> PesananServer(String uuid, String meja, {List<Map<String, Object?>> baris = const []}) => {
      'Uuid': uuid,
      'Nomor': 'OB/SLB/260924/POS-002-0001',
      'UuidMeja': meja,
      'NamaMeja': 'T-01',
      'Label': null,
      'JumlahTamu': 2,
      'DibukaOleh': 'Budi Santoso',
      'DibukaPada': '2026-09-24T00:40:00Z',
      'DikunciBayar': false,
      'Baris': baris,
    };

    test(
      'snapshot: pesanan perangkat lain masuk; perubahan lokal tertunda tidak ditimpa; ditutup & usang dihapus',
      () async {
        await Siapkan();
        final lokal = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
        await u.db
            .into(u.db.pesananTerbuka)
            .insert(
              PesananTerbukaCompanion.insert(
                Uuid: '01K5PESANAN0000000000USANG',
                Nomor: 'OB/SLB/260923/POS-002-0009',
                DibukaPada: DateTime.utc(2026, 9, 23, 10),
                Status: StatusPesananMeja.terbuka,
                Baris: '[]',
                DiubahPada: DateTime.utc(2026, 9, 23, 10),
              ),
            );

        u.server.penangan = (p) async {
          expect(p.url.path, '/api/pos/v1/pesanan-terbuka');
          return http.Response(
            jsonEncode({
              'Pesanan': [
                PesananServer(
                  '01K5PESANAN00000000LAIN001',
                  mejaT01,
                  baris: [
                    {
                      'Uuid': '01K5BARIS0000000000LAIN001',
                      'UuidProduk': UuidUji.croissant,
                      'UuidProdukSatuan': UuidUji.psCroissant,
                      'NamaProduk': 'Croissant Butter',
                      'Jumlah': '1.0000',
                      'HargaSatuan': '25000.00',
                      'HargaPilihan': '0.00',
                      'Pilihan': <Object?>[],
                      'Catatan': null,
                      'Ronde': 1,
                      'Dibatalkan': false,
                      'DikirimKeDapur': true,
                      'StatusDapur': 'Dimasak',
                    },
                  ],
                ),
                // Versi server pesanan lokal masih kosong tanpa meja: jangan menimpa, Buka-nya belum terkirim.
                {...PesananServer(lokal.uuid, mejaT01), 'NamaMeja': 'SALAH'},
              ],
              'Ditutup': <Object?>[],
            }),
            200,
            headers: {'content-type': 'application/json', 'etag': '"v1"'},
          );
        };
        expect(await u.pesananMeja.Tarik(), isTrue);

        final daftar = await u.repositoriMeja.PantauPesananTerbuka().first;
        expect(daftar.map((p) => p.uuid), unorderedEquals([lokal.uuid, '01K5PESANAN00000000LAIN001']));
        expect(daftar.firstWhere((p) => p.uuid == lokal.uuid).namaMeja, 'D-01');
        final lain = daftar.firstWhere((p) => p.uuid != lokal.uuid);
        expect(lain.baris.single.AmbilLabelStatus(), 'Dimasak');
        // Perubahan tertunda → ETag tidak disimpan (snapshot berikutnya diunduh utuh).
        expect(await u.repositori.AmbilPengaturan(KunciPengaturan.etagPesananTerbuka), '');

        // Outbox terkirim; snapshot berikutnya menutup pesanan perangkat lain.
        await u.db.delete(u.db.outbox).go();
        u.server.penangan = (p) async => http.Response(
          jsonEncode({
            'Pesanan': [PesananServer(lokal.uuid, mejaD01)],
            'Ditutup': [
              {'Uuid': '01K5PESANAN00000000LAIN001', 'Status': 'Dibayar'},
            ],
          }),
          200,
          headers: {'content-type': 'application/json', 'etag': '"v2"'},
        );
        await u.pesananMeja.Tarik();
        final akhir = await u.repositoriMeja.PantauPesananTerbuka().first;
        expect(akhir.map((p) => p.uuid), [lokal.uuid]);
        expect(await u.repositori.AmbilPengaturan(KunciPengaturan.etagPesananTerbuka), '"v2"');

        // ETag sama → 304, tidak ada yang berubah.
        u.server.penangan = (p) async {
          expect(p.headers['If-None-Match'], '"v2"');
          return http.Response('', 304);
        };
        expect(await u.pesananMeja.Tarik(), isTrue);
        expect((await u.repositoriMeja.PantauPesananTerbuka().first).single.uuid, lokal.uuid);
      },
    );

    test('K-12 minta bill & meja kotor: bayar lokal menandai meja, snapshot tidak menghapusnya sebelum penjualan terkirim, '
        'Meja.Bersih tertunda tidak dikembalikan snapshot', () async {
      await Siapkan();
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));

      // Minta bill: waktu pertama dipertahankan, outbox Ubah hanya membawa MintaBill.
      await u.pesananMeja.AturMintaBill(uuidPesanan: pesanan.uuid, kasir: rina, minta: true);
      u.jam = u.jam.add(const Duration(minutes: 2));
      final lagi = await u.pesananMeja.AturMintaBill(uuidPesanan: pesanan.uuid, kasir: rina, minta: true);
      expect(lagi.mintaBillPada, DateTime.utc(2026, 9, 24, 1));
      final ubah = (await Outbox()).lastWhere((o) => o.Jenis == 'PesananTerbuka.Ubah');
      expect(Data(ubah), {
        'UuidPesanan': pesanan.uuid,
        'MintaBill': true,
        'UuidPengguna': rina.uuid,
        'DiubahPada': '2026-09-24T01:02:00.000Z',
      });

      // Bayar di perangkat ini → D-01 perlu dibersihkan.
      await u.pesananMeja.SimpanBaris(uuidPesanan: pesanan.uuid, draf: DrafContoh(), kasir: rina, kirimDapur: true);
      final keranjang = LayananPesananMeja.SusunKeranjangEfektif(
        Keranjang(pesananMeja: KonteksPesananMeja.DariPesanan((await u.repositoriMeja.CariPesanan(pesanan.uuid))!)),
        await u.repositoriMeja.CariPesanan(pesanan.uuid),
        katalog,
      );
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
        kasir: rina,
        k: k,
      );
      expect((await u.repositoriMeja.PantauMejaPerluDibersihkan().first).keys, [mejaD01]);

      // Snapshot server belum tahu pembayaran itu (penjualan masih tertunda) tetapi tahu T-01 kotor.
      u.server.penangan = (p) async => http.Response(
        jsonEncode({
          'Pesanan': <Object?>[],
          'Ditutup': <Object?>[],
          'MejaPerluDibersihkan': [
            {'UuidMeja': mejaT01, 'Sejak': '2026-09-24T00:30:00Z'},
          ],
        }),
        200,
        headers: {'content-type': 'application/json', 'etag': '"k1"'},
      );
      expect(await u.pesananMeja.Tarik(), isTrue);
      expect((await u.repositoriMeja.PantauMejaPerluDibersihkan().first).keys, unorderedEquals([mejaD01, mejaT01]));

      // T-01 ditandai bersih di perangkat ini; snapshot yang masih memuatnya tidak mengembalikannya, ETag tidak disimpan.
      await u.pesananMeja.TandaiMejaBersih(uuidMeja: mejaT01, kasir: rina);
      final bersih = (await Outbox()).lastWhere((o) => o.Jenis == 'Meja.Bersih');
      expect(Data(bersih), {
        'UuidMeja': mejaT01,
        'UuidPengguna': rina.uuid,
        'DibersihkanPada': '2026-09-24T01:02:00.000Z',
      });
      await u.pesananMeja.Tarik();
      expect((await u.repositoriMeja.PantauMejaPerluDibersihkan().first).keys, [mejaD01]);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.etagPesananTerbuka), '');

      // Semua terkirim: daftar server menjadi sumber kebenaran.
      await u.db.delete(u.db.outbox).go();
      u.server.penangan = (p) async => http.Response(
        jsonEncode({
          'Pesanan': <Object?>[],
          'Ditutup': [
            {'Uuid': pesanan.uuid, 'Status': 'Dibayar'},
          ],
          'MejaPerluDibersihkan': [
            {'UuidMeja': mejaD01, 'Sejak': '2026-09-24T01:02:00Z'},
          ],
        }),
        200,
        headers: {'content-type': 'application/json', 'etag': '"k2"'},
      );
      await u.pesananMeja.Tarik();
      expect(await u.repositoriMeja.PantauMejaPerluDibersihkan().first, {mejaD01: DateTime.utc(2026, 9, 24, 1, 2)});
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.etagPesananTerbuka), '"k2"');
    });

    test('K-13 kursus: Utama ditahan, kirim Pembuka tidak ikut mengirim Utama, Kirim Utama = ronde baru', () async {
      await Siapkan();
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
      final croissant = u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!);
      final kopi = u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!);

      await u.pesananMeja.SimpanBaris(
        uuidPesanan: pesanan.uuid,
        draf: [croissant],
        kasir: rina,
        kirimDapur: false,
        kursus: KursusPesanan.utama,
      );
      var lokal = (await u.repositoriMeja.CariPesanan(pesanan.uuid))!;
      expect(LayananPesananMeja.AmbilKursusDitahan(lokal.baris), [(kursus: KursusPesanan.utama, jumlah: 1)]);
      expect(lokal.baris.single.AmbilLabelStatus(), 'Ditahan | Utama');
      final tambah = (await Outbox()).lastWhere((o) => o.Jenis == 'PesananTerbuka.Tambah');
      expect(((Data(tambah)['Baris']! as List<Object?>).single! as Map<String, Object?>)['Kursus'], 'Utama');

      // Pembuka dikirim; Utama tetap ditahan.
      await u.pesananMeja.SimpanBaris(
        uuidPesanan: pesanan.uuid,
        draf: [kopi],
        kasir: rina,
        kirimDapur: true,
        kursus: KursusPesanan.pembuka,
      );
      expect((await Outbox()).where((o) => o.Jenis == 'PesananTerbuka.KirimDapur'), isEmpty);
      lokal = (await u.repositoriMeja.CariPesanan(pesanan.uuid))!;
      expect(lokal.baris.firstWhere((b) => b.uuid == croissant.uuid).dikirimKeDapur, isFalse);

      // Kirim Utama: hanya baris Utama, ronde berikutnya.
      final hasil = await u.pesananMeja.KirimKursus(
        uuidPesanan: pesanan.uuid,
        kursus: KursusPesanan.utama,
        kasir: rina,
      );
      expect(hasil.uuidBaris, [croissant.uuid]);
      final kirim = (await Outbox()).lastWhere((o) => o.Jenis == 'PesananTerbuka.KirimDapur');
      expect(Data(kirim)['UuidBaris'], [croissant.uuid]);
      expect(Data(kirim)['Ronde'], 3);
      final utama = hasil.pesanan.baris.firstWhere((b) => b.uuid == croissant.uuid);
      expect((utama.dikirimKeDapur, utama.ronde, utama.kursus), (true, 3, KursusPesanan.utama));
      expect(LayananPesananMeja.AmbilKursusDitahan(hasil.pesanan.baris), isEmpty);
      expect(
        () => u.pesananMeja.KirimKursus(uuidPesanan: pesanan.uuid, kursus: KursusPesanan.utama, kasir: rina),
        GalatDengan('TidakAdaItemBaru'),
      );
    });

    test('kunci bayar: 409 PesananSedangDibayar ditolak; offline tetap boleh bayar', () async {
      await Siapkan();
      u.server.penangan = (p) async => http.Response(
        jsonEncode({
          'Galat': {'Kode': 'PesananSedangDibayar', 'Pesan': 'Pesanan ini sedang dibayar di perangkat lain.'},
        }),
        409,
        headers: {'content-type': 'application/json'},
      );
      await expectLater(
        () => u.pesananMeja.KunciBayar('01K5PESANAN00000000LAIN001'),
        GalatDengan('PesananSedangDibayar'),
      );

      u.server.penangan = (p) async => throw http.ClientException('Tidak ada koneksi');
      await u.pesananMeja.KunciBayar('01K5PESANAN00000000LAIN001');
      expect(await u.pesananMeja.Tarik(), isFalse);
    });

    test('data meja: area, meja, dan mode meja tersimpan untuk kerja offline', () async {
      await u.SiapkanAktif();
      u.server.penangan = (p) async =>
          http.Response(jsonEncode(DataMejaUji()), 200, headers: {'content-type': 'application/json'});
      expect(await u.pesananMeja.PerbaruiDataMeja(), isTrue);
      expect((await u.repositoriMeja.PantauMeja().first).map((m) => m.Nama), ['D-01', 'D-02', 'T-01']);
      expect((await u.repositoriMeja.PantauArea().first).map((a) => a.Nama), ['Dalam', 'Teras']);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.modeMejaAktif), '1');
    });
  });

  group('v1.99 pisah tagihan & gabung meja', () {
    test('pisah: item terpilih pindah ke tagihan baru (outbox Buka lalu PindahBaris); semua dipilih ditolak', () async {
      await Siapkan();
      final pesanan = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01), jumlahTamu: 2);
      final isi = await u.pesananMeja.SimpanBaris(
        uuidPesanan: pesanan.uuid,
        draf: DrafContoh(),
        kasir: rina,
        kirimDapur: true,
      );
      final croissant = isi.baris.last.uuid;

      await expectLater(
        () => u.pesananMeja.Pisah(
          uuidAsal: pesanan.uuid,
          uuidBaris: isi.baris.map((b) => b.uuid).toList(),
          label: 'D-01 | Tagihan 2',
          kasir: rina,
          k: k,
        ),
        GalatDengan('SemuaDipilih'),
      );

      final hasil = await u.pesananMeja.Pisah(
        uuidAsal: pesanan.uuid,
        uuidBaris: [croissant],
        label: 'D-01 | Tagihan 2',
        kasir: rina,
        k: k,
      );
      expect(hasil.asal.AmbilBarisAktif().map((b) => b.namaProduk), ['Es Kopi Susu Aren']);
      expect(hasil.baru.AmbilJudul(), 'D-01 | Tagihan 2');
      expect(hasil.baru.baris.single.uuid, croissant);
      expect(hasil.baru.baris.single.dikirimKeDapur, isTrue, reason: 'Status dapur ikut pindah.');

      final outbox = await Outbox();
      expect(outbox[outbox.length - 2].Jenis, 'PesananTerbuka.Buka');
      final pindah = outbox.last;
      expect(pindah.Jenis, 'PesananTerbuka.PindahBaris');
      expect(Data(pindah), {
        'UuidPesanan': pesanan.uuid,
        'UuidTujuan': hasil.baru.uuid,
        'UuidBaris': [croissant],
        'TutupAsal': false,
        'UuidPengguna': rina.uuid,
        'DipindahPada': '2026-09-24T01:00:00.000Z',
      });
      // Kedua pesanan menunggu PindahBaris → snapshot server tidak menimpanya.
      expect(await u.repositoriMeja.AmbilUuidPesananTertunda(), containsAll([pesanan.uuid, hasil.baru.uuid]));
    });

    test('gabung: semua item pindah, asal ditutup Digabung & mejanya kosong; asal kosong ditolak', () async {
      await Siapkan();
      final d01 = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaD01));
      final t01 = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await Meja(mejaT01));
      await expectLater(
        () => u.pesananMeja.Gabung(uuidAsal: t01.uuid, uuidTujuan: d01.uuid, kasir: rina),
        GalatDengan('PesananKosong'),
      );
      await u.pesananMeja.SimpanBaris(uuidPesanan: t01.uuid, draf: DrafContoh(), kasir: rina, kirimDapur: false);

      final tujuan = await u.pesananMeja.Gabung(uuidAsal: t01.uuid, uuidTujuan: d01.uuid, kasir: rina);
      expect(tujuan.AmbilBarisAktif(), hasLength(2));
      expect((await u.repositoriMeja.CariPesanan(t01.uuid))!.status, StatusPesananMeja.digabung);
      expect(await u.repositoriMeja.CariPesananDiMeja(mejaT01), isNull, reason: 'Meja T-01 kosong lagi.');
      expect(Data((await Outbox()).last)['TutupAsal'], true);

      await expectLater(
        () => u.pesananMeja.Gabung(uuidAsal: t01.uuid, uuidTujuan: d01.uuid, kasir: rina),
        GalatDengan('PesananSudahDitutup'),
      );
      await expectLater(
        () => u.pesananMeja.PindahBaris(uuidAsal: d01.uuid, uuidTujuan: d01.uuid, uuidBaris: const ['x'], kasir: rina),
        GalatDengan('TujuanSama'),
      );
    });
  });
}
