import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:klien_api/KlienApi.dart' show DataAwal;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Meja/KonteksPesananMeja.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late StafLokal budi;

  Future<void> Siapkan({Map<String, Object?>? dataAwal, bool bukaShift = true}) async {
    await u.SiapkanAktif(dataAwal: dataAwal);
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    budi = await u.Staf('Budi Santoso');
    if (bukaShift) {
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    }
  }

  ProdukJual Produk(String uuid) => katalog.CariProduk(uuid)!;

  BarisMetodePembayaran Metode(String jenis) => k.metodePembayaran.firstWhere((m) => m.Jenis == jenis);

  /// 2× Es Kopi Susu Aren (kurang manis) + 1× Croissant = Rp 61.000 + PBJT 10% = Rp 67.100.
  Keranjang KeranjangContoh() {
    var keranjang = Keranjang.kosong;
    final gula = [PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol())];
    for (var i = 0; i < 2; i++) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.kopiSusu), pilihan: gula),
        katalog,
        k,
      );
    }
    return u.penjualan.TambahBaris(keranjang, u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.croissant)), katalog, k);
  }

  Future<Map<String, Object?>> AmbilDataOutbox(String uuid) async {
    final baris = (await u.db.select(u.db.outbox).get()).firstWhere((o) => o.Uuid == uuid);
    expect(baris.Jenis, 'Penjualan.Buat');
    return jsonDecode(baris.Data) as Map<String, Object?>;
  }

  setUp(() => u = LingkunganUji.Buat());
  tearDown(() => u.Tutup());

  group('katalog & keranjang', () {
    test('konteks dari data awal F-07b: outlet, perangkat, batas diskon, metode fase 1 saja', () async {
      await Siapkan(bukaShift: false);
      expect(k.kodeOutlet, 'SLB');
      expect(k.kodePerangkat, 'POS-001');
      expect(k.uuidOutlet, '01K50VT1ET0000000000000001');
      expect(k.batasDiskonManual, Decimal.parse('10'));
      expect(k.batasDiskonPenyetuju, Decimal.parse('30'));
      expect(k.metodePembayaran.map((m) => m.Jenis), ['Tunai', 'QrisStatis', 'Edc', 'Transfer', 'Ewallet']);
      expect(katalog.AmbilTampil().map((p) => p.nama), isNot(contains('Gula Aren Cair')));
    });

    test('produk sama (satuan & pilihan sama) → jumlah +1. Pilihan berbeda → baris baru', () async {
      await Siapkan(bukaShift: false);
      final keranjang = KeranjangContoh();
      expect(keranjang.baris, hasLength(2));
      expect(keranjang.baris.first.jumlah, Kuantitas.DariBulat(2));

      final lain = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(
          katalog,
          k,
          Produk(UuidUji.kopiSusu),
          pilihan: [PilihanTerpilih(uuid: UuidUji.gulaNormal, nama: 'Normal', harga: Uang.Nol())],
        ),
        katalog,
        k,
      );
      expect(lain.baris, hasLength(3));
    });

    test('pilihan wajib (min 1) & maksimal dicek. Harga pilihan ditambahkan ke harga satuan', () async {
      await Siapkan(bukaShift: false);
      expect(() => u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.kopiSusu)), GalatDengan('PilihanBelumLengkap'));
      expect(
        () => u.penjualan.BuatBaris(
          katalog,
          k,
          Produk(UuidUji.kopiSusu),
          pilihan: [
            PilihanTerpilih(uuid: UuidUji.gulaNormal, nama: 'Normal', harga: Uang.Nol()),
            PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol()),
          ],
        ),
        GalatDengan('PilihanTerlaluBanyak'),
      );

      final baris = u.penjualan.BuatBaris(
        katalog,
        k,
        Produk(UuidUji.kopiSusu),
        pilihan: [
          PilihanTerpilih(uuid: UuidUji.gulaNormal, nama: 'Normal', harga: Uang.Nol()),
          PilihanTerpilih(uuid: UuidUji.extraShot, nama: 'Extra shot', harga: Uang.DariBulat(5000)),
        ],
      );
      final hasil = u.penjualan.Hitung(Keranjang(baris: [baris]), k).hasil;
      expect(hasil.subtotal, Uang.DariBulat(23000));
    });

    test(
      'produk induk varian dan bahan baku ditolak dengan pesan jelas. Produk ber-batch kini bisa dijual (F-05g)',
      () async {
        await Siapkan(bukaShift: false);
        expect(() => u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.kaos)), GalatDengan('ProdukTidakBisaDijual'));
        expect(() => u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.gulaAren)), GalatDengan('ProdukTidakBisaDijual'));
        expect(u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.susuUht)).jumlah, Kuantitas.DariBulat(1));
        try {
          u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.kaos));
        } on GalatKasir catch (galat) {
          expect(galat.pesan, contains('Pilih salah satu variannya'));
        }
      },
    );

    test('harga lewat PenentuHarga: bertingkat saat jumlah naik, ganti satuan ke lusin, cari barcode & SKU', () async {
      await Siapkan(bukaShift: false);
      var keranjang = Keranjang(baris: [u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.roti))]);
      expect(keranjang.baris.single.hargaSatuan, Uang.DariBulat(12000));

      keranjang = u.penjualan.UbahJumlah(keranjang, keranjang.baris.single.uuid, Kuantitas.DariBulat(10), katalog, k);
      expect(keranjang.baris.single.hargaSatuan, Uang.DariBulat(11000), reason: 'Harga bertingkat mulai 10 pcs.');

      final lusin = Produk(UuidUji.roti).satuan.firstWhere((s) => s.nama == 'Lusin');
      keranjang = u.penjualan.GantiSatuan(keranjang, keranjang.baris.single.uuid, lusin, katalog, k);
      expect(keranjang.baris.single.hargaSatuan, Uang.DariBulat(130000));
      expect(keranjang.baris.single.namaSatuan, 'Lusin');

      expect(
        () => u.penjualan.UbahJumlah(keranjang, keranjang.baris.single.uuid, Kuantitas.Dari('1.5'), katalog, k),
        GalatDengan('JumlahTidakValid'),
      );
      keranjang = u.penjualan.UbahJumlah(keranjang, keranjang.baris.single.uuid, Kuantitas.Nol(), katalog, k);
      expect(keranjang.CekKosong, isTrue);

      final barcode = katalog.CariKode(UuidUji.barcodeRotiLusin)!;
      expect(barcode.produk.uuid, UuidUji.roti);
      expect(barcode.satuan!.nama, 'Lusin');
      expect(katalog.CariKode('amr-01')!.produk.nama, 'Americano Panas');
      expect(katalog.CariKode('0000'), isNull);
    });

    test('menghapus baris terakhir keranjang meja tetap terikat pesanan. Keranjang biasa kembali kosong', () async {
      await Siapkan(bukaShift: false);
      final biasa = Keranjang(baris: [u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.roti))]);
      expect(u.penjualan.HapusBaris(biasa, biasa.baris.single.uuid), same(Keranjang.kosong));

      const meja = KonteksPesananMeja(uuid: 'PSN1', nomor: 'PSN-1', uuidMeja: 'MJ1', namaMeja: 'Meja 1', label: null);
      final terikat = biasa.Salin(pesananMeja: () => meja);
      final hasil = u.penjualan.HapusBaris(terikat, terikat.baris.single.uuid);
      expect(hasil.baris, isEmpty);
      expect(hasil.pesananMeja?.uuid, 'PSN1', reason: 'Konteks meja tidak boleh hilang diam-diam.');
    });
  });

  group('penentuan pajak (CLAUDE.md #12)', () {
    test('PBJT dipungut bila outlet memungut PBJT; PPN tidak dihitung bila bukan PKP', () async {
      await Siapkan(bukaShift: false);
      final roti = u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.roti));
      final hitungan = u.penjualan.Hitung(Keranjang(baris: [...KeranjangContoh().baris, roti]), k);
      expect(hitungan.kodePajakBaris, [
        ['PbjtMakananMinuman'],
        ['PbjtMakananMinuman'],
        <String>[],
      ]);
      expect(hitungan.hasil.subtotal, Uang.DariBulat(73000));
      expect(hitungan.hasil.totalPajak, Uang.DariBulat(6100));
      expect(hitungan.hasil.totalAkhir, Uang.DariBulat(79100));
      expect(hitungan.peringatan, isEmpty);
    });

    test('PKP: PPN 12% DPP 11/12 dari tarif bertanggal. Tidak memungut PBJT → PBJT tidak dihitung', () async {
      await Siapkan(
        bukaShift: false,
        dataAwal: DataAwalUji(
          profilPajak: {
            'Pkp': true,
            'PungutPbjt': false,
            'HargaTermasukPajak': false,
            'BiayaLayanan': {'Aktif': false, 'Persen': '0'},
          },
        ),
      );
      final roti = u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.roti));
      final croissant = u.penjualan.BuatBaris(katalog, k, Produk(UuidUji.croissant));
      final hitungan = u.penjualan.Hitung(Keranjang(baris: [roti, croissant]), k);
      expect(hitungan.kodePajakBaris, [
        ['Ppn'],
        <String>[],
      ]);
      // DPP 12.000 × 11/12 = 11.000; PPN 12% = 1.320.
      expect(hitungan.hasil.totalPajak, Uang.DariBulat(1320));
      expect(hitungan.tarifDipakai['Ppn']!.tarif, '12.00');
    });

    test('tanpa tarif berlaku pada tanggal bisnis → pajak tidak dihitung + peringatan', () async {
      await Siapkan(
        bukaShift: false,
        dataAwal: DataAwalUji(
          tarifPajak: [
            {
              'KodeJenisPajak': 'PbjtMakananMinuman',
              'Tarif': '10.00',
              'PengaliDppPembilang': 1,
              'PengaliDppPenyebut': 1,
              'BerlakuMulai': '2027-01-01',
              'BerlakuSampai': null,
            },
            {
              'KodeJenisPajak': 'PbjtMakananMinuman',
              'Tarif': '11.00',
              'PengaliDppPembilang': 1,
              'PengaliDppPenyebut': 1,
              'BerlakuMulai': '2024-01-01',
              'BerlakuSampai': '2026-09-23',
            },
          ],
        ),
      );
      final hitungan = u.penjualan.Hitung(KeranjangContoh(), k);
      expect(hitungan.hasil.totalPajak, Uang.Nol());
      expect(hitungan.pajakDokumen, isEmpty);
      expect(hitungan.peringatan.single, contains('Tarif PBJT belum tersedia'));

      // Sehari sebelumnya tarif 11% masih berlaku (BerlakuSampai inklusif).
      u.jam = DateTime.utc(2026, 9, 23, 1);
      expect(u.penjualan.Hitung(KeranjangContoh(), k).hasil.totalPajak, Uang.Dari('6710.00'));
    });
  });

  group('BR-07.3 diskon manual', () {
    test('≤ batas manual boleh. Di atas batas butuh penyetuju. Di atas batas penyetuju hanya Pemilik', () async {
      await Siapkan(bukaShift: false);
      final dasar = Uang.DariBulat(100000);
      StatusDiskon Periksa(String persen, StafLokal kasir, {PenyetujuDiskon? penyetuju}) =>
          LayananPenjualan.PeriksaDiskon(
            dasar: dasar,
            diskon: Uang.DariDesimal(Decimal.parse(persen) * Decimal.fromInt(1000)),
            kasir: kasir,
            k: k,
            penyetuju: penyetuju,
          );

      expect(Periksa('10', rina), StatusDiskon.Boleh);
      expect(Periksa('15', rina), StatusDiskon.ButuhPenyetuju);
      expect(Periksa('35', rina), StatusDiskon.MelebihiBatas);
      final penyetujuBudi = PenyetujuDiskon(uuid: budi.uuid, nama: budi.nama, pemilik: false);
      expect(Periksa('15', rina, penyetuju: penyetujuBudi), StatusDiskon.Boleh);
      expect(Periksa('35', rina, penyetuju: penyetujuBudi), StatusDiskon.MelebihiBatas);
      expect(
        Periksa(
          '35',
          rina,
          penyetuju: const PenyetujuDiskon(uuid: 'P', nama: 'Pemilik', pemilik: true),
        ),
        StatusDiskon.Boleh,
      );
      expect(Periksa('20', budi), StatusDiskon.Boleh, reason: 'Budi berizin menyetujui diskon sendiri sampai 30%.');
    });

    test('PRD v1.46 (f): kasir tanpa penjualan.diskon.manual diarahkan ke PIN penyetuju, bukan ditolak', () async {
      await Siapkan(bukaShift: false);
      final sari = await u.Staf('Sari Lestari');
      expect(sari.PunyaIzin(IzinKasir.penjualanDiskonManual), isFalse);
      StatusDiskon Periksa(String jumlah, {PenyetujuDiskon? penyetuju}) => LayananPenjualan.PeriksaDiskon(
        dasar: Uang.DariBulat(100000),
        diskon: Uang.Dari(jumlah),
        kasir: sari,
        k: k,
        penyetuju: penyetuju,
      );

      // Bahkan di bawah batas manual (5%): tanpa penyetuju → butuh penyetuju (bukan galat TanpaIzin).
      expect(Periksa('5000'), StatusDiskon.ButuhPenyetuju);
      final penyetujuBudi = PenyetujuDiskon(uuid: budi.uuid, nama: budi.nama, pemilik: false);
      expect(Periksa('5000', penyetuju: penyetujuBudi), StatusDiskon.Boleh);
      expect(Periksa('25000', penyetuju: penyetujuBudi), StatusDiskon.Boleh);
      expect(Periksa('35000', penyetuju: penyetujuBudi), StatusDiskon.MelebihiBatas);
      expect(
        Periksa(
          '35000',
          penyetuju: const PenyetujuDiskon(uuid: 'P', nama: 'Pemilik', pemilik: true),
        ),
        StatusDiskon.Boleh,
      );
    });

    test(
      'PRD v1.46 (f): bayar oleh kasir tanpa izin diskon — tanpa penyetuju ditolak, dengan penyetuju tercatat',
      () async {
        await Siapkan();
        final sari = await u.Staf('Sari Lestari');
        var keranjang = KeranjangContoh().Salin(diskonPesanan: () => DiskonManual.DariPersen(Decimal.parse('5')));
        final tunai = PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000));

        await expectLater(
          u.penjualan.Bayar(keranjang: keranjang, pembayaran: [tunai], kasir: sari, k: k),
          GalatDengan('PersetujuanDiperlukan'),
        );

        keranjang = keranjang.Salin(
          penyetuju: () => PenyetujuDiskon(uuid: budi.uuid, nama: budi.nama, pemilik: false),
        );
        final hasil = await u.penjualan.Bayar(keranjang: keranjang, pembayaran: [tunai], kasir: sari, k: k);
        final data = await AmbilDataOutbox(hasil.uuid);
        expect(data['UuidPengguna'], sari.uuid);
        expect(data['UuidPenyetujuDiskon'], budi.uuid);
      },
    );

    test('PRD v1.46 (c): persen efektif = diskon hasil mesin (dibulatkan) ÷ bruto, bukan persen masukan', () async {
      await Siapkan();
      // Bruto Rp 4.995,45 × 30% = 1.498,635 → dibulatkan mesin 1.498,64 = 30,0001% > batas penyetuju 30%.
      final keranjang = Keranjang(
        baris: [
          ItemKeranjang(
            uuid: '01K5BARIS00000000000000001',
            uuidProduk: UuidUji.croissant,
            nama: 'Croissant Mentega',
            uuidProdukSatuan: UuidUji.psCroissant,
            namaSatuan: 'pcs',
            bolehDesimal: false,
            jumlah: Kuantitas.DariBulat(1),
            hargaSatuan: Uang.Dari('4995.45'),
          ),
        ],
      );
      final diskon = DiskonManual.DariPersen(Decimal.parse('30'));
      final nilai = u.penjualan.HitungDiskonBaris(keranjang, '01K5BARIS00000000000000001', diskon, k);
      expect(nilai.dasar, Uang.Dari('4995.45'));
      expect(nilai.diskon, Uang.Dari('1498.64'));
      final persen = LayananPenjualan.HitungPersenEfektif(nilai.dasar, nilai.diskon);
      expect(persen > Rational.fromInt(30), isTrue);
      expect(persen * Rational.fromInt(10000) ~/ Rational.one, BigInt.from(300001));

      // Budi (berizin menyetujui sampai 30%) tidak bisa menyetujui 30,0001%; hanya Pemilik.
      expect(
        LayananPenjualan.PeriksaDiskon(dasar: nilai.dasar, diskon: nilai.diskon, kasir: budi, k: k),
        StatusDiskon.MelebihiBatas,
      );
      expect(
        LayananPenjualan.PeriksaDiskon(
          dasar: nilai.dasar,
          diskon: nilai.diskon,
          kasir: budi,
          k: k,
          penyetuju: const PenyetujuDiskon(uuid: 'P', nama: 'Pemilik', pemilik: true),
        ),
        StatusDiskon.Boleh,
      );
      final dengan = keranjang.Salin(baris: [keranjang.baris.single.Salin(diskon: () => diskon)]);
      await expectLater(
        u.penjualan.Bayar(
          keranjang: dengan,
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(10000))],
          kasir: budi,
          k: k,
        ),
        GalatDengan('DiskonMelebihiBatas'),
      );

      // Batas tepat: diskon pesanan 10% dari subtotal Rp 61.000 = Rp 6.100 = 10% → Rina boleh tanpa penyetuju.
      final pesanan = u.penjualan.HitungDiskonPesanan(
        KeranjangContoh(),
        DiskonManual.DariPersen(Decimal.parse('10')),
        k,
      );
      expect(pesanan.dasar, Uang.DariBulat(61000));
      expect(pesanan.diskon, Uang.DariBulat(6100));
      expect(
        LayananPenjualan.PeriksaDiskon(dasar: pesanan.dasar, diskon: pesanan.diskon, kasir: rina, k: k),
        StatusDiskon.Boleh,
      );
    });

    test('bayar dengan diskon di atas batas tanpa penyetuju ditolak, dengan penyetuju tercatat di outbox', () async {
      await Siapkan();
      var keranjang = KeranjangContoh();
      keranjang = keranjang.Salin(diskonPesanan: () => DiskonManual.DariPersen(Decimal.parse('20')));
      final tunai = PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000));

      await expectLater(
        u.penjualan.Bayar(keranjang: keranjang, pembayaran: [tunai], kasir: rina, k: k),
        GalatDengan('PersetujuanDiperlukan'),
      );

      keranjang = keranjang.Salin(
        penyetuju: () => PenyetujuDiskon(uuid: budi.uuid, nama: budi.nama, pemilik: false),
      );
      final hasil = await u.penjualan.Bayar(keranjang: keranjang, pembayaran: [tunai], kasir: rina, k: k);
      final data = await AmbilDataOutbox(hasil.uuid);
      expect(data['DiskonManualPesanan'], {'Persen': '20'});
      expect(data['UuidPenyetujuDiskon'], budi.uuid);
      // 61.000 − 12.200 = 48.800 + PBJT 4.880 = 53.680.
      expect((data['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], '53680.00');
    });
  });

  group('bayar & simpan (BR-07.1, BR-07.4, BR-08.1, BR-08.6)', () {
    test('BR-07.4: tanpa shift terbuka tidak bisa menjual', () async {
      await Siapkan(bukaShift: false);
      await expectLater(
        u.penjualan.Bayar(
          keranjang: KeranjangContoh(),
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
          kasir: rina,
          k: k,
        ),
        GalatDengan('ShiftTidakDitemukan'),
      );
    });

    test(
      'tunai: payload outbox Penjualan.Buat persis kontrak F-07b. Penjualan, detail, pembayaran tersimpan',
      () async {
        await Siapkan();
        final shift = (await u.repositori.AmbilShiftAktif())!;
        final keranjang = KeranjangContoh();
        final hasil = await u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
          kasir: rina,
          k: k,
        );

        expect(hasil.nomor, 'INV/SLB/260924/POS-001-0001');
        expect(hasil.totalAkhir, Uang.DariBulat(67100));
        expect(hasil.kembalian, Uang.DariBulat(32900));

        final data = await AmbilDataOutbox(hasil.uuid);
        expect(data.keys.toList(), [
          'UuidShift',
          'UuidPengguna',
          'Nomor',
          'Kanal',
          'DibuatPada',
          'HargaTermasukPajak',
          'PersenBiayaLayanan',
          'PembulatanTunai',
          'Pajak',
          'Baris',
          'DiskonManualPesanan',
          'UuidPenyetujuDiskon',
          'Pembayaran',
          'Ringkasan',
          'Catatan',
        ]);
        expect(data['UuidShift'], shift.Uuid);
        expect(data['UuidPengguna'], rina.uuid);
        expect(data['Nomor'], 'INV/SLB/260924/POS-001-0001');
        expect(data['Kanal'], 'BawaPulang');
        expect(data['DibuatPada'], '2026-09-24T01:00:00.000Z');
        expect(data['HargaTermasukPajak'], isFalse);
        expect(data['PersenBiayaLayanan'], '0');
        expect(data['PembulatanTunai'], isNull);
        expect(data['Pajak'], [
          {
            'Kode': 'PbjtMakananMinuman',
            'Tarif': '10.00',
            'PengaliDppPembilang': 1,
            'PengaliDppPenyebut': 1,
            'DasarPengenaan': 'SubtotalPlusLayanan',
            // F-17 bagian 3: bendera ongkir kena pajak ikut di snapshot pajak dokumen.
            'KenaBiayaKirim': false,
          },
        ]);
        final baris = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(baris.first, {
          'Uuid': keranjang.baris.first.uuid,
          'UuidProduk': UuidUji.kopiSusu,
          'UuidProdukSatuan': UuidUji.psKopiSusu,
          'Jumlah': '2.0000',
          'HargaSatuan': '18000.00',
          'HargaPilihan': '0.00',
          'Pilihan': [
            {'UuidPilihan': UuidUji.gulaKurang, 'Nama': 'Kurang manis', 'Harga': '0.00'},
          ],
          'HargaTermasukPajak': null,
          'KodePajak': ['PbjtMakananMinuman'],
          'DiskonManual': null,
          'Catatan': null,
        });
        final bayar = (data['Pembayaran']! as List<Object?>).single! as Map<String, Object?>;
        expect(bayar['UuidMetodePembayaran'], '01K5MTD0000000000000000001');
        expect(bayar['Jumlah'], '100000.00', reason: 'Tunai: Jumlah = uang diterima.');
        expect(bayar['Referensi'], isNull);
        expect(PembuatUlid.CekValid(bayar['Uuid']! as String), isTrue);
        expect(data['Ringkasan'], {
          'Subtotal': '61000.00',
          'TotalPajak': '6100.00',
          'Pembulatan': '0.00',
          'TotalAkhir': '67100.00',
          'Kembalian': '32900.00',
        });
        expect(PembuatUlid.CekValid(hasil.uuid), isTrue, reason: 'BR-07.6: UuidKlien ULID dari perangkat.');
        // Server memvalidasi semua Uuid sebagai ULID (Rincian F-07b).
        final semuaUuid = [
          data['UuidShift'],
          data['UuidPengguna'],
          for (final b in baris) ...[b['Uuid'], b['UuidProduk'], b['UuidProdukSatuan']],
          for (final b in baris)
            for (final p in (b['Pilihan']! as List<Object?>).cast<Map<String, Object?>>()) p['UuidPilihan'],
          bayar['Uuid'],
          bayar['UuidMetodePembayaran'],
        ];
        for (final nilai in semuaUuid) {
          expect(PembuatUlid.CekValid(nilai! as String), isTrue, reason: '$nilai harus ULID');
        }

        final penjualan = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
        expect(penjualan.Status, 'Lunas');
        expect(penjualan.TanggalBisnis, '2026-09-24');
        expect(penjualan.TotalDibayar, '100000.00');
        final detail = await u.repositoriPenjualan.AmbilDetail(hasil.uuid);
        expect(detail.map((d) => d.TotalBaris), ['39600.00', '27500.00']);
        expect(detail.map((d) => d.JumlahPajak), ['3600.00', '2500.00']);
        expect((await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid)).single.NamaMetode, 'Tunai');
      },
    );

    test('BR-07.1: nomor urut per perangkat per hari. Hari berikutnya mulai dari 0001', () async {
      await Siapkan();
      Future<String> Jual() async => (await u.penjualan.Bayar(
        keranjang: KeranjangContoh(),
        pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(70000))],
        kasir: rina,
        k: k,
      )).nomor;

      expect(await Jual(), 'INV/SLB/260924/POS-001-0001');
      expect(await Jual(), 'INV/SLB/260924/POS-001-0002');
      u.jam = DateTime.utc(2026, 9, 25, 1);
      expect(await Jual(), 'INV/SLB/260925/POS-001-0001');
    });

    test('BR-08.1 split & BR-08.6 pembulatan hanya bagian tunai. Referensi EDC tersimpan', () async {
      await Siapkan(dataAwal: DataAwalUji(pembulatanTunai: {'Kelipatan': 1000, 'Arah': 'Bawah'}));
      final keranjang = KeranjangContoh();

      // Semua tunai: 67.100 → 67.000.
      expect(u.penjualan.HitungTagihanTunai(keranjang, k, const []), Uang.DariBulat(67000));

      // QRIS 20.000 + sisa tunai: bagian tunai 47.100 → 47.000.
      final qris = PembayaranMasukan(metode: Metode('QrisStatis'), jumlah: Uang.DariBulat(20000));
      expect(u.penjualan.HitungTagihanTunai(keranjang, k, [qris]), Uang.DariBulat(47000));

      final edc = PembayaranMasukan(metode: Metode('Edc'), jumlah: Uang.DariBulat(20000), referensi: 'BCA | 123456');
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          edc,
          PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000)),
        ],
        kasir: rina,
        k: k,
      );
      expect(hasil.totalAkhir, Uang.DariBulat(67000));
      expect(hasil.kembalian, Uang.DariBulat(3000));
      final data = await AmbilDataOutbox(hasil.uuid);
      expect(data['PembulatanTunai'], {'Kelipatan': 1000, 'Arah': 'Bawah'});
      expect((data['Ringkasan']! as Map<String, Object?>)['Pembulatan'], '-100.00');
      expect(((data['Pembayaran']! as List<Object?>).first! as Map<String, Object?>)['Referensi'], 'BCA | 123456');
    });

    test('pembayaran kurang, non-tunai melebihi total, dan tunai ganda ditolak', () async {
      await Siapkan();
      final keranjang = KeranjangContoh();
      await expectLater(
        u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000))],
          kasir: rina,
          k: k,
        ),
        GalatDengan('PembayaranKurang'),
      );
      await expectLater(
        u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [PembayaranMasukan(metode: Metode('Transfer'), jumlah: Uang.DariBulat(70000))],
          kasir: rina,
          k: k,
        ),
        GalatDengan('PembayaranMelebihiTotal'),
      );
      await expectLater(
        u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [
            PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000)),
            PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000)),
          ],
          kasir: rina,
          k: k,
        ),
        GalatDengan('TunaiGanda'),
      );
      expect(await u.db.select(u.db.penjualan).get(), isEmpty);
    });

    test('§18.3 no. 3: satu transaksi SQLite — outbox gagal → penjualan & nomor urut tidak tersimpan', () async {
      await Siapkan();
      final uuidTetap = u.penjualan.BuatUuid();
      final penjualan = LayananPenjualanUuidTetap(u, uuidTetap);
      // Entri outbox ber-Uuid sama sudah ada → insert outbox melanggar UNIQUE di akhir transaksi.
      await u.db
          .into(u.db.outbox)
          .insert(
            OutboxCompanion.insert(
              Uuid: uuidTetap,
              Jenis: 'Uji',
              Data: '{}',
              Status: StatusOutbox.tertunda,
              DibuatPada: u.jam,
              BerikutnyaPada: u.jam,
            ),
          );

      await expectLater(
        penjualan.Bayar(
          keranjang: KeranjangContoh(),
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(70000))],
          kasir: rina,
          k: k,
        ),
        throwsA(anything),
      );
      expect(await u.db.select(u.db.penjualan).get(), isEmpty);
      expect(await u.db.select(u.db.penjualanDetail).get(), isEmpty);
      expect(await u.db.select(u.db.penjualanPembayaran).get(), isEmpty);
      expect(await u.db.select(u.db.nomorUrutPenjualan).get(), isEmpty, reason: 'Nomor urut tidak terpakai.');
    });

    test('data awal tanpa kode outlet (server lama) → penjualan ditolak dengan pesan jelas', () async {
      final lama = DataAwalUji()..remove('Outlet');
      await Siapkan(dataAwal: lama);
      await u.repositori.SimpanPengaturan(KunciPengaturan.kodeOutlet, '');
      k = await u.MuatKonteks();
      await expectLater(
        u.penjualan.Bayar(
          keranjang: KeranjangContoh(),
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(70000))],
          kasir: rina,
          k: k,
        ),
        GalatDengan('DataAwalBelumLengkap'),
      );
    });
  });

  group('pesanan tertahan & riwayat', () {
    test('tahan → daftar lokal (tidak ke outbox) → buka mengembalikan keranjang utuh', () async {
      await Siapkan();
      final keranjang = KeranjangContoh().Salin(catatan: () => 'Meja teras');
      await u.penjualan.TahanPesanan(keranjang, rina, Uang.DariBulat(67100));

      final daftar = await u.db.select(u.db.pesananTertahan).get();
      expect(daftar.single.Label, startsWith('Es Kopi Susu Aren +1 | '));
      expect(await u.db.select(u.db.outbox).get(), hasLength(1), reason: 'Hanya Shift.Buka. Pesanan tertahan lokal.');

      final dibuka = await u.penjualan.BukaPesanan(daftar.single.Uuid);
      expect(dibuka.baris.map((b) => b.nama), keranjang.baris.map((b) => b.nama));
      expect(dibuka.baris.first.pilihan.single.nama, 'Kurang manis');
      expect(dibuka.catatan, 'Meja teras');
      expect(await u.db.select(u.db.pesananTertahan).get(), isEmpty);
    });

    test('riwayat hari ini dengan status sinkron dari outbox', () async {
      await Siapkan();
      final hasil = await u.penjualan.Bayar(
        keranjang: KeranjangContoh(),
        pembayaran: [PembayaranMasukan(metode: Metode('QrisStatis'), jumlah: Uang.DariBulat(67100))],
        kasir: rina,
        k: k,
      );
      var riwayat = await u.repositoriPenjualan.PantauRiwayat('2026-09-24').first;
      expect(riwayat.single.status.name, 'BelumTerkirim');
      expect(riwayat.single.metode, ['QRIS']);

      await u.repositori.TandaiPerluTindakan(hasil.uuid, 'HitunganTidakCocok', 'Total berbeda.');
      riwayat = await u.repositoriPenjualan.PantauRiwayat('2026-09-24').first;
      expect(riwayat.single.status.name, 'PerluTindakan');

      await u.repositori.HapusOutbox([hasil.uuid]);
      riwayat = await u.repositoriPenjualan.PantauRiwayat('2026-09-24').first;
      expect(riwayat.single.status.name, 'Terkirim');
    });
  });

  group('PRD v1.46 tindak lanjut tinjauan', () {
    test('(d) tanggal bisnis & YYMMDD memakai Outlet.ZonaWaktu, bukan zona perangkat', () async {
      await Siapkan(dataAwal: DataAwalUji(zonaWaktu: 'Asia/Makassar'));
      expect(k.zonaWaktu, 'Asia/Makassar');
      // 15:30 UTC = 23:30 WITA tanggal 24; perangkat ber-zona UTC (atau WIB) tidak berpengaruh.
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 15, 30)), '2026-09-24');
      // 16:30 UTC = 00:30 WITA tanggal 25 (JamTutupBuku 00:00).
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 16, 30)), '2026-09-25');

      u.jam = DateTime.utc(2026, 9, 24, 16, 30);
      final hasil = await u.penjualan.Bayar(
        keranjang: KeranjangContoh(),
        pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
        kasir: rina,
        k: k,
      );
      expect(hasil.nomor, 'INV/SLB/260925/POS-001-0001');
      expect((await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!.TanggalBisnis, '2026-09-25');
    });

    test('(d) sebelum JamTutupBuku waktu outlet masih tanggal bisnis kemarin; WIT & zona tak dikenal', () async {
      await Siapkan(
        bukaShift: false,
        dataAwal: DataAwalUji(zonaWaktu: 'Asia/Makassar', jamTutupBuku: '03:00'),
      );
      // 18:30 UTC = 02:30 WITA tanggal 25 < 03:00 → tanggal bisnis 24; 19:30 UTC = 03:30 WITA → 25.
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 18, 30)), '2026-09-24');
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 19, 30)), '2026-09-25');

      await Siapkan(bukaShift: false, dataAwal: DataAwalUji(zonaWaktu: 'Asia/Jayapura'));
      // 15:30 UTC = 00:30 WIT tanggal 25.
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 15, 30)), '2026-09-25');

      await Siapkan(bukaShift: false, dataAwal: DataAwalUji(zonaWaktu: 'Europe/Berlin'));
      expect(k.zonaWaktu, ZonaWaktuOutlet.bawaan, reason: 'Zona tidak dikenal → UTC+7 (WIB).');
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 16, 30)), '2026-09-24');
      expect(k.HitungTanggalBisnis(DateTime.utc(2026, 9, 24, 17, 30)), '2026-09-25');
    });

    test('(e) nomor urut dari data-awal: sekuens lokal = max(lokal, server) per tanggal', () async {
      await Siapkan(dataAwal: DataAwalUji(nomorUrutPenjualan: {'260924': 7, '260923': 40, 'bukan-tanggal': 9}));
      final tunai = PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000));
      final pertama = await u.penjualan.Bayar(keranjang: KeranjangContoh(), pembayaran: [tunai], kasir: rina, k: k);
      expect(pertama.nomor, 'INV/SLB/260924/POS-001-0008', reason: 'Pemasangan ulang tidak memakai nomor 0001–0007.');

      // Data-awal berikutnya dengan nomor server lebih kecil tidak menurunkan sekuens lokal.
      await u.repositori.SimpanDataAwal(DataAwal.DariJson(DataAwalUji(nomorUrutPenjualan: {'260924': 3})), u.jam);
      final kedua = await u.penjualan.Bayar(keranjang: KeranjangContoh(), pembayaran: [tunai], kasir: rina, k: k);
      expect(kedua.nomor, 'INV/SLB/260924/POS-001-0009');

      final urut = await u.db.select(u.db.nomorUrutPenjualan).get();
      expect({for (final n in urut) n.Tanggal: n.Terakhir}, {'260924': 9, '260923': 40});
    });

    test('kategori jenis pajak: syarat PKP/PBJT dari Kategori bila ada, fallback ke kode lama', () async {
      await Siapkan(
        bukaShift: false,
        dataAwal: DataAwalUji(
          tarifPajak: [
            {
              'KodeJenisPajak': 'PpnKhusus',
              'Tarif': '12.00',
              'PengaliDppPembilang': 11,
              'PengaliDppPenyebut': 12,
              'BerlakuMulai': '2025-01-01',
              'BerlakuSampai': null,
            },
            {
              'KodeJenisPajak': 'PbjtMakananMinuman',
              'Tarif': '10.00',
              'PengaliDppPembilang': 1,
              'PengaliDppPenyebut': 1,
              'BerlakuMulai': '2024-01-01',
              'BerlakuSampai': null,
            },
          ],
        ),
      );
      ItemKeranjang Baris(String uuid, PajakProduk pajak) => ItemKeranjang(
        uuid: uuid,
        uuidProduk: UuidUji.roti,
        nama: 'Roti Tawar Gandum',
        uuidProdukSatuan: null,
        namaSatuan: null,
        bolehDesimal: false,
        jumlah: Kuantitas.DariBulat(1),
        hargaSatuan: Uang.DariBulat(12000),
        pajak: [pajak],
      );
      final keranjang = Keranjang(
        baris: [
          // Kode baru berkategori PPN: outlet bukan PKP → tidak dipungut.
          Baris(
            '01K5BARIS00000000000000001',
            const PajakProduk(kode: 'PpnKhusus', dasarPengenaan: 'Subtotal', kategori: 'Ppn'),
          ),
          // Kode lama tanpa Kategori → fallback: PbjtMakananMinuman = PBJT, outlet memungut PBJT.
          Baris(
            '01K5BARIS00000000000000002',
            const PajakProduk(kode: 'PbjtMakananMinuman', dasarPengenaan: 'Subtotal'),
          ),
        ],
      );
      final hitungan = u.penjualan.Hitung(keranjang, k);
      expect(hitungan.kodePajakBaris, [
        <String>[],
        ['PbjtMakananMinuman'],
      ]);
      expect(hitungan.labelPajak, {'PbjtMakananMinuman': 'PBJT'});

      await Siapkan(
        bukaShift: false,
        dataAwal: DataAwalUji(
          profilPajak: {
            'Pkp': true,
            'PungutPbjt': false,
            'HargaTermasukPajak': false,
            'BiayaLayanan': {'Aktif': false, 'Persen': '0'},
          },
          tarifPajak: [
            {
              'KodeJenisPajak': 'PpnKhusus',
              'Tarif': '12.00',
              'PengaliDppPembilang': 11,
              'PengaliDppPenyebut': 12,
              'BerlakuMulai': '2025-01-01',
              'BerlakuSampai': null,
            },
          ],
        ),
      );
      final pkp = u.penjualan.Hitung(keranjang, k);
      expect(pkp.kodePajakBaris, [
        ['PpnKhusus'],
        <String>[],
      ]);
      expect(pkp.labelPajak, {'PpnKhusus': 'PPN'});
      expect(pkp.hasil.totalPajak, Uang.DariBulat(1320));
    });

    test('Referensi EDC "bank | approval" tidak pernah melebihi 100 karakter (batas server)', () {
      final bank = 'B' * 60;
      final approval = '9' * 80;
      final referensi = LayananPenjualan.SusunReferensiEdc(bank, approval);
      expect(referensi.length, lessThanOrEqualTo(LayananPenjualan.panjangMaksReferensi));
      expect(referensi, '${'B' * 40} | ${'9' * 56}');
      expect(
        LayananPenjualan.panjangMaksBankEdc + 3 + LayananPenjualan.panjangMaksApprovalEdc,
        lessThanOrEqualTo(LayananPenjualan.panjangMaksReferensi),
      );
      expect(LayananPenjualan.SusunReferensiEdc('  ', ' 123456 '), '123456');
      expect(LayananPenjualan.SusunReferensiEdc('BCA', '123456'), 'BCA | 123456');
    });

    test('Referensi pembayaran > 100 karakter ditolak sebelum disimpan', () async {
      await Siapkan(bukaShift: false);
      expect(
        () => LayananPenjualan.ValidasiPembayaran([
          PembayaranMasukan(metode: Metode('Transfer'), jumlah: Uang.DariBulat(1000), referensi: 'R' * 101),
        ]),
        GalatDengan('ReferensiTerlaluPanjang'),
      );
      LayananPenjualan.ValidasiPembayaran([
        PembayaranMasukan(metode: Metode('Transfer'), jumlah: Uang.DariBulat(1000), referensi: 'R' * 100),
      ]);
    });
  });

  group('F-18 staf pelayan baris (komisi)', () {
    test(
      'baris dengan staf mengirim Baris.Staf, tanpa staf tidak ada kunci. Keranjang tertahan menyimpan staf',
      () async {
        await Siapkan();
        final dasar = KeranjangContoh();
        final keranjang = dasar.Salin(
          baris: [
            dasar.baris.first.Salin(staf: ['01K5KRY0000000000000000001', '01K5KRY0000000000000000002']),
            ...dasar.baris.skip(1),
          ],
        );
        expect(keranjang.baris.first.CekBisaDigabung(dasar.baris.first), isFalse);
        final hasil = await u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
          kasir: rina,
          k: k,
        );
        final baris = ((await AmbilDataOutbox(hasil.uuid))['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(baris.first['Staf'], ['01K5KRY0000000000000000001', '01K5KRY0000000000000000002']);
        expect(baris.last.containsKey('Staf'), isFalse);
        expect(
          ItemKeranjang.DariJson(jsonDecode(jsonEncode(keranjang.baris.first.KeJson())) as Map<String, Object?>).staf,
          ['01K5KRY0000000000000000001', '01K5KRY0000000000000000002'],
        );
      },
    );
  });

  group('F-12 penjualan tempo (BR-12.1)', () {
    const toko = PelangganTerpilih(
      uuid: '01K5PELANGGAN0000000000009',
      nama: 'Toko Makmur Jaya',
      noHpSamar: '0813****0001',
      limitKredit: '80000.00',
      sisaPiutang: '20000.00',
      hariLewatJatuhTempo: 0,
    );

    test('alasan butuh penyetuju: tanpa limit, melebihi limit, lewat jatuh tempo di atas batas', () {
      expect(LayananPenjualan.PeriksaTempo(pelanggan: toko, jumlah: Uang.DariBulat(60000), batasHariLewat: 0), isEmpty);
      expect(LayananPenjualan.PeriksaTempo(pelanggan: toko, jumlah: Uang.DariBulat(60001), batasHariLewat: 0), [
        'piutang Rp 80.001 melebihi limit Rp 80.000',
      ]);
      const tanpaLimit = PelangganTerpilih(uuid: 'X', nama: 'Warung Bu Sri', noHpSamar: '0812****0002');
      expect(LayananPenjualan.PeriksaTempo(pelanggan: tanpaLimit, jumlah: Uang.DariBulat(1), batasHariLewat: 0), [
        'pelanggan belum punya limit kredit',
      ]);
      const lewat = PelangganTerpilih(
        uuid: 'Y',
        nama: 'CV Karya',
        noHpSamar: '0812****0003',
        limitKredit: '5000000.00',
        sisaPiutang: '0.00',
        hariLewatJatuhTempo: 20,
      );
      expect(LayananPenjualan.PeriksaTempo(pelanggan: lewat, jumlah: Uang.DariBulat(1), batasHariLewat: 14), [
        'ada piutang lewat jatuh tempo 20 hari',
      ]);
      expect(LayananPenjualan.PeriksaTempo(pelanggan: lewat, jumlah: Uang.DariBulat(1), batasHariLewat: 30), isEmpty);
    });

    test('tempo wajib pelanggan. Di luar limit tanpa penyetuju ditolak, dengan PIN tercatat di outbox', () async {
      await Siapkan(dataAwal: DataAwalUji(tempo: true));
      expect(k.metodePembayaran.map((m) => m.Jenis), contains('Tempo'));
      final keranjang = KeranjangContoh();
      final tempo = [PembayaranMasukan(metode: Metode('Tempo'), jumlah: Uang.Dari('67100'))];

      await expectLater(
        u.penjualan.Bayar(keranjang: keranjang, pembayaran: tempo, kasir: rina, k: k),
        GalatDengan('TempoTanpaPelanggan'),
      );
      final denganToko = keranjang.Salin(pelanggan: () => toko);
      await expectLater(
        u.penjualan.Bayar(keranjang: denganToko, pembayaran: tempo, kasir: rina, k: k),
        GalatDengan('PersetujuanTempoDiperlukan'),
      );
      await expectLater(
        u.penjualan.Bayar(
          keranjang: denganToko,
          pembayaran: [
            PembayaranMasukan(metode: Metode('Tempo'), jumlah: Uang.DariBulat(30000)),
            PembayaranMasukan(metode: Metode('Tempo'), jumlah: Uang.Dari('37100')),
          ],
          kasir: rina,
          k: k,
          uuidPenyetujuTempo: budi.uuid,
        ),
        GalatDengan('TempoGanda'),
      );
      expect(await u.db.select(u.db.penjualan).get(), isEmpty);

      await u.repositoriPelanggan.Simpan(
        toko.uuid,
        toko.nama,
        toko.noHpSamar,
        DateTime.utc(2026, 9, 25),
        kredit: (limitKredit: '80000.00', sisaPiutang: '20000.00', hariLewatJatuhTempo: 0),
      );
      final hasil = await u.penjualan.Bayar(
        keranjang: denganToko,
        pembayaran: tempo,
        kasir: rina,
        k: k,
        uuidPenyetujuTempo: budi.uuid,
      );
      final data = await AmbilDataOutbox(hasil.uuid);
      expect(data['UuidPelanggan'], toko.uuid);
      expect(data['UuidPenyetujuTempo'], budi.uuid);
      expect(((data['Pembayaran']! as List<Object?>).single! as Map<String, Object?>)['Jumlah'], '67100.00');
      // Cache kredit ikut bertambah agar cek offline berikutnya menghitung penjualan ini.
      expect((await u.repositoriPelanggan.AmbilTerakhir()).single.SisaPiutang, '87100.00');
    });

    test('dalam limit tanpa penyetuju: outbox tanpa UuidPenyetujuTempo', () async {
      await Siapkan(dataAwal: DataAwalUji(tempo: true, batasHariLewatJatuhTempo: 7));
      expect(k.batasHariLewatJatuhTempo, 7);
      final keranjang = KeranjangContoh().Salin(
        pelanggan: () => const PelangganTerpilih(
          uuid: '01K5PELANGGAN0000000000009',
          nama: 'Toko Makmur Jaya',
          noHpSamar: '0813****0001',
          limitKredit: '5000000.00',
          sisaPiutang: '0.00',
          hariLewatJatuhTempo: 5,
        ),
      );
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: Metode('Tempo'), jumlah: Uang.Dari('67100'))],
        kasir: rina,
        k: k,
      );
      final data = await AmbilDataOutbox(hasil.uuid);
      expect(data.containsKey('UuidPenyetujuTempo'), isFalse);
    });
  });
}

/// Layanan penjualan dengan UuidKlien tetap (untuk memaksa bentrok outbox).
LayananPenjualan LayananPenjualanUuidTetap(LingkunganUji u, String uuid) => LayananPenjualan(
  repositori: u.repositori,
  repositoriPenjualan: u.repositoriPenjualan,
  ulid: _UlidTetap(uuid),
  jam: () => u.jam,
);

class _UlidTetap extends PembuatUlid {
  _UlidTetap(this.nilai);

  final String nilai;
  var _pertama = true;

  @override
  String Buat() {
    if (_pertama) {
      _pertama = false;
      return nilai;
    }
    return super.Buat();
  }
}
