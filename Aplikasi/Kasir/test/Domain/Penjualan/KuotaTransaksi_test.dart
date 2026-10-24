import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart' show StatusPenjualanLokal;
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/KuotaTransaksiEfektif.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

KuotaTransaksiAplikasi Kuota({String periode = '2026-12', int terpakai = 10, int? batas = 100}) =>
    KuotaTransaksiAplikasi(periode: periode, terpakai: terpakai, batas: batas, status: StatusKuotaTransaksi.Normal);

/// P-04: kuota transaksi penjualan per bulan di perangkat. Hitungan server ditambah penjualan lokal yang belum tercakup;
/// bulan basi atau paket tanpa batas tidak mengunci apa pun.
void main() {
  group('KuotaTransaksiEfektif', () {
    final desember = DateTime.utc(2026, 12, 15, 3);

    test('hitungan server + penjualan belum tercakup; habis saat terpakai mencapai batas', () {
      final aman = KuotaTransaksiEfektif.Hitung(
        kuota: Kuota(terpakai: 90),
        penjualanBelumTercakup: 5,
        sekarang: desember,
      )!;
      expect((aman.terpakai, aman.sisa, aman.habis, aman.hampirHabis), (95, 5, false, true));

      final habis = KuotaTransaksiEfektif.Hitung(
        kuota: Kuota(terpakai: 98),
        penjualanBelumTercakup: 2,
        sekarang: desember,
      )!;
      expect((habis.terpakai, habis.sisa, habis.habis, habis.hampirHabis), (100, 0, true, false));
    });

    test('ambang hampir habis tepat 80% dan di bawahnya aman', () {
      expect(const KuotaTransaksiEfektif(batas: 100, terpakai: 79).hampirHabis, isFalse);
      expect(const KuotaTransaksiEfektif(batas: 100, terpakai: 80).hampirHabis, isTrue);
      expect(const KuotaTransaksiEfektif(batas: 100, terpakai: 99).hampirHabis, isTrue);
      expect(const KuotaTransaksiEfektif(batas: 100, terpakai: 150).sisa, 0);
    });

    test('batas 0 langsung habis', () {
      final nol = KuotaTransaksiEfektif.Hitung(
        kuota: Kuota(terpakai: 0, batas: 0),
        penjualanBelumTercakup: 0,
        sekarang: desember,
      )!;
      expect(nol.habis, isTrue);
    });

    test('tanpa kuota, tanpa batas, atau data bulan lain = tidak ada yang ditegakkan', () {
      expect(KuotaTransaksiEfektif.Hitung(kuota: null, penjualanBelumTercakup: 3, sekarang: desember), isNull);
      expect(
        KuotaTransaksiEfektif.Hitung(kuota: Kuota(batas: null), penjualanBelumTercakup: 3, sekarang: desember),
        isNull,
      );
      expect(
        KuotaTransaksiEfektif.Hitung(
          kuota: Kuota(periode: '2026-11', terpakai: 100),
          penjualanBelumTercakup: 0,
          sekarang: desember,
        ),
        isNull,
      );
    });

    test('bulan kuota mengikuti tanggal WIB, bukan UTC', () {
      // 30 Nov 2026 18:00 UTC = 1 Des 2026 01:00 WIB.
      expect(KuotaTransaksiEfektif.Periode(DateTime.utc(2026, 11, 30, 18)), '2026-12');
      expect(KuotaTransaksiEfektif.Periode(DateTime.utc(2026, 11, 30, 16, 59)), '2026-11');
    });
  });

  group('RepositoriPenjualan.HitungPenjualanBelumTercakupKuota', () {
    late LingkunganUji u;
    late KatalogLokal katalog;
    late KonteksPenjualan k;
    late StafLokal rina;

    Future<PenjualanTersimpan> JualTunai() {
      final keranjang = u.penjualan.TambahBaris(
        Keranjang.kosong,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
        katalog,
        k,
      );
      return u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [
          PembayaranMasukan(
            metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
            jumlah: Uang.DariBulat(100000),
          ),
        ],
        kasir: rina,
        k: k,
      );
    }

    setUp(() async {
      u = LingkunganUji.Buat();
      await u.SiapkanAktif();
      await u.SiapkanKatalog();
      katalog = await u.MuatKatalog();
      k = await u.MuatKonteks();
      rina = await u.Staf('Rina Wulandari');
      await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    });
    tearDown(() => u.Tutup());

    Future<int> Hitung({required DateTime sejak, Set<String> tertunda = const {}}) =>
        u.repositoriPenjualan.HitungPenjualanBelumTercakupKuota(sejak: sejak, uuidTertundaSaatAmbil: tertunda);

    test('dibuat sejak konfigurasi dibaca ikut dihitung; sebelumnya dan sudah terkirim tidak', () async {
      final sekarang = u.jam;
      final satu = await JualTunai();
      await JualTunai();

      expect(await Hitung(sejak: sekarang.subtract(const Duration(minutes: 5))), 2);

      // Setelah terkirim (keluar dari outbox) dan konfigurasi dibaca sesudahnya, hitungan server sudah mencakupnya.
      await u.db.delete(u.db.outbox).go();
      expect(await Hitung(sejak: u.jam.add(const Duration(minutes: 1))), 0);
      expect(await Hitung(sejak: sekarang.subtract(const Duration(minutes: 5))), 2);

      // Yang masih di outbox saat konfigurasi dibaca belum tercakup, walau sekarang sudah terkirim.
      expect(await Hitung(sejak: u.jam.add(const Duration(minutes: 1)), tertunda: {satu.uuid}), 1);
    });

    test('penjualan yang masih di outbox selalu dihitung, dan void tetap dihitung (kuota tidak kembali)', () async {
      final penjualan = await JualTunai();
      final masaDepan = u.jam.add(const Duration(days: 1));

      expect(await Hitung(sejak: masaDepan), 1);

      await (u.db.update(u.db.penjualan)..where((p) => p.Uuid.equals(penjualan.uuid))).write(
        const PenjualanCompanion(Status: Value(StatusPenjualanLokal.divoid)),
      );
      expect(await Hitung(sejak: masaDepan), 1);
    });
  });
}
