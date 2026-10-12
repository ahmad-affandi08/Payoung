import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/BagianTagihan.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// K-14 sisa (BR-08.2, v5.68): struk per tamu pada tagihan yang dibagi. Penjualan tetap satu dokumen dengan beberapa
/// pembayaran (kontrak server tidak berubah); bagian tiap tamu tersimpan lokal supaya bisa dicetak ulang offline.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;

  const profil = ProfilPrinter(alamat: '192.168.1.50');

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    await profil.Simpan(u.repositori);
  });
  tearDown(() => u.Tutup());

  /// 2× Es Kopi Susu Aren + 1× Croissant = Rp 61.000 + PBJT 10% = Rp 67.100.
  Keranjang IsiKeranjang() {
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

  PembayaranMasukan Bagian(String jenis, int porsi, {int? diterima, String? referensi}) {
    final metode = k.metodePembayaran.firstWhere((m) => m.Jenis == jenis);
    return PembayaranMasukan(
      metode: metode,
      jumlah: Uang.DariBulat(porsi),
      referensi: referensi,
      tamu: BagianTamu(
        namaMetode: metode.Nama,
        porsi: Uang.DariBulat(porsi),
        diterima: Uang.DariBulat(diterima ?? porsi),
      ),
    );
  }

  /// Tiga tamu: tunai (menyerahkan 30.000), kartu EDC, lalu transfer (tamu terakhir menanggung selisih pembulatan).
  Future<PenjualanTersimpan> JualDibagi() {
    final bayar = [
      Bagian(JenisMetodeBayar.tunai, 22366, diterima: 30000),
      Bagian(JenisMetodeBayar.edc, 22366, referensi: 'BCA 123456'),
      Bagian(JenisMetodeBayar.transfer, 22368),
    ];
    return u.penjualan.Bayar(
      keranjang: IsiKeranjang(),
      pembayaran: bayar,
      kasir: rina,
      k: k,
      bagianTagihan: BagianTagihan(rata: true, tamu: [for (final p in bayar) p.tamu!]),
    );
  }

  test('BR-08.2 bagian tiap tamu tersimpan lokal bersama penjualan; kontrak outbox tidak memuatnya', () async {
    final hasil = await JualDibagi();

    expect(hasil.bagianTagihan!.tamu, hasLength(3));
    final bagian = (await u.struk.AmbilBagianTagihan(hasil.uuid))!;
    expect(bagian.rata, isTrue);
    expect(bagian.tamu.map((t) => t.porsi.KeString()), ['22366.00', '22366.00', '22368.00']);
    expect(bagian.tamu.first.diterima.KeString(), '30000.00');
    expect(bagian.tamu.first.kembalian.KeString(), '7634.00');
    expect(bagian.tamu[1].kembalian.BernilaiNol(), isTrue);

    final outbox = (await u.db.select(u.db.outbox).get()).where((o) => o.Jenis == 'Penjualan.Buat').single;
    expect(outbox.Data, isNot(contains('BagianTagihan')));
    expect(outbox.Data, isNot(contains('Diterima')));
  });

  test('penjualan tidak dibagi atau hanya satu tamu: tidak ada struk per tamu', () async {
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == JenisMetodeBayar.tunai);
    final biasa = await u.penjualan.Bayar(
      keranjang: IsiKeranjang(),
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(70000))],
      kasir: rina,
      k: k,
    );
    expect(await u.struk.AmbilBagianTagihan(biasa.uuid), isNull);
    await expectLater(u.struk.CetakStrukTamu(biasa.uuid), throwsA(isA<GalatKasir>()));

    final satu = Bagian(JenisMetodeBayar.tunai, 67100, diterima: 70000);
    final sendiri = await u.penjualan.Bayar(
      keranjang: IsiKeranjang(),
      pembayaran: [satu],
      kasir: rina,
      k: k,
      bagianTagihan: BagianTagihan(rata: true, tamu: [satu.tamu!]),
    );
    expect(await u.struk.AmbilBagianTagihan(sendiri.uuid), isNull, reason: 'Satu tamu = struk biasa.');
    expect(u.printer.kiriman, isEmpty);
  });

  test('struk satu tamu: bagian, metode, uang diterima, kembalian; tanpa rincian barang dan tanpa buka laci', () async {
    final hasil = await JualDibagi();

    await u.struk.CetakStrukTamu(hasil.uuid, tamuKe: 1);
    final teks = u.printer.AmbilTeks();
    expect(teks, contains('STRUK TAMU'));
    expect(teks, contains('Tamu 1 dari 3'));
    expect(teks, contains('Tagihan dibagi rata'));
    expect(teks, contains(hasil.nomor));
    expect(teks, contains('Kasir: Rina Wulandari'));
    expect(RegExp(r'Total tagihan\s+Rp 67\.100').hasMatch(teks), isTrue, reason: teks);
    expect(RegExp(r'Bagian tamu 1\s+Rp 22\.366').hasMatch(teks), isTrue, reason: teks);
    expect(RegExp(r'Tunai diterima\s+30\.000').hasMatch(teks), isTrue, reason: teks);
    expect(RegExp(r'Kembalian\s+7\.634').hasMatch(teks), isTrue, reason: teks);
    expect(teks, isNot(contains('Croissant')));
    expect(teks, isNot(contains('CETAK ULANG')));
    expect(u.printer.CekBukaLaci(), isFalse);
    expect(u.printer.kiriman, hasLength(1));
  });

  test('semua tamu: satu struk per tamu berurutan; non-tunai tanpa kembalian; cetak ulang bertanda', () async {
    final hasil = await JualDibagi();

    await u.struk.CetakStrukTamu(hasil.uuid, cetakUlang: true);
    expect(u.printer.kiriman, hasLength(3));
    final kedua = u.printer.AmbilTeks(1);
    expect(kedua, contains('Tamu 2 dari 3'));
    expect(kedua, contains('CETAK ULANG'));
    expect(RegExp(r'Bagian tamu 2\s+Rp 22\.366').hasMatch(kedua), isTrue, reason: kedua);
    expect(kedua, isNot(contains('Kembalian')));
    expect(kedua, isNot(contains('diterima')));
    final ketiga = u.printer.AmbilTeks(2);
    expect(ketiga, contains('Tamu 3 dari 3'));
    expect(RegExp(r'Bagian tamu 3\s+Rp 22\.368').hasMatch(ketiga), isTrue, reason: ketiga);
  });

  test('nomor tamu di luar rentang ditolak dan tidak mencetak apa pun', () async {
    final hasil = await JualDibagi();
    await expectLater(u.struk.CetakStrukTamu(hasil.uuid, tamuKe: 4), throwsA(isA<GalatKasir>()));
    await expectLater(u.struk.CetakStrukTamu(hasil.uuid, tamuKe: 0), throwsA(isA<GalatKasir>()));
    expect(u.printer.kiriman, isEmpty);
  });
}
