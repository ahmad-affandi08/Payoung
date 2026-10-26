import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Pelanggan/LayananDeposit.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunDokumenKasir.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Rincian F-16d bagian 1 di perangkat: isi deposit offline (nomor `DEP/…`, outbox `Deposit.Isi`, masuk kas shift),
/// saldo dibaca online (offline = `PerluOnline`), dan bayar dengan deposit (pelanggan wajib, tidak melebihi saldo).
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  const ani = PelangganTerpilih(uuid: '01K5PELANGGAN0000000000001', nama: 'Ani Rahmawati', noHpSamar: '0812****7890');

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif(dataAwal: DataAwalUji(deposit: true, nomorUrutIsiDeposit: {'260101': 4}));
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('isi deposit offline: nomor DEP, outbox Deposit.Isi, tunai masuk kas shift. Aturan jumlah & metode', () async {
    final k = await u.MuatKonteks();
    expect(k.deposit.berlaku, isTrue);
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final deposit = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Deposit');

    Future<IsiDepositTersimpan> Isi(String jumlah, {String? jenis}) => u.deposit.Isi(
      pelanggan: ani,
      jumlah: Uang.Dari(jumlah),
      metode: jenis == null ? tunai : k.metodePembayaran.firstWhere((m) => m.Jenis == jenis),
      kasir: rina,
      k: k,
    );

    for (final (jumlah, kode) in [
      ('100000.50', 'JumlahTidakValid'),
      ('500', 'JumlahTidakValid'),
      ('10000001', 'JumlahTidakValid'),
    ]) {
      await expectLater(Isi(jumlah), throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode)));
    }
    await expectLater(
      u.deposit.Isi(pelanggan: ani, jumlah: Uang.DariBulat(50000), metode: deposit, kasir: rina, k: k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'MetodeBayarBelumDidukung')),
    );

    final hasil = await Isi('150000');
    expect(hasil.nomor, matches(RegExp(r'^DEP/SLB/\d{6}/POS-001-0001$')));
    expect(hasil.tunai, isTrue);
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    final item = outbox.last;
    expect(item.Jenis, LayananDeposit.jenisOutbox);
    expect(item.Uuid, hasil.uuid);
    final data = jsonDecode(item.Data) as Map<String, Object?>;
    expect(data, containsPair('UuidPelanggan', ani.uuid));
    expect(data, containsPair('Jumlah', '150000.00'));
    expect(data, containsPair('UuidMetodePembayaran', tunai.Uuid));
    expect(data, containsPair('Nomor', hasil.nomor));
    expect(data['UuidShift'], isNotNull);

    await Isi('50000', jenis: 'QrisStatis');
    final shift = await u.repositori.AmbilShiftAktif();
    final laporan = await u.tutupShift.SusunLaporan(shift!.Uuid);
    expect(laporan.jumlahIsiDeposit, 2);
    expect(laporan.nominalIsiDeposit, Uang.DariBulat(200000));
    expect(laporan.tunaiMasukBersih, Uang.DariBulat(150000));
    expect(laporan.kasSeharusnya, Uang.DariBulat(650000));
    expect(laporan.jumlahTransaksi, 0, reason: 'Isi deposit bukan penjualan.');
    expect(laporan.perMetode.map((m) => (m.nama, m.jumlah)), [
      ('Tunai', Uang.DariBulat(150000)),
      ('QRIS', Uang.DariBulat(50000)),
    ]);
  });

  test('nomor urut isi deposit dari server dipakai: tidak mengulang nomor setelah pasang ulang', () async {
    final k = await u.MuatKonteks();
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final tanggal = k.HitungTanggalBisnis(u.jam.toUtc());
    final yymmdd = '${tanggal.substring(2, 4)}${tanggal.substring(5, 7)}${tanggal.substring(8, 10)}';
    await u.repositori.SimpanDataAwal(
      DataAwal.DariJson(DataAwalUji(deposit: true, nomorUrutIsiDeposit: {yymmdd: 7})),
      u.jam,
    );
    final hasil = await u.deposit.Isi(pelanggan: ani, jumlah: Uang.DariBulat(20000), metode: tunai, kasir: rina, k: k);
    expect(hasil.nomor, endsWith('-0008'));
  });

  test('paket tanpa deposit: isi ditolak FiturTidakAktif', () async {
    await u.repositori.SimpanDataAwal(DataAwal.DariJson(DataAwalUji()), u.jam);
    final k = await u.MuatKonteks();
    expect(k.deposit.berlaku, isFalse);
    await expectLater(
      u.deposit.Isi(pelanggan: ani, jumlah: Uang.DariBulat(20000), metode: k.metodePembayaran.first, kasir: rina, k: k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'FiturTidakAktif')),
    );
  });

  test('saldo deposit online. Offline → PerluOnline', () async {
    u.server.penangan = (p) async {
      expect(p.url.path, endsWith('/api/pos/v1/pelanggan/${ani.uuid}/deposit'));
      return Json({
        'Pelanggan': {'Uuid': ani.uuid, 'SaldoDeposit': '120000.00'},
        'Berlaku': true,
      }, 200);
    };
    expect(await u.deposit.AmbilSaldo(ani.uuid), Uang.DariBulat(120000));

    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(
      u.deposit.AmbilSaldo(ani.uuid),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
    );
  });

  test(
    'bayar dengan deposit: wajib pelanggan & saldo dicek, tidak melebihi saldo. Outbox membawa metode Deposit',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      final deposit = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Deposit');
      final baris = u.penjualan.BuatBaris(
        katalog,
        k,
        katalog.CariProduk(UuidUji.americano)!,
        jumlah: Kuantitas.DariBulat(2),
      );
      final keranjang = Keranjang(baris: [baris], pelanggan: ani);
      final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
      final bayar = [PembayaranMasukan(metode: deposit, jumlah: total)];

      Future<PenjualanTersimpan> Bayar(Keranjang isi, Uang? saldo) =>
          u.penjualan.Bayar(keranjang: isi, pembayaran: bayar, kasir: rina, k: k, saldoDeposit: saldo);

      await expectLater(
        Bayar(keranjang.Salin(pelanggan: () => null), total),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'DepositTanpaPelanggan')),
      );
      await expectLater(
        Bayar(keranjang, null),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );
      await expectLater(
        Bayar(keranjang, total.Kurangi(Uang.DariBulat(1))),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'SaldoDepositKurang')),
      );

      final hasil = await Bayar(keranjang, total.Tambah(Uang.DariBulat(5000)));
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(outbox.last.Uuid, hasil.uuid);
      expect(data['UuidPelanggan'], ani.uuid);
      expect((data['Pembayaran']! as List<Object?>).single, containsPair('UuidMetodePembayaran', deposit.Uuid));

      final shift = await u.repositori.AmbilShiftAktif();
      final laporan = await u.tutupShift.SusunLaporan(shift!.Uuid);
      expect(laporan.tunaiMasukBersih, Uang.Nol(), reason: 'Bayar deposit bukan uang masuk laci.');
    },
  );

  test(
    'bukti isi deposit: judul, nomor, pelanggan, jumlah, saldo sesudah bila diketahui. Laci hanya bila diminta',
    () async {
      final hasil = IsiDepositTersimpan(
        uuid: '01K5ISIDEPOSIT000000000001',
        nomor: 'DEP/SLB/260926/POS-001-0001',
        jumlah: Uang.DariBulat(150000),
        namaPelanggan: ani.nama,
        namaKasir: 'Rina Wulandari',
        namaMetode: 'Tunai',
        tunai: true,
        dibuatPada: DateTime.utc(2026, 9, 26, 3),
        saldoSebelum: Uang.DariBulat(20000),
      );
      final identitas = await IdentitasStruk.Muat(u.repositori);
      final dokumen = PenyusunDokumenKasir.SusunIsiDeposit(identitas, hasil, bukaLaci: true);
      final teks = dokumen.baris
          .map(
            (b) => switch (b) {
              BarisTeks(:final teks) => teks,
              BarisDuaKolom(:final kiri, :final kanan) => '$kiri $kanan',
              _ => '',
            },
          )
          .join('\n');
      expect(teks, contains('BUKTI ISI DEPOSIT'));
      expect(teks, contains('DEP/SLB/260926/POS-001-0001'));
      expect(teks, contains('Ani Rahmawati'));
      expect(teks, contains('170.000'));
      expect(dokumen.bukaLaci, isTrue);
      expect(PenyusunDokumenKasir.SusunIsiDeposit(identitas, hasil).bukaLaci, isFalse);
    },
  );
}
