import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Meja/LayananPesanSendiri.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-17 self-order di perangkat (v2.02): terima pesanan QR → klaim di server → pesanan terbuka meja (dibuka bila
/// perlu) + baris dikirim ke dapur lewat outbox dengan Uuid baris pesanan QR; tolak dengan alasan; produk yang belum
/// ada di katalog ditolak sebelum menghubungi server; sudah diproses perangkat lain tidak mengubah data lokal.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late LayananPesanSendiri layanan;
  final permintaan = <http.Request>[];

  const mejaD01 = '01K5MEJA0000000000000D0101';

  PesananSendiriPos Pesanan({
    String produk = UuidUji.americano,
    String? uuidMeja = mejaD01,
    String satuan = UuidUji.psAmericano,
    String nama = 'Americano Panas',
    String harga = '15000.00',
    String jumlah = '2',
  }) => PesananSendiriPos.DariJson({
    'Uuid': '01K5QR00000000000000000001',
    'Nomor': 'QR/SLB/260924-0001',
    'UuidMeja': uuidMeja,
    'NamaMeja': 'D-01',
    'NamaPemesan': 'Bu Ani',
    'Catatan': null,
    'DibuatPada': '2026-09-24T01:00:00Z',
    'Subtotal': '30000.00',
    'Baris': [
      {
        'Uuid': '01K5QRBAR1S000000000000001',
        'UuidProduk': produk,
        'UuidProdukSatuan': satuan,
        'NamaProduk': nama,
        'Jumlah': jumlah,
        'HargaSatuan': harga,
        'HargaPilihan': '0.00',
        'Pilihan': <Object?>[],
        'Catatan': 'Tanpa gula',
      },
    ],
  });

  Future<List<BarisOutbox>> Outbox() => (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();

  setUp(() async {
    u = LingkunganUji.Buat();
    permintaan.clear();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    await u.SiapkanMeja();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    layanan = LayananPesanSendiri(
      klien: u.klien,
      pesananMeja: u.pesananMeja,
      repositoriMeja: u.repositoriMeja,
      penjualan: u.penjualan,
    );
    u.server.penangan = (p) async {
      permintaan.add(p);
      return http.Response(jsonEncode({'Uuid': 'x', 'Status': 'Diterima'}), 200);
    };
  });
  tearDown(() => u.Tutup());

  test('terima: klaim server dengan Uuid pesanan baru, buka pesanan meja + kirim baris ke dapur', () async {
    final pesanan = await layanan.Terima(Pesanan(), kasir: rina, katalog: katalog, k: k);

    final klaim = permintaan.single;
    expect(klaim.url.path, '/api/pos/v1/pesan-sendiri/01K5QR00000000000000000001/terima');
    final body = jsonDecode(klaim.body) as Map<String, Object?>;
    expect(body['UuidPengguna'], rina.uuid);
    expect(body['UuidPesananTerbuka'], pesanan.uuid);

    expect(pesanan.namaMeja, 'D-01');
    expect(pesanan.label, 'Bu Ani');
    final baris = pesanan.AmbilBarisAktif().single;
    expect(baris.uuid, '01K5QRBAR1S000000000000001');
    expect(baris.jumlah, '2.0000');
    expect(baris.catatan, 'Tanpa gula');
    expect(baris.dikirimKeDapur, isTrue);

    final outbox = await Outbox();
    expect(outbox.map((o) => o.Jenis), ['PesananTerbuka.Buka', 'PesananTerbuka.Tambah']);
    expect(outbox.first.Uuid, pesanan.uuid);
    expect((jsonDecode(outbox.last.Data) as Map<String, Object?>)['KirimDapur'], true);
  });

  test('meja sudah punya pesanan terbuka: baris ditambahkan ke pesanan itu (ronde baru)', () async {
    final ada = await u.pesananMeja.Buka(kasir: rina, k: k, meja: await u.repositoriMeja.CariMeja(mejaD01));
    final hasil = await layanan.Terima(Pesanan(), kasir: rina, katalog: katalog, k: k);
    expect(hasil.uuid, ada.uuid);
    expect((jsonDecode(permintaan.single.body) as Map<String, Object?>)['UuidPesananTerbuka'], ada.uuid);
    expect((await Outbox()).map((o) => o.Jenis), ['PesananTerbuka.Buka', 'PesananTerbuka.Tambah']);
  });

  test('v2.06 baris varian dari QR: anak varian dipakai sebagai produk, harga varian dari katalog perangkat', () async {
    // Katalog perangkat dengan anak varian "Kaos Kopi Senja L" (induk Kaos).
    const kaosL = '01K5PRD000000000000KA0SL01';
    const psKaosL = '01K5PS000000000000KA0SL001';
    final isi = KatalogUji();
    (isi['Produk']! as List<Object?>).add({
      ...ProdukUji(kaosL, 'Kaos Kopi Senja L', sku: 'KAOS-L'),
      'UuidInduk': UuidUji.kaos,
      'AtributVarian': [
        {'Nama': 'Ukuran', 'Nilai': 'L'},
      ],
    });
    (isi['ProdukSatuan']! as List<Object?>).add(SatuanProdukUji(psKaosL, kaosL, UuidUji.satuanPcs));
    (isi['ProdukHarga']! as List<Object?>).add(HargaUji('01K5HRG000000000000KA0SL01', kaosL, psKaosL, '99000.00'));
    await u.SiapkanKatalog(isi);
    katalog = await u.MuatKatalog();

    final pesanan = await layanan.Terima(
      Pesanan(produk: kaosL, satuan: psKaosL, nama: 'Kaos Kopi Senja — L', harga: '99000.00', jumlah: '1'),
      kasir: rina,
      katalog: katalog,
      k: k,
    );

    final item = pesanan.AmbilBarisAktif().single;
    expect(item.uuidProduk, kaosL);
    expect(item.hargaSatuan, '99000.00');
  });

  test('produk belum di katalog: ditolak sebelum menghubungi server', () async {
    await expectLater(
      () => layanan.Terima(
        Pesanan(produk: '01K5PRODUKTIDAKADA00000001'),
        kasir: rina,
        katalog: katalog,
        k: k,
      ),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ProdukTidakDikenal')),
    );
    expect(permintaan, isEmpty);
    expect(await Outbox(), isEmpty);
  });

  test('sudah diterima perangkat lain (409): data lokal tidak berubah', () async {
    u.server.penangan = (p) async => http.Response(
      jsonEncode({
        'Galat': {'Kode': 'SudahDiproses', 'Pesan': 'Pesanan sudah diterima perangkat lain.'},
      }),
      409,
    );
    await expectLater(
      () => layanan.Terima(Pesanan(), kasir: rina, katalog: katalog, k: k),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'SudahDiproses')),
    );
    expect(await Outbox(), isEmpty);
    expect(await u.repositoriMeja.CariPesananDiMeja(mejaD01), isNull);
  });

  test('tolak: alasan wajib, dikirim ke server. Staf tanpa izin ditolak', () async {
    await expectLater(
      () => layanan.Tolak(Pesanan(), kasir: rina, alasan: ' x '),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'AlasanWajib')),
    );
    await layanan.Tolak(Pesanan(), kasir: rina, alasan: 'Menu habis');
    expect(permintaan.single.url.path, '/api/pos/v1/pesan-sendiri/01K5QR00000000000000000001/tolak');
    expect(jsonDecode(permintaan.single.body), {'UuidPengguna': rina.uuid, 'Alasan': 'Menu habis'});

    const tamu = StafLokal(uuid: '01K5STAF000000000000000009', nama: 'Tamu', pemilik: false, izin: []);
    await expectLater(
      () => layanan.Terima(Pesanan(), kasir: tamu, katalog: katalog, k: k),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
    );
  });
}
