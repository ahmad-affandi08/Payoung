import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Pelanggan/LayananPelanggan.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// Rincian F-16a di perangkat: normalisasi & penyamaran nomor HP sama dengan server, pelanggan baru offline (outbox
/// `Pelanggan.Buat` + cache tersamar), cari online/offline, dan `Penjualan.Buat` membawa `UuidPelanggan`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  test('nomor HP: normalisasi & penyamaran sama dengan NomorHp server', () {
    expect(LayananPelanggan.NormalisasiNoHp('0812-3456-7890'), '6281234567890');
    expect(LayananPelanggan.NormalisasiNoHp('+62 812 3456 7890'), '6281234567890');
    expect(LayananPelanggan.NormalisasiNoHp('812.3456.7890'), '6281234567890');
    expect(LayananPelanggan.NormalisasiNoHp('0812'), isNull);
    expect(LayananPelanggan.NormalisasiNoHp('0812abc4567'), isNull);
    expect(LayananPelanggan.SamarkanNoHp('6281234567890'), '0812****7890');
    expect(LayananPelanggan.SamarkanNoHp('6281311112222'), '0813****2222');
  });

  test('pelanggan baru offline: outbox Pelanggan.Buat + cache tersamar. Nama & nomor divalidasi', () async {
    final baru = await u.pelanggan.Buat(nama: '  Budi Santoso ', noHp: '0813 1111 2222', kasir: rina);
    expect(baru.nama, 'Budi Santoso');
    expect(baru.noHpSamar, '0813****2222');

    final outbox = (await u.db.select(u.db.outbox).get()).single;
    expect(outbox.Jenis, 'Pelanggan.Buat');
    expect(outbox.Uuid, baru.uuid);
    expect(jsonDecode(outbox.Data), {
      'Nama': 'Budi Santoso',
      'NoHp': '6281311112222',
      'Email': null,
      'UuidPengguna': rina.uuid,
      'DibuatPada': '2026-09-24T01:00:00.000Z',
    });
    final lokal = (await u.db.select(u.db.pelangganLokal).get()).single;
    expect(lokal.NoHpSamar, '0813****2222', reason: 'Nomor utuh tidak disimpan di tabel lokal.');

    await expectLater(() => u.pelanggan.Buat(nama: ' ', noHp: '081311112222', kasir: rina), GalatDengan('NamaWajib'));
    await expectLater(() => u.pelanggan.Buat(nama: 'Ani', noHp: '0812', kasir: rina), GalatDengan('NoHpTidakValid'));
    await expectLater(
      () => u.pelanggan.Buat(
        nama: 'Ani',
        noHp: '081234567890',
        kasir: const StafLokal(uuid: '01K5STAF000000000000000009', nama: 'Tamu', pemilik: false, izin: []),
      ),
      GalatDengan('TanpaIzin'),
    );
  });

  test('cari: online dari server & dicatat saat dipilih. Offline dari cache perangkat', () async {
    u.server.penangan = (p) async => http.Response(
      jsonEncode({
        'Pelanggan': [
          {'Uuid': '01K5PELANGGAN0000000000001', 'Nama': 'Ani Rahmawati', 'NoHp': '0812****7890'},
        ],
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
    expect((await u.pelanggan.Cari('an')).pelanggan, isEmpty);
    final online = await u.pelanggan.Cari('ani');
    expect(online.online, isTrue);
    expect(u.server.permintaan.single.url.queryParameters['kata'], 'ani');
    await u.pelanggan.CatatDipakai(online.pelanggan.single);

    u.jam = DateTime.utc(2026, 9, 24, 2);
    await u.pelanggan.Buat(nama: 'Budi Santoso', noHp: '081311112222', kasir: rina);
    u.server.penangan = (p) async => throw http.ClientException('offline');
    final offline = await u.pelanggan.Cari('ani');
    expect(offline.online, isFalse);
    expect(offline.pelanggan.single.nama, 'Ani Rahmawati');
    expect((await u.pelanggan.Cari('2222')).pelanggan.single.nama, 'Budi Santoso');
    expect((await u.pelanggan.AmbilTerakhir()).map((p) => p.nama), ['Budi Santoso', 'Ani Rahmawati']);
  });

  test('bayar dengan pelanggan: Penjualan.Buat membawa UuidPelanggan setelah Pelanggan.Buat. Keranjang tertahan menyimpannya', () async {
    await u.SiapkanKatalog();
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    final baru = await u.pelanggan.Buat(nama: 'Budi Santoso', noHp: '081311112222', kasir: rina);

    var keranjang = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
      katalog,
      k,
    );
    keranjang = keranjang.Salin(pelanggan: () => baru);
    expect(Keranjang.DariJson(keranjang.KeJson()).pelanggan?.uuid, baru.uuid);

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(50000))],
      kasir: rina,
      k: k,
    );
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.map((o) => o.Jenis), ['Shift.Buka', 'Pelanggan.Buat', 'Penjualan.Buat']);
    final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
    expect(outbox.last.Uuid, hasil.uuid);
    expect(data['UuidPelanggan'], baru.uuid);
    expect(LayananPenjualan.jenisOutbox, 'Penjualan.Buat');
  });

  test('F-16b harga tier: pelanggan GOLD mendapat harga daftar tier. Lepas pelanggan kembali ke harga dasar', () async {
    final katalogJson = KatalogUji();
    katalogJson['DaftarHarga'] = [
      {
        'Uuid': '01K5DH0000000000000000G0LD',
        'Nama': 'Harga member Gold',
        'UuidOutlet': null,
        'Kanal': null,
        'TierPelanggan': 'GOLD',
        'MulaiPada': null,
        'SelesaiPada': null,
        'Prioritas': 10,
        'Aktif': true,
      },
    ];
    (katalogJson['ProdukHarga']! as List<Object?>).add({
      ...HargaUji('01K5HRG000000000000CR0G0LD', UuidUji.croissant, UuidUji.psCroissant, '22000.00'),
      'UuidDaftarHarga': '01K5DH0000000000000000G0LD',
    });
    await u.SiapkanKatalog(katalogJson);
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    const gold = PelangganTerpilih(
      uuid: 'P1',
      nama: 'Ani',
      noHpSamar: '0812****7890',
      kodeTier: 'GOLD',
      namaTier: 'Gold',
    );

    final umum = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
      katalog,
      k,
    );
    expect(umum.baris.single.hargaSatuan, Uang.Dari('25000.00'));

    final member = u.penjualan.HitungUlangHarga(umum.Salin(pelanggan: () => gold), katalog, k);
    expect(member.baris.single.hargaSatuan, Uang.Dari('22000.00'));
    final baru = u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!, tierPelanggan: 'GOLD');
    expect(baru.hargaSatuan, Uang.Dari('22000.00'));
    expect(
      u.penjualan
          .UbahJumlah(member, member.baris.single.uuid, Kuantitas.DariBulat(3), katalog, k)
          .baris
          .single
          .hargaSatuan,
      Uang.Dari('22000.00'),
    );

    final lepas = u.penjualan.HitungUlangHarga(member.Salin(pelanggan: () => null), katalog, k);
    expect(lepas.baris.single.hargaSatuan, Uang.Dari('25000.00'));
  });

  test('F-16b tier ikut tersimpan di cache pelanggan untuk harga tier saat offline', () async {
    await u.pelanggan.CatatDipakai(
      const PelangganTerpilih(
        uuid: 'P1',
        nama: 'Ani Rahmawati',
        noHpSamar: '0812****7890',
        kodeTier: 'GOLD',
        namaTier: 'Gold',
        saldoPoin: 120,
      ),
    );
    u.server.penangan = (p) async => throw http.ClientException('offline');
    final hasil = (await u.pelanggan.Cari('ani')).pelanggan.single;
    expect(hasil.kodeTier, 'GOLD');
    expect(hasil.saldoPoin, isNull, reason: 'Saldo poin hanya dari server (online).');
    expect(PelangganTerpilih.DariJson(hasil.KeJson())?.namaTier, 'Gold');
  });

  test('F-16b tukar poin: saldo wajib online. Batas poin = saldo & sisa belanja. Nilai = poin × nilai tukar', () async {
    u.server.penangan = (p) async => http.Response(
      jsonEncode({
        'Pelanggan': {'Uuid': 'P1', 'SaldoPoin': 120},
        'TukarPoin': {'Berlaku': true, 'NilaiTukarPoin': '100.00', 'MinimalTukarPoin': 10},
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
    final saldo = await u.pelanggan.AmbilSaldoPoin('P1');
    expect(u.server.permintaan.single.url.path, endsWith('/pelanggan/P1/poin'));
    expect(saldo.saldoPoin, 120);

    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(() => u.pelanggan.AmbilSaldoPoin('P1'), GalatDengan('PerluOnline'));

    final seratus = Uang.Dari('100.00');
    expect(
      LayananPelanggan.HitungMaksimalPoin(saldo: 120, sisaTagihan: Uang.Dari('25000.00'), nilaiPerPoin: seratus),
      120,
    );
    expect(
      LayananPelanggan.HitungMaksimalPoin(saldo: 500, sisaTagihan: Uang.Dari('25050.00'), nilaiPerPoin: seratus),
      250,
    );
    expect(LayananPelanggan.HitungMaksimalPoin(saldo: 0, sisaTagihan: Uang.Dari('25000.00'), nilaiPerPoin: seratus), 0);
    expect(LayananPelanggan.HitungMaksimalPoin(saldo: 50, sisaTagihan: Uang.Nol(), nilaiPerPoin: seratus), 0);
    expect(LayananPelanggan.HitungNilaiTukar(50, seratus), Uang.Dari('5000.00'));
  });

  test(
    'F-16b bayar dengan tukar poin: diskon sebelum pajak, TukarPoin di outbox. Melebihi sisa belanja ditolak',
    () async {
      await u.SiapkanKatalog();
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
      const ani = PelangganTerpilih(uuid: '01K5PELANGGAN0000000000001', nama: 'Ani', noHpSamar: '0812****7890');

      final dasar = u.penjualan.TambahBaris(
        Keranjang.kosong,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
        katalog,
        k,
      );
      final tanpa = u.penjualan.Hitung(dasar, k).hasil;
      final keranjang = dasar.Salin(
        pelanggan: () => ani,
        tukarPoin: () => TukarPoin(poin: 50, nilai: Uang.Dari('5000.00')),
      );
      expect(Keranjang.DariJson(keranjang.KeJson()).tukarPoin?.poin, 50);
      final dengan = u.penjualan.Hitung(keranjang, k).hasil;
      expect(dengan.diskonPoin, Uang.Dari('5000.00'));
      expect(dengan.subtotal, tanpa.subtotal);
      expect(dengan.totalAkhir.Bandingkan(tanpa.totalAkhir), lessThan(0));
      expect(
        u.penjualan.HitungDiskonPesanan(keranjang, DiskonManual.DariPersen(Decimal.fromInt(10)), k).diskon,
        Uang.Dari('2500.00'),
        reason: 'Diskon poin tidak dihitung sebagai diskon manual.',
      );

      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      await expectLater(
        () => u.penjualan.Bayar(
          keranjang: keranjang.Salin(tukarPoin: () => TukarPoin(poin: 900, nilai: Uang.Dari('90000.00'))),
          pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(50000))],
          kasir: rina,
          k: k,
        ),
        GalatDengan('TukarPoinMelebihiTotal'),
      );

      await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(50000))],
        kasir: rina,
        k: k,
      );
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(data['TukarPoin'], {'Poin': 50, 'Nilai': '5000.00'});
      expect(data['UuidPelanggan'], ani.uuid);
      expect((data['Ringkasan']! as Map<String, Object?>)['TotalAkhir'], dengan.totalAkhir.KeString());
    },
  );
}
