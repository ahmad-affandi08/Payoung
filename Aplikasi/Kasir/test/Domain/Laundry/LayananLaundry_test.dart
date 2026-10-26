import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananLaundry.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunDokumenKasir.dart';
import 'package:kasir/Domain/Struk/PenyusunStrukPenjualan.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Laundry bagian 2 (§9.9) di perangkat: tiket divalidasi (berat/item, nama tanpa pelanggan), estimasi dari durasi
/// pengaturan, blok `Laundry` ikut `Penjualan.Buat` & tersimpan lokal (nota & cetak ulang offline dengan QR lacak),
/// daftar cucian & ubah status online.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif(dataAwal: DataAwalUji(laundry: true));
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('berat & validasi tiket. Estimasi dari durasi reguler/express', () async {
    final k = await u.MuatKonteks();
    expect(k.laundry.aktif, isTrue);
    expect(LayananLaundry.UraiBerat(' 3,5 '), Decimal.parse('3.5'));
    expect(LayananLaundry.UraiBerat(''), isNull);
    for (final salah in ['0', '3,555', 'abc', '10000']) {
      expect(() => LayananLaundry.UraiBerat(salah), throwsA(isA<GalatKasir>()), reason: salah);
    }
    expect(
      () =>
          u.laundry.BuatTiket(jenisLayanan: 'Reguler', pengaturan: k.laundry, pelanggan: null, namaPelanggan: 'Ratna'),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'IsiLaundryKosong')),
    );
    expect(
      () => u.laundry.BuatTiket(
        jenisLayanan: 'Reguler',
        pengaturan: k.laundry,
        pelanggan: null,
        berat: Decimal.parse('2'),
      ),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'NamaWajib')),
    );
    final tiket = u.laundry.BuatTiket(
      jenisLayanan: 'Express',
      pengaturan: k.laundry,
      pelanggan: null,
      item: [(nama: ' Bed cover ', jumlah: 1), (nama: '', jumlah: 3)],
      namaPelanggan: ' Ratna ',
      noHp: '0812-3456-7890',
      parfum: 'Lavender',
    );
    expect(tiket.estimasiSelesaiPada, u.jam.toUtc().add(const Duration(hours: 24)));
    expect(tiket.item, [(nama: 'Bed cover', jumlah: 1)]);
    expect(tiket.namaPelanggan, 'Ratna');
    expect(LaundryKeranjang.DariJson(tiket.KeJson())?.RingkasIsi(), 'Bed cover ×1');
  });

  test('bayar: blok Laundry di outbox & tersimpan lokal. Struk & nota memuat rincian + QR lacak', () async {
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    final tiket = u.laundry.BuatTiket(
      jenisLayanan: 'Reguler',
      pengaturan: k.laundry,
      pelanggan: null,
      berat: LayananLaundry.UraiBerat('3,5'),
      namaPelanggan: 'Ratna',
      catatan: 'Pisahkan putih',
    );
    final keranjang = Keranjang(
      baris: [u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!)],
      laundry: tiket,
    );
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    final hasil = await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir)],
      kasir: rina,
      k: k,
    );
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
    expect(data['Laundry'], containsPair('Berat', '3.50'));
    expect(data['Laundry'], containsPair('JenisLayanan', 'Reguler'));
    expect(data['Laundry'], containsPair('NamaPelanggan', 'Ratna'));

    final jual = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
    expect(jual.Laundry, isNotNull);
    final struk = TataLetakStruk.KeTeks(
      PenyusunStrukPenjualan.Susun(
        await IdentitasStruk.Muat(u.repositori),
        DataStrukPenjualan(
          penjualan: jual,
          detail: await u.repositoriPenjualan.AmbilDetail(hasil.uuid),
          pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
          laundry: LaundryKeranjang.DariJson(jsonDecode(jual.Laundry!)),
          awalanLacakLaundry: k.laundry.awalanLacak,
        ),
      ),
      LebarKertas.Mm58,
    ).map((b) => b.trim()).toList();
    expect(struk, contains('TIKET LAUNDRY'));
    expect(struk.any((b) => b.startsWith('Berat') && b.endsWith('3,5 kg')), isTrue, reason: struk.join('\n'));
    expect(struk, contains('Catatan: Pisahkan putih'));
    expect(struk, contains('Lacak cucian:'));
    expect(struk, contains('[QR https://payoung.test/s/1a.${hasil.uuid.toUpperCase()}]'));

    final nota = TataLetakStruk.KeTeks(
      PenyusunDokumenKasir.SusunNotaLaundry(
        await IdentitasStruk.Muat(u.repositori),
        TiketLaundryPos.DariJson({
          'Uuid': hasil.uuid,
          'Nomor': hasil.nomor,
          'EstimasiSelesaiPada': '2026-10-14T02:25:00Z',
          'NamaPelanggan': 'Ratna',
          'JenisLayanan': 'Express',
          'Berat': '3.50',
          'Item': [
            {'Nama': 'Jas', 'Jumlah': 2},
          ],
          'Status': 'Siap',
          'LabelStatus': 'Siap diambil',
        }),
        awalanLacak: k.laundry.awalanLacak,
      ),
      LebarKertas.Mm58,
    ).map((b) => b.trim()).toList();
    expect(nota, contains('NOTA LAUNDRY'));
    expect(nota, contains(hasil.nomor));
    expect(nota.any((b) => b.startsWith('Jas') && b.endsWith('2')), isTrue);
    expect(nota, contains('Lacak cucian:'));
  });

  test('daftar cucian & ubah status online. Offline = PerluOnline, tanpa izin ditolak', () async {
    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(u.laundry.Cari(), throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')));

    final tiket = {
      'Uuid': 'P1',
      'Nomor': 'INV/1',
      'EstimasiSelesaiPada': '2026-10-14T02:25:00Z',
      'NamaPelanggan': 'Ratna',
      'JenisLayanan': 'Reguler',
      'Status': 'Siap',
      'LabelStatus': 'Siap diambil',
      'StatusBerikutnya': ['Diambil'],
    };
    u.server.penangan = (p) async => p.method == 'GET'
        ? Json({
            'Tiket': [tiket],
          }, 200)
        : Json({
            'Tiket': {...tiket, 'Status': 'Diambil', 'StatusBerikutnya': <String>[]},
          }, 200);
    final daftar = await u.laundry.Cari();
    final diambil = await u.laundry.UbahStatus(daftar.single, 'Diambil', kasir: rina);
    expect(diambil.status, 'Diambil');
    expect(jsonDecode(u.server.permintaan.last.body), {'Status': 'Diambil', 'UuidPengguna': rina.uuid});

    const tamu = StafLokal(uuid: '01K5TAMU000000000000000001', nama: 'Dodi', pemilik: false, izin: []);
    expect(
      () => u.laundry.UbahStatus(daftar.single, 'Diambil', kasir: tamu),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
    );
  });
}
