import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPesananOnline.dart';
import 'package:kasir/Domain/Penjualan/LayananPreOrder.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-17 di perangkat: pesanan toko online dimuat ke keranjang kanal `Online` dengan harga saat dipesan; pesanan yang
/// sudah dibayar di muka membawa uang mukanya sehingga `Penjualan.Buat` memakai metode Uang Muka dan merujuk
/// `UuidPesananOnline` (bukan `UuidPesananPenjualan`). Pesanan berongkir ikut membawa ongkirnya (F-17 bagian 3), jadi
/// `TotalAkhir` penjualan sama dengan total pesanan yang dilihat pembeli.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  /// @param ongkir dan @param sisaUangMuka string desimal seperti kiriman server.
  Map<String, Object?> Pesanan({
    String status = 'Siap',
    String jenisPemenuhan = 'AmbilSendiri',
    String ongkir = '0.00',
    String diskonOngkir = '0.00',
    String sisaUangMuka = '28000.00',
    String total = '28000.00',
    bool sudahDibayar = true,
    Map<String, Object?>? voucher,
  }) => {
    'Uuid': '01K5PESANANONLINE000000001',
    'Nomor': 'ON/SLB/260930-0001',
    'NamaPelanggan': 'Bu Ratna',
    'JenisPemenuhan': jenisPemenuhan,
    'MetodePembayaran': sudahDibayar ? 'QrisOnline' : 'BayarSaatAmbil',
    'Status': status,
    'Subtotal': '28000.00',
    'Ongkir': ongkir,
    'DiskonOngkir': diskonOngkir,
    'Total': total,
    'SudahDibayar': sudahDibayar,
    'SisaUangMuka': sisaUangMuka,
    'Catatan': 'Tolong tanpa gula',
    'DibuatPada': '2026-09-30T02:00:00Z',
    'Voucher': voucher,
    'Baris': [
      {
        'UuidProduk': UuidUji.americano,
        'UuidProdukSatuan': null,
        'NamaProduk': 'Americano Panas',
        'Jumlah': '2.0000',
        'HargaSatuan': '14000.00',
        'HargaPilihan': '0.00',
        'Pilihan': <Object?>[],
        'Catatan': null,
      },
    ],
  };

  Map<String, Object?> Balasan(Map<String, Object?> pesanan, {bool adaMetode = true}) => {
    'Pesanan': [pesanan],
    'MetodeUangMuka': adaMetode ? {'Uuid': '01K5METODEUANGMUKA00000001', 'Nama': 'Uang muka (DP)'} : null,
  };

  Future<Map<String, Object?>> BacaOutboxTerakhir() async {
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    return jsonDecode(outbox.last.Data) as Map<String, Object?>;
  }

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('offline ditolak jelas, lalu daftar pesanan aktif dibaca dari server', () async {
    u.server.penangan = (_) async => throw http.ClientException('offline');
    await expectLater(
      u.pesananOnline.AmbilAktif(),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
    );

    u.server.penangan = (_) async => JsonUji(Balasan(Pesanan()));
    final hasil = await u.pesananOnline.AmbilAktif();
    expect(hasil.pesanan.single.nomor, 'ON/SLB/260930-0001');
    expect(hasil.pesanan.single.sudahDibayar, isTrue);
    expect(hasil.uuidMetodeUangMuka, '01K5METODEUANGMUKA00000001');
  });

  test('pesanan berbayar dimuat ke keranjang Online dan ditagih lewat metode Uang muka', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    u.server.penangan = (_) async => JsonUji(Balasan(Pesanan()));
    final hasil = await u.pesananOnline.AmbilAktif();

    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.kanal, KanalPenjualan.Online);
    expect(keranjang.baris.single.hargaSatuan, Uang.DariBulat(14000), reason: 'Harga saat dipesan.');
    expect(keranjang.baris.single.jumlah, Kuantitas.DariBulat(2));
    expect(keranjang.praPesan?.sumber, SumberUangMuka.pesananOnline);
    expect(keranjang.praPesan?.sisaUangMuka, Uang.DariBulat(28000));
    // Draf keranjang yang disimpan & dibaca ulang tidak boleh berubah sumber uang mukanya.
    expect(Keranjang.DariJson(keranjang.KeJson()).praPesan?.sumber, SumberUangMuka.pesananOnline);

    final uangMuka = LayananPreOrder.MetodeUangMuka(keranjang.praPesan!);
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    // Uang muka yang dipakai tidak boleh melebihi sisa yang dicatat server, meski masih di bawah total belanja.
    await expectLater(
      u.penjualan.Bayar(
        keranjang: keranjang.Salin(
          praPesan: () => PraPesananKeranjang(
            uuid: keranjang.praPesan!.uuid,
            nomor: keranjang.praPesan!.nomor,
            sisaUangMuka: Uang.DariBulat(10000),
            uuidMetode: keranjang.praPesan!.uuidMetode,
            namaMetode: keranjang.praPesan!.namaMetode,
            sumber: SumberUangMuka.pesananOnline,
          ),
        ),
        pembayaran: [PembayaranMasukan(metode: uangMuka, jumlah: total)],
        kasir: rina,
        k: k,
      ),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'UangMukaMelebihiSisa')),
    );

    // Uang muka menutup sebagian; sisanya ditagih tunai seperti pengambilan pre-order.
    final sisa = keranjang.praPesan!.sisaUangMuka;
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(metode: uangMuka, jumlah: sisa),
        PembayaranMasukan(metode: tunai, jumlah: total.Kurangi(sisa)),
      ],
      kasir: rina,
      k: k,
    );
    final data = await BacaOutboxTerakhir();
    expect(data['UuidPesananOnline'], '01K5PESANANONLINE000000001');
    expect(data.containsKey('UuidPesananPenjualan'), isFalse, reason: 'Satu sumber uang muka saja.');
    expect(data['Kanal'], 'Online');
  });

  test('v3.46: voucher checkout ikut dimuat ke keranjang tanpa memesan ulang, kodenya terkirim di outbox', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final voucher = {
      'Kode': 'HEMAT5K',
      'UuidPromo': '01K5PROMO00000000000000009',
      'NamaPromo': 'Voucher hemat Rp 5.000',
      'Promo': {
        'Uuid': '01K5PROMO00000000000000009',
        'Kode': 'VCR-HEMAT',
        'Nama': 'Voucher hemat Rp 5.000',
        'Prioritas': 0,
        'Eksklusif': false,
        'MulaiPada': null,
        'SelesaiPada': null,
        'KuotaTersisa': null,
        'Definisi': {
          'WajibVoucher': true,
          'Aksi': {'Jenis': 'DiskonTetapPesanan', 'Jumlah': '5000'},
        },
      },
    };
    u.server.penangan = (_) async =>
        JsonUji(Balasan(Pesanan(sudahDibayar: false, sisaUangMuka: '0.00', voucher: voucher), adaMetode: false));
    final hasil = await u.pesananOnline.AmbilAktif();
    expect(hasil.pesanan.single.voucher?.kode, 'HEMAT5K');
    final jumlahPermintaan = u.server.permintaan.length;

    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.voucher?.kode, 'HEMAT5K');
    expect(keranjang.voucher?.uuidPromo, '01K5PROMO00000000000000009');
    expect(u.server.permintaan.length, jumlahPermintaan, reason: 'Voucher sudah dipesan server atas pesanan ini.');

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    final jual = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: total)],
      kasir: rina,
      k: k,
    );
    expect(jual.uuid, keranjang.voucher?.uuidPenjualan, reason: 'Penjualan memakai Uuid yang dibawa voucher.');
    final data = await BacaOutboxTerakhir();
    expect(data['UuidPesananOnline'], '01K5PESANANONLINE000000001');
    expect(data['Voucher'], 'HEMAT5K');
  });

  test('pesanan COD tanpa uang muka tetap bisa ditagih penuh di kasir', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    u.server.penangan = (_) async =>
        JsonUji(Balasan(Pesanan(sudahDibayar: false, sisaUangMuka: '0.00'), adaMetode: false));
    final hasil = await u.pesananOnline.AmbilAktif();

    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.praPesan?.sisaUangMuka, Uang.Nol());
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: total)],
      kasir: rina,
      k: k,
    );
    final data = await BacaOutboxTerakhir();
    expect(data['UuidPesananOnline'], '01K5PESANANONLINE000000001');
  });

  test('F-17 bagian 3: pesanan kirim berongkir ditagih beserta ongkirnya, ongkir masuk total & outbox', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();

    u.server.penangan = (_) async => JsonUji(
      Balasan(Pesanan(jenisPemenuhan: 'Kirim', ongkir: '12000.00', sisaUangMuka: '40000.00', total: '40000.00')),
    );
    final hasil = await u.pesananOnline.AmbilAktif();
    expect(LayananPesananOnline.AlasanBelumBisaDitagih(hasil.pesanan.single), isNull);

    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.biayaKirim, Uang.DariBulat(12000));
    expect(keranjang.diskonKirim, Uang.Nol());
    expect(keranjang.HitungBiayaKirimNetto(), Uang.DariBulat(12000));
    // Draf keranjang yang disimpan & dibaca ulang tidak boleh kehilangan ongkirnya.
    expect(Keranjang.DariJson(keranjang.KeJson()).biayaKirim, Uang.DariBulat(12000));

    final hitungan = u.penjualan.Hitung(keranjang, k);
    // 2 x 14.000 barang + 2.800 pajak 10% atas barang + 12.000 ongkir. Ongkirnya TIDAK menambah DPP karena kelompok
    // pajak katalog uji tidak berbendera KenaBiayaKirim — itu justru yang dijaga di sini.
    expect(hitungan.hasil.subtotal, Uang.DariBulat(28000));
    expect(hitungan.hasil.biayaKirim, Uang.DariBulat(12000));
    expect(hitungan.hasil.totalPajak, Uang.DariBulat(2800));
    expect(hitungan.hasil.totalAkhir, Uang.DariBulat(42800));

    final uangMuka = LayananPreOrder.MetodeUangMuka(keranjang.praPesan!);
    final sisa = keranjang.praPesan!.sisaUangMuka;
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(metode: uangMuka, jumlah: sisa),
        PembayaranMasukan(metode: tunai, jumlah: hitungan.hasil.totalAkhir.Kurangi(sisa)),
      ],
      kasir: rina,
      k: k,
    );
    final data = await BacaOutboxTerakhir();
    expect(data['BiayaKirim'], '12000.00');
    expect(data.containsKey('DiskonKirim'), isFalse, reason: 'Tanpa diskon ongkir, kuncinya tidak dikirim.');
    expect((data['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], '42800.00');
    expect(data['UuidPesananOnline'], '01K5PESANANONLINE000000001');
  });

  test(
    'F-16c gratis ongkir: pesanan membawa DiskonOngkir ke keranjang. Outbox memuat ongkir kotor + diskonnya',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();

      // Ongkir zona Rp 12.000 digratiskan promo di checkout: pembeli membayar di muka Rp 30.800 (barang + pajak).
      u.server.penangan = (_) async => JsonUji(
        Balasan(
          Pesanan(
            jenisPemenuhan: 'Kirim',
            ongkir: '12000.00',
            diskonOngkir: '12000.00',
            sisaUangMuka: '30800.00',
            total: '30800.00',
          ),
        ),
      );
      final hasil = await u.pesananOnline.AmbilAktif();
      expect(hasil.pesanan.single.diskonOngkir, '12000.00');

      final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
      expect(keranjang.biayaKirim, Uang.DariBulat(12000));
      expect(keranjang.diskonKirim, Uang.DariBulat(12000));
      expect(keranjang.HitungBiayaKirimNetto(), Uang.Nol());
      // Draf keranjang yang disimpan & dibaca ulang tidak boleh kehilangan diskon ongkirnya.
      expect(Keranjang.DariJson(keranjang.KeJson()).diskonKirim, Uang.DariBulat(12000));

      final hitungan = u.penjualan.Hitung(keranjang, k);
      expect(hitungan.hasil.biayaKirim, Uang.DariBulat(12000));
      expect(hitungan.hasil.diskonKirim, Uang.DariBulat(12000));
      // Sama dengan yang dibayar pembeli di muka: ongkir netto nol tidak menambah total.
      expect(hitungan.hasil.totalAkhir, Uang.DariBulat(30800));

      await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          PembayaranMasukan(
            metode: LayananPreOrder.MetodeUangMuka(keranjang.praPesan!),
            jumlah: hitungan.hasil.totalAkhir,
          ),
        ],
        kasir: rina,
        k: k,
      );
      final data = await BacaOutboxTerakhir();
      expect(data['BiayaKirim'], '12000.00');
      expect(data['DiskonKirim'], '12000.00');
      expect((data['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], '30800.00');
    },
  );

  test('pesanan tanpa ongkir tidak mengirim kunci ongkir sama sekali. Pesanan yang belum Siap ditolak', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();

    u.server.penangan = (_) async => JsonUji(Balasan(Pesanan()));
    final hasil = await u.pesananOnline.AmbilAktif();
    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.biayaKirim, Uang.Nol());
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    final sisa = keranjang.praPesan!.sisaUangMuka;
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(metode: LayananPreOrder.MetodeUangMuka(keranjang.praPesan!), jumlah: sisa),
        PembayaranMasukan(metode: tunai, jumlah: total.Kurangi(sisa)),
      ],
      kasir: rina,
      k: k,
    );
    final data = await BacaOutboxTerakhir();
    expect(data.containsKey('BiayaKirim'), isFalse);
    expect(data.containsKey('DiskonKirim'), isFalse);

    u.server.penangan = (_) async => JsonUji(Balasan(Pesanan(status: 'Dikonfirmasi')));
    final belumSiap = await u.pesananOnline.AmbilAktif();
    expect(LayananPesananOnline.AlasanBelumBisaDitagih(belumSiap.pesanan.single), contains('Siap'));
  });

  test('uang muka tanpa metode dari server ditolak, bukan dijadikan metode kosong', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    u.server.penangan = (_) async => JsonUji(Balasan(Pesanan(), adaMetode: false));
    final hasil = await u.pesananOnline.AmbilAktif();

    expect(
      () => u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'DataAwalBelumLengkap')),
    );
  });

  test('F-17 bagian 3: pesanan pembeli yang masuk memasang pelanggannya tanpa menghitung ulang harga. Outbox membawa '
      'UuidPelanggan', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final pesanan = Pesanan(sudahDibayar: false, sisaUangMuka: '0.00')
      ..['Pelanggan'] = {
        'Uuid': '01K5PELANGGANONLINE0000001',
        'Nama': 'Sinta Maharani Kusumawardani',
        'NoHp': '0812****1234',
        'KodeTier': 'GOLD',
        'NamaTier': 'Gold',
        'SaldoPoin': 1250,
        'LimitKredit': null,
        'SisaPiutang': '0.00',
        'HariLewatJatuhTempo': 0,
        'HariLahir': '04-17',
        'JumlahTransaksi': 3,
        'PemakaianPromo': <String, Object?>{},
      };
    u.server.penangan = (_) async => JsonUji({...Balasan(pesanan, adaMetode: false), 'TanggalBisnis': '2026-09-30'});
    final hasil = await u.pesananOnline.AmbilAktif();
    expect(hasil.pesanan.single.pelanggan?.namaTier, 'Gold');
    expect(hasil.pesanan.single.pelanggan?.pemakaianPada, '2026-09-30');

    final keranjang = u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
    expect(keranjang.pelanggan?.uuid, '01K5PELANGGANONLINE0000001');
    expect(keranjang.pelanggan?.kodeTier, 'GOLD');
    expect(keranjang.pelanggan?.jumlahTransaksi, 3);
    expect(
      keranjang.baris.single.hargaSatuan,
      Uang.DariBulat(14000),
      reason: 'Harga saat dipesan, tidak dihitung ulang.',
    );

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir)],
      kasir: rina,
      k: k,
    );
    final data = await BacaOutboxTerakhir();
    expect(data['UuidPelanggan'], '01K5PELANGGANONLINE0000001');
    expect(data['UuidPesananOnline'], '01K5PESANANONLINE000000001');
  });

  test('tamu atau server lama tanpa Pelanggan: keranjang tanpa pelanggan', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    u.server.penangan = (_) async =>
        JsonUji(Balasan(Pesanan(sudahDibayar: false, sisaUangMuka: '0.00'), adaMetode: false));
    final hasil = await u.pesananOnline.AmbilAktif();
    expect(u.pesananOnline.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k).pelanggan, isNull);
  });
}
