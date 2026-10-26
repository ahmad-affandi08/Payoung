import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPreOrder.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Rincian F-12 bagian 2 di perangkat: pre-order dibuat offline (DP = satu pembayaran, nomor `SO/…`, outbox
/// `PesananPenjualan.Buat`, DP ikut kas shift), diambil online (baris & DP dimuat ke keranjang) lalu dilunasi dengan
/// metode Uang Muka + `UuidPesananPenjualan` di `Penjualan.Buat`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  const pelanggan = PelangganTerpilih(uuid: '01K5PELANGGAN0000000000001', nama: 'Ibu Ratna', noHpSamar: '0813****0077');

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  Future<Keranjang> KeranjangDuaAmericano() async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final baris = u.penjualan.BuatBaris(
      katalog,
      k,
      katalog.CariProduk(UuidUji.americano)!,
      jumlah: Kuantitas.DariBulat(2),
    );
    return Keranjang(baris: [baris], pelanggan: pelanggan);
  }

  test('buat pre-order offline: nomor SO, outbox & baris lokal, DP tunai masuk kas shift. Aturan ditolak', () async {
    final k = await u.MuatKonteks();
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final tempo = k.metodePembayaran.where((m) => m.Jenis == 'Tempo').firstOrNull;
    final keranjang = await KeranjangDuaAmericano();
    final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
    final besok = DateTime.now().add(const Duration(days: 3)).toIso8601String().substring(0, 10);

    Future<PreOrderTersimpan> Buat({Keranjang? isi, Uang? dp, String? tanggal}) => u.preOrder.Buat(
      keranjang: isi ?? keranjang,
      k: k,
      kasir: rina,
      tanggalAmbil: tanggal ?? besok,
      uangMuka: dp ?? Uang.DariBulat(20000),
      metode: tunai,
      catatan: 'Tulisan: Selamat ulang tahun',
    );

    await expectLater(
      Buat(isi: keranjang.Salin(pelanggan: () => null)),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PelangganWajib')),
    );
    await expectLater(
      Buat(isi: keranjang.Salin(diskonPesanan: () => DiskonManual.DariJumlah(Uang.DariBulat(1000)))),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PreOrderBelumDidukung')),
      reason: 'Diskon manual tidak ikut ke server, jadi total saat diambil akan berbeda.',
    );
    await expectLater(
      Buat(isi: keranjang.Salin(biayaKirim: Uang.DariBulat(5000))),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PreOrderBelumDidukung')),
      reason: 'Ongkir tidak ikut ke server.',
    );
    await expectLater(
      Buat(dp: total.Tambah(Uang.DariBulat(1))),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'UangMukaMelebihiTotal')),
    );
    await expectLater(
      Buat(tanggal: '2020-01-01'),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanggalAmbilTidakValid')),
    );
    if (tempo != null) {
      await expectLater(
        u.preOrder.Buat(
          keranjang: keranjang,
          k: k,
          kasir: rina,
          tanggalAmbil: besok,
          uangMuka: Uang.DariBulat(20000),
          metode: tempo,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'MetodeBayarBelumDidukung')),
      );
    }

    final hasil = await Buat();
    expect(hasil.nomor, matches(RegExp(r'^SO/SLB/\d{6}/POS-001-0001$')));
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    final item = outbox.last;
    expect(item.Jenis, 'PesananPenjualan.Buat');
    expect(item.Uuid, hasil.uuid);
    final data = jsonDecode(item.Data) as Map<String, Object?>;
    expect(data['UuidPelanggan'], pelanggan.uuid);
    expect(data['TotalPesanan'], total.KeString());
    expect((data['Pembayaran']! as List<Object?>).single, containsPair('Jumlah', '20000.00'));
    expect(((data['Baris']! as List<Object?>).single! as Map<String, Object?>)['Jumlah'], '2.0000');

    final shift = await u.repositori.AmbilShiftAktif();
    final laporan = await u.tutupShift.SusunLaporan(shift!.Uuid);
    expect(laporan.tunaiMasukBersih, Uang.DariBulat(20000));
    expect(laporan.jumlahUangMuka, 1);
    expect(laporan.kasSeharusnya, Uang.DariBulat(520000));
    expect(laporan.jumlahTransaksi, 0, reason: 'Pre-order bukan penjualan sampai diambil.');
  });

  test(
    'ambil: cari online → keranjang berharga pesanan + DP. Bayar dengan Uang Muka + tunai. Offline ditolak',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      u.server.penangan = (p) async => throw http.ClientException('offline');
      await expectLater(
        u.preOrder.Cari('ratna'),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );

      u.server.penangan = (p) async => Json({
        'Pesanan': [
          {
            'Uuid': '01K5PREORDER00000000000001',
            'Nomor': 'SO/SLB/260925/POS-001-0001',
            'Status': 'Siap',
            'TanggalAmbil': '2026-09-28',
            'Catatan': 'Tulisan: Selamat ulang tahun',
            'TotalPesanan': '30000.00',
            'UangMuka': '20000.00',
            'SisaUangMuka': '20000.00',
            'Pelanggan': {'Uuid': pelanggan.uuid, 'Nama': 'Ibu Ratna', 'NoHp': '0813****0077'},
            'Baris': [
              {
                'Uuid': '01K5PREORDERBARIS000000001',
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
          },
        ],
        'MetodeUangMuka': {'Uuid': '01K5METODEUANGMUKA00000001', 'Nama': 'Uang muka (DP)'},
      }, 200);
      final hasil = await u.preOrder.Cari('ratna');
      final keranjang = u.preOrder.MuatKeKeranjang(hasil.pesanan.single, hasil, katalog, k);
      expect(keranjang.baris.single.hargaSatuan, Uang.DariBulat(14000), reason: 'Harga saat dipesan.');
      expect(keranjang.baris.single.jumlah, Kuantitas.DariBulat(2));
      expect(keranjang.pelanggan?.nama, 'Ibu Ratna');
      expect(keranjang.praPesan?.sisaUangMuka, Uang.DariBulat(20000));
      expect(Keranjang.DariJson(keranjang.KeJson()).praPesan?.nomor, 'SO/SLB/260925/POS-001-0001');

      final uangMuka = LayananPreOrder.MetodeUangMuka(keranjang.praPesan!);
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      await expectLater(
        u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [
            PembayaranMasukan(metode: uangMuka, jumlah: Uang.DariBulat(25000)),
            PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(50000)),
          ],
          kasir: rina,
          k: k,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'UangMukaMelebihiSisa')),
      );
      final total = u.penjualan.Hitung(keranjang, k).hasil.totalAkhir;
      await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          PembayaranMasukan(metode: uangMuka, jumlah: Uang.DariBulat(20000)),
          PembayaranMasukan(metode: tunai, jumlah: total.Kurangi(Uang.DariBulat(20000))),
        ],
        kasir: rina,
        k: k,
      );
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(data['UuidPesananPenjualan'], '01K5PREORDER00000000000001');
      expect(
        (data['Pembayaran']! as List<Object?>).first,
        allOf(containsPair('UuidMetodePembayaran', '01K5METODEUANGMUKA00000001'), containsPair('Jumlah', '20000.00')),
      );
    },
  );
}
