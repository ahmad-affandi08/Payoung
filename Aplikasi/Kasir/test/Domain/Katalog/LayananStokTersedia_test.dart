import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Katalog/LayananStokTersedia.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// F-07 + F-05, BR-05.2: salinan sisa stok Toko di kasir offline-first. Sisa efektif = stok salinan − penjualan lokal
/// yang belum tercakup salinan − isi keranjang (satuan dasar). Produk di luar daftar server dan perangkat tanpa salinan
/// tidak dibatasi.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late LayananStokTersedia stok;

  /// Server tiruan: jawaban `stok-tersedia` diatur [jawaban]; jalur lain offline.
  var jawaban = <String, String>{};
  var jumlahPanggilan = 0;

  void AturServer() {
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/stok-tersedia')) {
        jumlahPanggilan++;
        return JsonUji({
          'WaktuServer': '2026-09-24T01:00:00Z',
          'Produk': [
            for (final e in jawaban.entries) {'UuidProduk': e.key, 'Tersedia': e.value},
          ],
        });
      }
      throw http.ClientException('offline');
    };
  }

  LayananStokTersedia BuatLayanan() => LayananStokTersedia(
    klien: u.klien,
    repositori: u.repositori,
    repositoriPenjualan: u.repositoriPenjualan,
    jam: () => u.jam,
  );

  ProdukJual Produk(String uuid) => katalog.CariProduk(uuid)!;

  SatuanJual Satuan(String uuidProduk, String nama) => Produk(uuidProduk).satuan.firstWhere((s) => s.nama == nama);

  /// Keranjang berisi [jumlah] roti dalam satuan [nama] (tanpa pencegah stok: dibangun lewat `u.penjualan`).
  Keranjang KeranjangRoti(int jumlah, {String satuan = 'Pcs', Keranjang? dasar}) {
    final baris = u.penjualan.BuatBaris(
      katalog,
      k,
      Produk(UuidUji.roti),
      satuan: Satuan(UuidUji.roti, satuan),
      jumlah: Kuantitas.DariBulat(jumlah),
    );
    final awal = dasar ?? Keranjang.kosong;
    return awal.Salin(baris: [...awal.baris, baris]);
  }

  /// Jual [jumlah] roti pcs secara tunai di jam uji saat ini; hasilnya Uuid penjualan.
  Future<String> JualRoti(int jumlah, {String satuan = 'Pcs'}) async {
    final tunai = PembayaranMasukan(
      metode: k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai'),
      jumlah: Uang.DariBulat(5000000),
    );
    final hasil = await u.penjualan.Bayar(
      keranjang: KeranjangRoti(jumlah, satuan: satuan),
      pembayaran: [tunai],
      kasir: rina,
      k: k,
    );
    return hasil.uuid;
  }

  Kuantitas? Efektif(Keranjang keranjang, {String? kecualiBaris}) =>
      stok.HitungTersediaEfektif(UuidUji.roti, keranjang, katalog, kecualiBaris: kecualiBaris);

  Kuantitas Jml(int n) => Kuantitas.DariBulat(n);

  setUp(() async {
    u = LingkunganUji.Buat();
    jawaban = {};
    jumlahPanggilan = 0;
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    stok = BuatLayanan();
    AturServer();
  });
  tearDown(() => u.Tutup());

  group('tanpa batas', () {
    test('BR-05.2: tanpa salinan sama sekali semua produk tanpa batas dan tidak ada yang "Habis"', () {
      expect(stok.CekAdaSalinan, isFalse);
      expect(Efektif(KeranjangRoti(500)), isNull);
      expect(stok.CekHabis(UuidUji.roti, KeranjangRoti(500), katalog), isFalse);
    });

    test('BR-05.2: produk di luar daftar server (boleh minus, jasa, paket, resep) tanpa batas', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      expect(await stok.Segarkan(), isTrue);

      expect(stok.HitungTersediaEfektif(UuidUji.croissant, Keranjang.kosong, katalog), isNull);
      expect(stok.CekHabis(UuidUji.croissant, Keranjang.kosong, katalog), isFalse);
      expect(Efektif(Keranjang.kosong), Jml(10));
    });

    test('BR-05.2: daftar kosong yang sah dari server berarti semua produk tanpa batas', () async {
      expect(await stok.Segarkan(), isTrue);

      expect(stok.CekAdaSalinan, isTrue);
      expect(Efektif(KeranjangRoti(500)), isNull);
    });
  });

  group('salinan dan keranjang', () {
    test('BR-05.2: sisa efektif = stok salinan − isi keranjang', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();

      expect(Efektif(Keranjang.kosong), Jml(10));
      expect(Efektif(KeranjangRoti(3)), Jml(7));
      expect(stok.CekHabis(UuidUji.roti, KeranjangRoti(10), katalog), isTrue, reason: 'Semua stok sudah di keranjang.');
      expect(stok.CekHabis(UuidUji.roti, KeranjangRoti(9), katalog), isFalse);
    });

    test('BR-05.2: satuan jual dikonversi ke satuan dasar (1 lusin = 12 pcs)', () async {
      jawaban = {UuidUji.roti: '30.0000'};
      await stok.Segarkan();

      expect(Efektif(KeranjangRoti(1, satuan: 'Lusin')), Jml(18));
      expect(
        Efektif(
          KeranjangRoti(
            5,
            satuan: 'Pcs',
            dasar: KeranjangRoti(1, satuan: 'Lusin'),
          ),
        ),
        Jml(13),
      );
    });

    test('BR-05.2: baris yang sedang diubah dikecualikan lewat kecualiBaris', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      final keranjang = KeranjangRoti(4, dasar: KeranjangRoti(2, satuan: 'Lusin'));
      final barisPcs = keranjang.baris.last;

      expect(Efektif(keranjang), Jml(-18), reason: 'Keranjang melebihi salinan: sisa negatif, bukan error.');
      expect(Efektif(keranjang, kecualiBaris: barisPcs.uuid), Jml(-14));
    });

    test('BR-05.2: stok nol atau negatif dari server membuat produk "Habis"', () async {
      jawaban = {UuidUji.roti: '0.0000', UuidUji.croissant: '-3.0000'};
      await stok.Segarkan();

      expect(stok.CekHabis(UuidUji.roti, Keranjang.kosong, katalog), isTrue);
      expect(stok.CekHabis(UuidUji.croissant, Keranjang.kosong, katalog), isTrue);
      expect(stok.HitungTersediaEfektif(UuidUji.croissant, Keranjang.kosong, katalog), Jml(-3));
    });

    test('BR-05.2: jumlah desimal (kg) dihitung eksak tanpa pecahan biner', () async {
      jawaban = {UuidUji.roti: '2.5000'};
      await stok.Segarkan();
      final baris = u.penjualan
          .BuatBaris(katalog, k, Produk(UuidUji.roti), satuan: Satuan(UuidUji.roti, 'Pcs'))
          .Salin(jumlah: Kuantitas.Dari('0.7500'));

      expect(Efektif(Keranjang(baris: [baris])), Kuantitas.Dari('1.7500'));
    });
  });

  group('penjualan lokal yang belum tercakup', () {
    test('BR-05.2: penjualan setelah salinan dikurangkan, belum terkirim maupun sudah terkirim', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));

      final uuid = await JualRoti(3);
      await stok.HitungUlangTerjual();
      expect(Efektif(Keranjang.kosong), Jml(7), reason: 'Di outbox, dibuat setelah salinan.');

      await u.repositori.HapusOutbox([uuid]);
      await stok.HitungUlangTerjual();
      expect(Efektif(Keranjang.kosong), Jml(7), reason: 'Terkirim setelah salinan diambil: salinan belum memuatnya.');
    });

    test('BR-05.2: penjualan yang masih di outbox saat salinan diminta tetap dihitung walau sudah terkirim', () async {
      u.jam = u.jam.add(const Duration(minutes: 1));
      final uuid = await JualRoti(3);
      u.jam = u.jam.add(const Duration(minutes: 1));
      // Server belum melihat penjualan itu ketika menghitung salinan: stoknya masih 10.
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      expect(stok.salinan!.belumTercakup, {uuid});
      expect(Efektif(Keranjang.kosong), Jml(7));

      await u.repositori.HapusOutbox([uuid]);
      await stok.HitungUlangTerjual();
      expect(
        Efektif(Keranjang.kosong),
        Jml(7),
        reason: 'Dibuat sebelum salinan dan sudah terkirim, tetapi belum tercakup.',
      );
    });

    test('BR-05.2: penjualan lama yang sudah terkirim sebelum salinan diminta tidak dihitung dua kali', () async {
      u.jam = u.jam.add(const Duration(minutes: 1));
      final uuid = await JualRoti(3);
      await u.repositori.HapusOutbox([uuid]);
      u.jam = u.jam.add(const Duration(minutes: 1));
      // Server sudah memuat penjualan itu: 10 − 3 = 7.
      jawaban = {UuidUji.roti: '7.0000'};
      await stok.Segarkan();

      expect(stok.salinan!.belumTercakup, isEmpty);
      expect(Efektif(Keranjang.kosong), Jml(7));
    });

    test('BR-05.2: penjualan yang ditolak server (masih di outbox) tetap dihitung, konservatif', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));
      final uuid = await JualRoti(2);
      await u.db.customStatement('UPDATE Outbox SET Status = ? WHERE Uuid = ?', [StatusOutbox.perluTindakan, uuid]);

      await stok.HitungUlangTerjual();

      expect(Efektif(Keranjang.kosong), Jml(8));
    });

    test('BR-05.2: satuan jual penjualan lokal dikonversi ke satuan dasar (1 lusin = 12 pcs)', () async {
      jawaban = {UuidUji.roti: '30.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));
      await JualRoti(1, satuan: 'Lusin');

      await stok.HitungUlangTerjual();

      expect(Efektif(Keranjang.kosong), Jml(18));
    });

    test('BR-05.2: penjualan void tidak dihitung', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));
      final uuid = await JualRoti(4);
      await stok.HitungUlangTerjual();
      expect(Efektif(Keranjang.kosong), Jml(6));

      await (u.db.update(
        u.db.penjualan,
      )..where((p) => p.Uuid.equals(uuid))).write(const PenjualanCompanion(Status: Value(StatusPenjualanLokal.divoid)));
      await stok.HitungUlangTerjual();

      expect(Efektif(Keranjang.kosong), Jml(10));
    });

    test('BR-05.2: salinan baru setelah outbox kosong menghapus hitungan lokal yang sudah tercakup', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));
      final uuid = await JualRoti(3);
      await stok.HitungUlangTerjual();
      await u.repositori.HapusOutbox([uuid]);
      u.jam = u.jam.add(const Duration(minutes: 1));
      jawaban = {UuidUji.roti: '7.0000'};

      await stok.Segarkan();

      expect(Efektif(Keranjang.kosong), Jml(7), reason: 'Bukan 4: penjualan itu kini dilihat server.');
    });

    test('BR-05.2: pantauan basis data menghitung ulang otomatis saat penjualan tersimpan', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      var berubah = 0;
      stok.saatBerubah = () => berubah++;
      await stok.Mulai();
      u.jam = u.jam.add(const Duration(minutes: 1));

      await JualRoti(3);
      for (var i = 0; i < 50 && Efektif(Keranjang.kosong) != Jml(7); i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }

      expect(Efektif(Keranjang.kosong), Jml(7));
      expect(berubah, greaterThan(0));
      stok.Berhenti();
    });
  });

  group('server tak terjangkau dan penyimpanan', () {
    test('BR-05.2: offline atau galat server sementara tidak melempar dan salinan terakhir tetap dipakai', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      expect(await stok.Segarkan(), isTrue);

      u.server.penangan = (_) async => throw http.ClientException('offline');
      expect(await stok.Segarkan(), isFalse);
      u.server.penangan = (_) async => http.Response('gangguan', 503);
      expect(await stok.Segarkan(), isFalse);
      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'TerlaluBanyakPermintaan', 'Pesan': 'Coba lagi nanti.'},
      }, 429);
      expect(await stok.Segarkan(), isFalse);

      expect(Efektif(Keranjang.kosong), Jml(10));
    });

    test('BR-05.2: rute stok tersedia tidak ada lagi (404, server dikembalikan ke versi lama): salinan dibuang, penjualan tidak ditahan data usang', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      expect(await stok.Segarkan(), isTrue);
      expect(Efektif(Keranjang.kosong), Jml(10));

      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'FiturTidakTersedia', 'Pesan': 'Belum ada.'},
      }, 404);
      expect(await stok.Segarkan(), isFalse);

      expect(stok.CekAdaSalinan, isFalse);
      expect(Efektif(KeranjangRoti(99)), isNull);
    });

    test('BR-05.2: jawaban bukan daftar stok ({} dari server lama) diabaikan, bukan dianggap tanpa batas', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();

      u.server.penangan = (_) async => JsonUji(<String, Object?>{});
      expect(await stok.Segarkan(), isFalse);
      u.server.penangan = (_) async => JsonUji(DataAwalUji());
      expect(await stok.Segarkan(), isFalse);

      expect(Efektif(Keranjang.kosong), Jml(10));
    });

    test('BR-05.2: tanpa salinan dan server tak terjangkau, tetap tanpa batas', () async {
      u.server.penangan = (_) async => throw http.ClientException('offline');

      expect(await stok.Segarkan(), isFalse);
      expect(stok.CekAdaSalinan, isFalse);
      expect(Efektif(KeranjangRoti(99)), isNull);
    });

    test('BR-05.2: salinan tersimpan berlaku setelah aplikasi dibuka ulang dalam keadaan offline', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      u.jam = u.jam.add(const Duration(minutes: 1));
      await JualRoti(3);

      u.server.penangan = (_) async => throw http.ClientException('offline');
      final dibuka = BuatLayanan();
      expect(dibuka.CekAdaSalinan, isFalse);
      await dibuka.Muat();

      expect(dibuka.CekAdaSalinan, isTrue);
      expect(dibuka.HitungTersediaEfektif(UuidUji.roti, Keranjang.kosong, katalog), Jml(7));
      expect(dibuka.HitungTersediaEfektif(UuidUji.croissant, Keranjang.kosong, katalog), isNull);
    });

    test('BR-05.2: salinan tersimpan yang rusak dibuang, bukan membuat aplikasi gagal', () async {
      await u.repositori.SimpanPengaturan(KunciPengaturan.stokTersedia, '{rusak');
      final dibuka = BuatLayanan();

      await dibuka.Muat();

      expect(dibuka.CekAdaSalinan, isFalse);
      expect(SalinanStokTersedia.DariJson('[]'), isNull);
      expect(SalinanStokTersedia.DariJson('{"DiambilPada":"kemarin","Produk":{}}'), isNull);
    });

    test('BR-05.2: ditulis ke pengaturan hanya bila isinya berubah atau salinan tersimpan sudah usang', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();
      final pertama = await u.repositori.AmbilPengaturan(KunciPengaturan.stokTersedia);
      expect(pertama, isNotNull);
      const penanda = 'penanda-tidak-ditimpa';
      await u.repositori.SimpanPengaturan(KunciPengaturan.stokTersedia, penanda);

      u.jam = u.jam.add(const Duration(minutes: 1));
      await stok.Segarkan();
      expect(
        await u.repositori.AmbilPengaturan(KunciPengaturan.stokTersedia),
        penanda,
        reason: 'Isi sama dan masih baru: tidak ditulis ulang.',
      );

      jawaban = {UuidUji.roti: '9.0000'};
      await stok.Segarkan();
      final berubah = await u.repositori.AmbilPengaturan(KunciPengaturan.stokTersedia);
      expect(berubah, isNot(penanda));
      expect((jsonDecode(berubah!) as Map<String, Object?>)['Produk'], {UuidUji.roti: '9.0000'});

      await u.repositori.SimpanPengaturan(KunciPengaturan.stokTersedia, penanda);
      u.jam = u.jam.add(LayananStokTersedia.selangSimpanUlang);
      await stok.Segarkan();
      expect(
        await u.repositori.AmbilPengaturan(KunciPengaturan.stokTersedia),
        isNot(penanda),
        reason: 'Salinan tersimpan sudah usang: DiambilPada diperbarui.',
      );
    });

    test('BR-05.2: Kosongkan (aktivasi ulang) menghapus salinan di memori dan di pengaturan', () async {
      jawaban = {UuidUji.roti: '10.0000'};
      await stok.Segarkan();

      await stok.Kosongkan();

      expect(stok.CekAdaSalinan, isFalse);
      expect(Efektif(KeranjangRoti(99)), isNull);
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.stokTersedia), isNull);
    });

    test('BR-05.2: bentuk simpan bolak-balik tanpa kehilangan jumlah desimal', () {
      final salinan = SalinanStokTersedia(
        diambilPada: DateTime.utc(2026, 10, 8, 3),
        waktuServer: DateTime.utc(2026, 10, 8, 2, 59),
        tersedia: {'P1': Kuantitas.Dari('12.5000'), 'P2': Kuantitas.Dari('-3')},
        belumTercakup: {'A', 'B'},
      );

      final dibaca = SalinanStokTersedia.DariJson(salinan.KeJson())!;

      expect(dibaca.diambilPada, DateTime.utc(2026, 10, 8, 3));
      expect(dibaca.waktuServer, DateTime.utc(2026, 10, 8, 2, 59));
      expect(dibaca.tersedia, {'P1': Kuantitas.Dari('12.5'), 'P2': Kuantitas.Dari('-3.0000')});
      expect(dibaca.belumTercakup, {'A', 'B'});
    });

    test('BR-05.2: format jumlah tampilan Indonesia (koma desimal, tanpa nol di belakang)', () {
      expect(LayananStokTersedia.FormatJumlah(Kuantitas.Dari('12.0000')), '12');
      expect(LayananStokTersedia.FormatJumlah(Kuantitas.Dari('2.5000')), '2,5');
      expect(LayananStokTersedia.FormatJumlah(Kuantitas.Dari('0.0500')), '0,05');
      expect(LayananStokTersedia.UraiKuantitas('abc'), isNull);
      expect(LayananStokTersedia.UraiKuantitas('1.23456'), isNull);
      expect(LayananStokTersedia.KeDasar(Kuantitas.DariBulat(2), '12'), Kuantitas.DariBulat(24));
      expect(LayananStokTersedia.KeDasar(Kuantitas.DariBulat(2), 'rusak'), Kuantitas.DariBulat(2));
      expect(LayananStokTersedia.KeDasar(Kuantitas.DariBulat(2), null), Kuantitas.DariBulat(2));
    });
  });

  test('BR-05.2: Segarkan beruntun tidak menumpuk permintaan (satu permintaan berjalan sekali)', () async {
    jawaban = {UuidUji.roti: '10.0000'};

    final hasil = await Future.wait([stok.Segarkan(), stok.Segarkan()]);

    expect(hasil.where((b) => b), hasLength(1));
    expect(jumlahPanggilan, 1);
  });
}
