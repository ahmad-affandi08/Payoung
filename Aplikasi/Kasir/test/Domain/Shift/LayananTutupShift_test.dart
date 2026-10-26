import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Shift/LayananShift.dart';
import 'package:kasir/Domain/Shift/LayananTutupShift.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// Rincian F-11 di perangkat: laporan X/Z, kas seharusnya, aturan tutup shift, dan payload `Shift.Tutup`.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late StafLokal budi;
  late StafLokal sari;
  late BarisShift shift;

  BarisMetodePembayaran Metode(String jenis) => k.metodePembayaran.firstWhere((m) => m.Jenis == jenis);

  /// 2× Es Kopi Susu Aren + 1× Croissant = Rp 61.000 + PBJT 10% = Rp 67.100.
  Keranjang KeranjangContoh() {
    var keranjang = Keranjang.kosong;
    final gula = [PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol())];
    for (var i = 0; i < 2; i++) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.kopiSusu)!, pilihan: gula),
        katalog,
        k,
      );
    }
    return u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
      katalog,
      k,
    );
  }

  /// Kas awal Rp 500.000; jual tunai Rp 67.100 (bayar Rp 100.000), jual QRIS Rp 67.100; kas masuk Rp 20.000, kas
  /// keluar Rp 45.000, setoran Rp 100.000 → kas seharusnya Rp 442.100.
  Future<void> Siapkan({Map<String, Object?>? dataAwal, bool bersama = false}) async {
    await u.SiapkanAktif(dataAwal: dataAwal ?? DataAwalUji(shiftBersama: bersama));
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    budi = await u.Staf('Budi Santoso');
    sari = await u.Staf('Sari Lestari');
    shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    await u.penjualan.Bayar(
      keranjang: KeranjangContoh(),
      pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
      kasir: rina,
      k: k,
    );
    await u.penjualan.Bayar(
      keranjang: KeranjangContoh(),
      pembayaran: [PembayaranMasukan(metode: Metode('QrisStatis'), jumlah: Uang.DariBulat(67100))],
      kasir: rina,
      k: k,
    );
    await u.shift.CatatMutasi(
      shift: shift,
      jenis: JenisMutasi.masuk,
      jumlah: Uang.DariBulat(20000),
      pencatat: rina,
      uuidKategori: '01K5KATEGORI00000000000002',
    );
    await u.shift.CatatMutasi(
      shift: shift,
      jenis: JenisMutasi.keluar,
      jumlah: Uang.DariBulat(45000),
      pencatat: rina,
      uuidKategori: '01K5KATEGORI00000000000001',
    );
    await u.shift.CatatMutasi(shift: shift, jenis: JenisMutasi.setoran, jumlah: Uang.DariBulat(100000), pencatat: rina);
  }

  Future<Map<String, Object?>> DataOutboxTutup() async {
    final baris = (await u.db.select(u.db.outbox).get()).where((o) => o.Jenis == 'Shift.Tutup').single;
    return jsonDecode(baris.Data) as Map<String, Object?>;
  }

  setUp(() => u = LingkunganUji.Buat());
  tearDown(() => u.Tutup());

  test('HitungKasSeharusnya = kas awal + tunai bersih + masuk − keluar − setoran − refund tunai', () {
    expect(
      LayananTutupShift.HitungKasSeharusnya(
        kasAwal: Uang.DariBulat(500000),
        tunaiMasukBersih: Uang.DariBulat(67100),
        kasMasuk: Uang.DariBulat(20000),
        kasKeluar: Uang.DariBulat(45000),
        setoran: Uang.DariBulat(100000),
        refundTunai: Uang.DariBulat(5000),
      ),
      Uang.DariBulat(437100),
    );
  });

  test('laporan X: penjualan, per metode (tunai bersih kembalian), kas laci, kas seharusnya', () async {
    await Siapkan();
    final laporan = await u.tutupShift.SusunLaporan(shift.Uuid);

    expect(laporan.jumlahTransaksi, 2);
    expect(laporan.penjualanKotor, Uang.DariBulat(122000));
    expect(laporan.totalDiskon, Uang.Nol());
    expect(laporan.penjualanBersih, Uang.DariBulat(122000));
    expect(laporan.totalPajak, Uang.DariBulat(12200));
    expect(laporan.totalAkhir, Uang.DariBulat(134200));
    expect(laporan.perMetode.map((m) => (m.nama, m.jumlah)), [
      ('Tunai', Uang.DariBulat(67100)),
      ('QRIS', Uang.DariBulat(67100)),
    ]);
    expect(laporan.tunaiMasukBersih, Uang.DariBulat(67100));
    expect(laporan.refundTunai, Uang.Nol());
    expect(
      (laporan.kasMasuk, laporan.kasKeluar, laporan.setoran),
      (Uang.DariBulat(20000), Uang.DariBulat(45000), Uang.DariBulat(100000)),
    );
    expect(laporan.kasSeharusnya, Uang.DariBulat(442100));
    expect(laporan.tertutup, isFalse);
  });

  test('D-40 layar Shift: produk terlaris urut jumlah, penjualan per jam, rata-rata per transaksi', () async {
    await Siapkan();
    final laporan = await u.tutupShift.SusunLaporan(shift.Uuid);

    expect(laporan.terlaris.map((p) => (p.nama, p.jumlah)), [
      ('Es Kopi Susu Aren', Kuantitas.DariBulat(4)),
      ('Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo', Kuantitas.DariBulat(2)),
    ]);
    expect(laporan.terlaris.first.total.Bandingkan(laporan.terlaris.last.total), 1);
    expect(laporan.perJam.values.fold(Uang.Nol(), (t, n) => t.Tambah(n)), laporan.totalAkhir);
    expect(laporan.rataRataTransaksi, Uang.DariBulat(67100));
  });

  test(
    'tutup pas: shift Tertutup + outbox Shift.Tutup sesuai kontrak dalam satu transaksi. Laporan Z tertunda',
    () async {
      await Siapkan();
      u.jam = u.jam.add(const Duration(hours: 8));
      final laporan = await u.tutupShift.TutupShift(
        shift: shift,
        penutup: rina,
        kasAktual: Uang.DariBulat(442100),
        pecahan: {100000: 4, 20000: 2, 2000: 1, 100: 1},
        nonTunai: {'01K5MTD0000000000000000002': Uang.DariBulat(67100)},
      );

      expect(laporan.tertutup, isTrue);
      expect(laporan.shift.KasSeharusnya, '442100.00');
      expect(laporan.shift.Selisih, '0.00');
      expect(await u.repositori.AmbilShiftAktif(), isNull);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.laporanZTertunda), shift.Uuid);

      final data = await DataOutboxTutup();
      expect(data, {
        'UuidShift': shift.Uuid,
        'UuidPengguna': rina.uuid,
        'DitutupPada': '2026-09-24T09:00:00.000Z',
        'KasAktual': '442100.00',
        'PecahanKasAkhir': [
          {'Nominal': '100000', 'Jumlah': 4},
          {'Nominal': '20000', 'Jumlah': 2},
          {'Nominal': '2000', 'Jumlah': 1},
          {'Nominal': '100', 'Jumlah': 1},
        ],
        'NonTunai': [
          {'UuidMetodePembayaran': '01K5MTD0000000000000000002', 'Jumlah': '67100.00'},
        ],
        'Alasan': null,
        'UuidPenyetuju': null,
        'Ringkasan': {'KasSeharusnya': '442100.00', 'Selisih': '0.00'},
      });

      // Outbox FIFO: tutup shift terkirim setelah penjualan & kas shift itu.
      final jenis = (await u.db.select(u.db.outbox).get()).map((o) => o.Jenis).toList();
      expect(jenis.last, 'Shift.Tutup');

      await expectLater(
        u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(442100)),
        GalatDengan('ShiftSudahDitutup'),
      );

      // Shift baru bisa dibuka setelah tutup.
      await u.tutupShift.SelesaikanLaporanZ();
      final baru = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(300000));
      expect(baru.Uuid, isNot(shift.Uuid));
    },
  );

  test(
    'selisih kurang di bawah toleransi Rp 10.000 tanpa penyetuju. Di atas toleransi wajib alasan & penyetuju ber-izin',
    () async {
      await Siapkan();
      expect((await u.tutupShift.Pratinjau(shift.Uuid, Uang.DariBulat(435100))).butuhPersetujuan, isFalse);
      final atas = await u.tutupShift.Pratinjau(shift.Uuid, Uang.DariBulat(420100));
      expect(atas.selisih, Uang.DariBulat(-22000));
      expect(atas.butuhPersetujuan, isTrue);

      await expectLater(
        u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(420100), penyetuju: budi),
        GalatDengan('AlasanDiperlukan'),
      );
      await expectLater(
        u.tutupShift.TutupShift(
          shift: shift,
          penutup: rina,
          kasAktual: Uang.DariBulat(420100),
          alasan: 'Salah kembalian',
        ),
        GalatDengan('PersetujuanDiperlukan'),
      );
      await expectLater(
        u.tutupShift.TutupShift(
          shift: shift,
          penutup: rina,
          kasAktual: Uang.DariBulat(420100),
          alasan: 'Salah kembalian',
          penyetuju: sari,
        ),
        GalatDengan('PenyetujuTidakBerwenang'),
      );
      expect(await u.repositori.AmbilShiftAktif(), isNotNull, reason: 'Penolakan tidak menyimpan apa pun.');

      await u.tutupShift.TutupShift(
        shift: shift,
        penutup: rina,
        kasAktual: Uang.DariBulat(420100),
        alasan: '  Salah kembalian  ',
        penyetuju: budi,
      );
      final data = await DataOutboxTutup();
      expect(data['Alasan'], 'Salah kembalian');
      expect(data['UuidPenyetuju'], budi.uuid);
      expect(data['Ringkasan'], {'KasSeharusnya': '442100.00', 'Selisih': '-22000.00'});
      expect(data['PecahanKasAkhir'], isNull);
    },
  );

  test(
    'toleransi dari data awal: Rp 50.000 menerima selisih Rp 22.000 tanpa penyetuju. Tutup buta dari data awal',
    () async {
      await Siapkan(dataAwal: DataAwalUji(toleransiSelisihKas: '50000.00', tutupShiftButa: false));
      expect(await u.tutupShift.CekTutupButa(), isFalse);
      await u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(420100));
      expect((await DataOutboxTutup())['Ringkasan'], {'KasSeharusnya': '442100.00', 'Selisih': '-22000.00'});
    },
  );

  test(
    'ditolak: pesanan tertahan, pecahan ≠ kas aktual, kasir lain di shift bukan bersama. Supervisor boleh',
    () async {
      await Siapkan();
      await u.penjualan.TahanPesanan(KeranjangContoh(), rina, Uang.DariBulat(67100));
      await expectLater(
        u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(442100)),
        GalatDengan('PesananTertahan'),
      );
      final tertahan = (await u.repositoriPenjualan.PantauPesananTertahan().first).single;
      await u.repositoriPenjualan.HapusPesananTertahan(tertahan.Uuid);

      await expectLater(
        u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(442100), pecahan: {100000: 4}),
        GalatDengan('PecahanTidakSesuai'),
      );
      await expectLater(
        u.tutupShift.TutupShift(shift: shift, penutup: sari, kasAktual: Uang.DariBulat(442100)),
        GalatDengan('BukanShiftSendiri'),
      );
      await u.tutupShift.TutupShift(shift: shift, penutup: budi, kasAktual: Uang.DariBulat(442100));
      expect((await DataOutboxTutup())['UuidPengguna'], budi.uuid);
    },
  );
}
