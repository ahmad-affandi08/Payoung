import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/Dapur/LayananRuteDapur.dart';
import 'package:kasir/Domain/Dapur/LayananTiketDapur.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';

import '../../Pendukung/LingkunganUji.dart';

/// D-310: kasir mengatur stasiun dapur tiap kategori dan menu dari aplikasi. Perlu izin `dapur.rute.atur` dan online.
void main() {
  late LingkunganUji u;
  late LayananRuteDapur layanan;

  const bar = '01K5STAS1VN000000000BAR001';
  const dapur = '01K5STAS1VN000000000DAPUR1';
  const kategoriKopi = '01K5KAT0000000000000K0P101';
  const produkKopi = '01K5PRD0000000000K0P1SVSV1';

  const kasirBoleh = StafLokal(
    uuid: '01K5USR0000000000000000001',
    nama: 'Rina Wulandari',
    pemilik: false,
    izin: [IzinKasir.penjualanBuat, IzinKasir.dapurRuteAtur],
  );
  const kasirTanpaIzin = StafLokal(
    uuid: '01K5USR0000000000000000002',
    nama: 'Budi Santoso',
    pemilik: false,
    izin: [IzinKasir.penjualanBuat],
  );

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await const RuteDapur(
      stasiun: [StasiunDapurLokal(bar, 'Bar'), StasiunDapurLokal(dapur, 'Dapur')],
      uuidBawaan: dapur,
      kategori: {kategoriKopi: bar},
    ).Simpan(u.repositori);
    layanan = LayananRuteDapur(klien: u.klien, repositori: u.repositori);
  });
  tearDown(() => u.Tutup());

  /// Server tiruan yang menjawab keadaan akhir seperti server sungguhan.
  void ServerMenjawab(String? uuidStasiun) => u.server.penangan = (p) async {
    final badan = jsonDecode(p.body) as Map<String, Object?>;
    return JsonUji({'Uuid': p.url.pathSegments.last, 'UuidStasiun': badan['UuidStasiun'] ?? uuidStasiun});
  };

  test('izin: hanya pemegang dapur.rute.atur (Kasir bawaan) yang boleh. Tanpa izin tidak menyentuh server', () async {
    expect(LayananRuteDapur.CekBoleh(kasirBoleh), isTrue);
    expect(LayananRuteDapur.CekBoleh(kasirTanpaIzin), isFalse);
    expect(
      () => layanan.AturKategori(kategoriKopi, uuidStasiun: dapur, kasir: kasirTanpaIzin),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
    );
    expect(u.server.permintaan, isEmpty);
  });

  test('kategori: dikirim ke server dengan pelaku, lalu rute lokal langsung berubah', () async {
    ServerMenjawab(null);

    final rute = await layanan.AturKategori(kategoriKopi, uuidStasiun: dapur, kasir: kasirBoleh);

    final kirim = u.server.permintaan.single;
    expect(kirim.method, 'POST');
    expect(kirim.url.path, endsWith('/dapur/rute/kategori/$kategoriKopi'));
    expect(jsonDecode(kirim.body), {'UuidPengguna': kasirBoleh.uuid, 'UuidStasiun': dapur});
    expect(rute.kategori[kategoriKopi], dapur);
    expect((await RuteDapur.Muat(u.repositori)).kategori[kategoriKopi], dapur, reason: 'tersimpan di perangkat');
  });

  test('menu: diatur lalu dikembalikan ke ikut kategori (null menghapus aturan menu)', () async {
    ServerMenjawab(null);

    var rute = await layanan.AturProduk(produkKopi, uuidStasiun: dapur, kasir: kasirBoleh);
    expect(rute.produk, {produkKopi: dapur});
    expect(u.server.permintaan.last.url.path, endsWith('/dapur/rute/produk/$produkKopi'));

    rute = await layanan.AturProduk(produkKopi, uuidStasiun: null, kasir: kasirBoleh);
    expect(rute.produk, isEmpty);
    expect(jsonDecode(u.server.permintaan.last.body), {'UuidPengguna': kasirBoleh.uuid, 'UuidStasiun': null});
    expect((await RuteDapur.Muat(u.repositori)).produk, isEmpty);
  });

  test('kategori: null mengembalikan ikut induk atau bawaan dan menghapus aturan kategori', () async {
    ServerMenjawab(null);

    final rute = await layanan.AturKategori(kategoriKopi, uuidStasiun: null, kasir: kasirBoleh);

    expect(rute.kategori, isEmpty);
    expect(rute.AmbilStasiun(kategoriKopi, const {})?.nama, 'Dapur', reason: 'kembali ke stasiun bawaan');
  });

  test('offline: pesan jelas, aturan lokal tidak berubah', () async {
    u.server.penangan = (_) async => throw http.ClientException('offline');

    await expectLater(
      layanan.AturKategori(kategoriKopi, uuidStasiun: dapur, kasir: kasirBoleh),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
    );

    expect((await RuteDapur.Muat(u.repositori)).kategori[kategoriKopi], bar);
  });

  test('server menolak (misal izin dicabut atau stasiun diarsipkan): kode dan pesan server diteruskan', () async {
    u.server.penangan = (_) async => JsonUji({
      'Galat': {
        'Kode': 'StasiunTidakDitemukan',
        'Pesan': 'Stasiun ini tidak ada, sudah diarsipkan, atau berupa checker.',
      },
    }, 404);

    await expectLater(
      layanan.AturProduk(produkKopi, uuidStasiun: dapur, kasir: kasirBoleh),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'StasiunTidakDitemukan')),
    );

    expect((await RuteDapur.Muat(u.repositori)).produk, isEmpty);
  });
}
