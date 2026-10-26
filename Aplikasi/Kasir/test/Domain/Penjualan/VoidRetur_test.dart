import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/RepositoriPenjualan.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananReturPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananVoidPenjualan.dart';
import 'package:kasir/Domain/Penjualan/PenghitungNilaiRetur.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';
import '../../Pendukung/StrukUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

final RegExp polaUlid = RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$');

/// Rincian F-09 fase 1 di perangkat: void transaksi shift yang sama, retur dari struk (nilai proporsional sama dengan
/// server), nomor RJ, payload outbox persis kontrak, dan kas seharusnya shift setelah void & retur.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;
  late StafLokal budi;
  late StafLokal sari;
  late BarisShift shift;

  late LayananVoidPenjualan layananVoid;
  late LayananReturPenjualan layananRetur;

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

  Future<PenjualanTersimpan> JualTunai() => u.penjualan.Bayar(
    keranjang: KeranjangContoh(),
    pembayaran: [PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(100000))],
    kasir: rina,
    k: k,
  );

  Future<PenjualanTersimpan> JualCampuran() => u.penjualan.Bayar(
    keranjang: KeranjangContoh(),
    pembayaran: [
      PembayaranMasukan(metode: Metode('QrisStatis'), jumlah: Uang.DariBulat(40000)),
      PembayaranMasukan(metode: Metode('Tunai'), jumlah: Uang.DariBulat(50000)),
    ],
    kasir: rina,
    k: k,
  );

  Future<List<BarisOutbox>> Outbox() => (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();

  Future<Map<String, Object?>> DataOutbox(String jenis) async =>
      jsonDecode((await Outbox()).lastWhere((o) => o.Jenis == jenis).Data) as Map<String, Object?>;

  /// Server tiruan: `penjualan/cari` mengembalikan [struk]; permintaan lain ditolak.
  void ServerStruk(Map<String, Object?> struk) => u.server.penangan = (p) async {
    if (p.url.path.endsWith('/api/pos/v1/penjualan/cari')) {
      return JsonUji(struk);
    }
    return JsonUji({
      'Galat': {'Kode': 'TidakDiharapkan', 'Pesan': 'Tidak diharapkan'},
    }, 400);
  };

  Future<void> Siapkan() async {
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    budi = await u.Staf('Budi Santoso');
    sari = await u.Staf('Sari Lestari');
    shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    layananVoid = LayananVoidPenjualan(
      repositori: u.repositori,
      repositoriPenjualan: u.repositoriPenjualan,
      jam: () => u.jam,
    );
    layananRetur = LayananReturPenjualan(
      klien: u.klien,
      repositori: u.repositori,
      repositoriPenjualan: u.repositoriPenjualan,
      jam: () => u.jam,
    );
  }

  setUp(() async {
    u = LingkunganUji.Buat();
    await Siapkan();
  });
  tearDown(() => u.Tutup());

  group('nilai retur (sama dengan server PenghitungNilaiRetur)', () {
    BarisPenjualanCariPos Baris(String jumlah, String total, {String? bisa, String? nilaiBisa}) =>
        BarisPenjualanCariPos.DariJson(
          BarisStrukUji('01K5BRS0000000000000000009', 'Uji', 'pcs', jumlah, total, bisa: bisa, nilaiBisa: nilaiBisa),
        );

    test('proporsional TotalBaris × jumlah ÷ jumlah jual, dibulatkan ke sen HalfUp', () {
      expect(PenghitungNilaiRetur.Hitung(Baris('3.0000', '100000.00'), Kuantitas.Dari('1')), Uang.Dari('33333.33'));
      expect(PenghitungNilaiRetur.Hitung(Baris('3.0000', '100000.00'), Kuantitas.Dari('2')), Uang.Dari('66666.67'));
      // Tepat di tengah (0,025) → menjauhi nol.
      expect(PenghitungNilaiRetur.Hitung(Baris('2.0000', '0.05'), Kuantitas.Dari('1')), Uang.Dari('0.03'));
      // Satuan desimal: 0,75 dari 2,5 kg Rp 187.500.
      expect(PenghitungNilaiRetur.Hitung(Baris('2.5000', '187500.00'), Kuantitas.Dari('0.75')), Uang.Dari('56250.00'));
      expect(
        PenghitungNilaiRetur.HitungBagian(Uang.Dari('10000.00'), Kuantitas.Dari('0.3333'), Kuantitas.Dari('1')),
        Uang.Dari('3333.00'),
      );
    });

    test('retur yang menghabiskan sisa baris mengambil NilaiBisaDiretur agar Σ retur = TotalBaris', () {
      // Retur pertama 1 dari 3 = Rp 33.333,33; sisa 2 bernilai Rp 66.666,67 (bukan 2 × 33.333,33).
      final sisa = Baris('3.0000', '100000.00', bisa: '2.0000', nilaiBisa: '66666.67');
      expect(PenghitungNilaiRetur.Hitung(sisa, Kuantitas.Dari('2')), Uang.Dari('66666.67'));
      expect(Uang.Dari('33333.33').Tambah(PenghitungNilaiRetur.Hitung(sisa, Kuantitas.Dari('2'))), Uang.Dari('100000'));
      // Bukan sisa penuh → tetap proporsional terhadap jumlah jual.
      expect(PenghitungNilaiRetur.Hitung(sisa, Kuantitas.Dari('1')), Uang.Dari('33333.33'));
    });
  });

  group('void transaksi (BR-09.1)', () {
    test('payload Penjualan.Void persis kontrak. Status Void + VoidPenjualan + outbox satu transaksi setelah '
        'Penjualan.Buat', () async {
      final jual = await JualTunai();
      u.jam = u.jam.add(const Duration(minutes: 3));

      final refund = await layananVoid.Void(
        uuidPenjualan: jual.uuid,
        kasir: rina,
        alasan: '  Salah input pesanan meja 4  ',
        penyetuju: budi,
      );

      expect(refund.tunai, Uang.DariBulat(67100), reason: 'Tunai bersih = Rp 100.000 − kembalian Rp 32.900.');
      expect(refund.nonTunai, Uang.Nol());
      final outbox = await Outbox();
      expect(outbox.map((o) => o.Jenis), ['Shift.Buka', 'Penjualan.Buat', 'Penjualan.Void']);
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(data, {
        'UuidPenjualan': jual.uuid,
        'UuidPengguna': '01K5STAF000000000000000001',
        'UuidPenyetuju': '01K5STAF000000000000000002',
        'Alasan': 'Salah input pesanan meja 4',
        'DivoidPada': '2026-09-24T01:03:00.000Z',
      });
      expect(outbox.last.Uuid, matches(polaUlid));

      final dokumen = (await u.db.select(u.db.voidPenjualan).get()).single;
      expect(dokumen.Uuid, outbox.last.Uuid, reason: 'Uuid item outbox = Uuid VoidPenjualan.');
      expect(
        (dokumen.UuidShift, dokumen.Nominal, dokumen.RefundTunai, dokumen.RefundNonTunai),
        (shift.Uuid, '67100.00', '67100.00', '0.00'),
      );
      expect((await u.repositoriPenjualan.CariPenjualan(jual.uuid))!.Status, StatusPenjualanLokal.divoid);

      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: rina, alasan: 'Ulang lagi', penyetuju: budi),
        GalatDengan('SudahDivoid'),
      );
    });

    test('pembayaran campuran: tunai bersih dari laci, non-tunai refund manual (BR-09.2)', () async {
      final jual = await JualCampuran();
      final refund = await layananVoid.Void(uuidPenjualan: jual.uuid, kasir: budi, alasan: 'Pelanggan batal');
      // QRIS Rp 40.000 + tunai Rp 50.000 untuk Rp 67.100 → kembalian Rp 22.900 → tunai bersih Rp 27.100.
      expect(refund.tunai, Uang.DariBulat(27100));
      expect(refund.nonTunai, Uang.DariBulat(40000));
      expect(refund.metodeNonTunai, ['QRIS']);
      expect((await DataOutbox('Penjualan.Void'))['UuidPenyetuju'], budi.uuid, reason: 'Budi menyetujui dirinya.');
    });

    test('alasan < 5 huruf, tanpa penyetuju, penyetuju tanpa izin, kasir tanpa izin → ditolak. Tidak ada yang '
        'tersimpan', () async {
      final jual = await JualTunai();
      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: rina, alasan: 'ok ', penyetuju: budi),
        GalatDengan('AlasanDiperlukan'),
      );
      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: rina, alasan: 'Salah input'),
        GalatDengan('PersetujuanDiperlukan'),
      );
      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: rina, alasan: 'Salah input', penyetuju: sari),
        GalatDengan('PenyetujuTidakBerwenang'),
      );
      const tamu = StafLokal(uuid: '01K5STAF000000000000000009', nama: 'Tamu', pemilik: false, izin: []);
      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: tamu, alasan: 'Salah input', penyetuju: budi),
        GalatDengan('TanpaIzin'),
      );
      expect(await u.db.select(u.db.voidPenjualan).get(), isEmpty);
      expect((await Outbox()).where((o) => o.Jenis == 'Penjualan.Void'), isEmpty);
      expect((await u.repositoriPenjualan.CariPenjualan(jual.uuid))!.Status, StatusPenjualanLokal.lunas);
    });

    test('shift penjualan sudah ditutup → VoidTidakDiizinkan (pakai retur)', () async {
      final jual = await JualTunai();
      await u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(567100));
      expect(
        LayananVoidPenjualan.CekBisaDivoid((await u.repositoriPenjualan.CariPenjualan(jual.uuid))!, null),
        isFalse,
      );
      await expectLater(
        layananVoid.Void(uuidPenjualan: jual.uuid, kasir: budi, alasan: 'Salah input'),
        GalatDengan('VoidTidakDiizinkan'),
      );
    });
  });

  group('retur dari struk', () {
    List<PilihanReturBaris> Pilih(HasilCariPenjualan hasil, Map<String, (String, String)> jumlah) => [
      for (final b in hasil.baris)
        if (jumlah[b.uuid] case (final j, final kondisi))
          PilihanReturBaris(baris: b, jumlah: Kuantitas.Dari(j), kondisi: kondisi),
    ];

    test('cari online memakai nomor persis. Payload ReturPenjualan.Buat persis kontrak. Nomor RJ per perangkat '
        'per hari. Refund tunai + transfer', () async {
      ServerStruk(StrukUji());
      final hasil = await layananRetur.Cari('  ${UuidStruk.nomor} ');
      expect(u.server.permintaan.single.url.query, 'nomor=INV%2FSLB%2F260920%2FPOS-002-0007');
      u.jam = u.jam.add(const Duration(minutes: 10));

      final tersimpan = await layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {
          UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual),
          UuidStruk.bijiKopi: ('0.75', KondisiRetur.rusak),
        }),
        alasan: 'Kemasan bocor saat dibawa pulang',
        refundTunai: Uang.DariBulat(50000),
        metodeTransfer: Metode('Transfer'),
        kasir: rina,
        penyetuju: budi,
        k: k,
      );

      expect(tersimpan.nomor, 'RJ/SLB/260924/POS-001-0001');
      expect(tersimpan.totalRefund, Uang.Dari('89583.33'), reason: 'Rp 33.333,33 + Rp 56.250.');
      expect(tersimpan.refundTransfer, Uang.Dari('39583.33'));

      final outbox = (await Outbox()).last;
      expect(outbox.Jenis, 'ReturPenjualan.Buat');
      final data = jsonDecode(outbox.Data) as Map<String, Object?>;
      final retur = (await u.db.select(u.db.returPenjualan).get()).single;
      final detail = await u.repositoriPenjualan.AmbilDetailRetur(retur.Uuid);
      final refund = await u.repositoriPenjualan.AmbilPembayaranRetur(retur.Uuid);
      String UuidDetail(String asal) => detail.firstWhere((d) => d.UuidPenjualanDetail == asal).Uuid;
      String UuidRefund(String jenis) => refund.firstWhere((r) => r.Jenis == jenis).Uuid;
      expect(outbox.Uuid, retur.Uuid, reason: 'Uuid item outbox = Uuid ReturPenjualan.');
      expect(data, {
        'UuidPenjualanAsal': UuidStruk.penjualan,
        'UuidShift': shift.Uuid,
        'UuidPengguna': '01K5STAF000000000000000001',
        'UuidPenyetuju': '01K5STAF000000000000000002',
        'Nomor': 'RJ/SLB/260924/POS-001-0001',
        'Alasan': 'Kemasan bocor saat dibawa pulang',
        'DibuatPada': '2026-09-24T01:10:00.000Z',
        'Baris': [
          {
            'Uuid': UuidDetail(UuidStruk.kopiLiter),
            'UuidPenjualanDetail': UuidStruk.kopiLiter,
            'Jumlah': '1.0000',
            'Kondisi': 'LayakJual',
          },
          {
            'Uuid': UuidDetail(UuidStruk.bijiKopi),
            'UuidPenjualanDetail': UuidStruk.bijiKopi,
            'Jumlah': '0.7500',
            'Kondisi': 'Rusak',
          },
        ],
        'Refund': [
          {'Uuid': UuidRefund('Tunai'), 'UuidMetodePembayaran': '01K5MTD0000000000000000001', 'Jumlah': '50000.00'},
          {'Uuid': UuidRefund('Transfer'), 'UuidMetodePembayaran': '01K5MTD0000000000000000004', 'Jumlah': '39583.33'},
        ],
        'Ringkasan': {'TotalRefund': '89583.33'},
      });
      expect([retur.MetodeRefund, retur.RefundTunai, retur.TotalRefund], ['Campuran', '50000.00', '89583.33']);
      expect(detail.map((d) => d.NilaiBaris), containsAll(['33333.33', '56250.00']));

      // Retur kedua di hari yang sama → -0002; hari bisnis berikutnya → mulai 0001 lagi.
      final kedua = await layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {UuidStruk.airMineral: ('1', KondisiRetur.layakJual)}),
        alasan: 'Salah ambil barang',
        refundTunai: Uang.Nol(),
        kasir: budi,
        k: k,
      );
      expect(kedua.nomor, 'RJ/SLB/260924/POS-001-0002');
      final dataKedua = await DataOutbox('ReturPenjualan.Buat');
      expect(dataKedua['Refund'], isEmpty, reason: 'Total Rp 0 → tanpa baris refund.');
      expect(dataKedua['Ringkasan'], {'TotalRefund': '0.00'});

      u.jam = DateTime.utc(2026, 9, 25, 2);
      final besok = await layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual)}),
        alasan: 'Rasa tidak sesuai',
        refundTunai: Uang.Dari('33333.33'),
        kasir: budi,
        k: k,
      );
      expect(besok.nomor, 'RJ/SLB/260925/POS-001-0001');
    });

    test(
      'F-12 retur penjualan tempo: potong piutang lebih dulu (≤ sisa), sisanya tunai. Refund tunai lebih ditolak',
      () async {
        ServerStruk(
          StrukUji(
            sisaPiutang: '50000.00',
            pembayaran: [
              {
                'Uuid': '01K5BYR0000000000000000009',
                'UuidMetodePembayaran': '01K5MTD0000000000000000007',
                'JenisMetode': 'Tempo',
                'NamaMetode': 'Tempo',
                'Jumlah': '287500.00',
                'Referensi': null,
              },
            ],
          ),
        );
        final hasil = await layananRetur.Cari(UuidStruk.nomor);
        expect(hasil.penjualan.sisaPiutang, '50000.00');
        final pilihan = Pilih(hasil, {
          UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual),
          UuidStruk.bijiKopi: ('0.75', KondisiRetur.layakJual),
        });
        expect(LayananReturPenjualan.HitungPotongPiutang(hasil, Uang.Dari('89583.33')), Uang.DariBulat(50000));
        expect(LayananReturPenjualan.HitungPotongPiutang(hasil, Uang.DariBulat(20000)), Uang.DariBulat(20000));

        await expectLater(
          layananRetur.Simpan(
            hasil: hasil,
            pilihan: pilihan,
            alasan: 'Barang tidak sesuai pesanan',
            refundTunai: Uang.Dari('89583.33'),
            kasir: rina,
            penyetuju: budi,
            k: k,
          ),
          GalatDengan('RefundTidakSesuai'),
        );

        final tersimpan = await layananRetur.Simpan(
          hasil: hasil,
          pilihan: pilihan,
          alasan: 'Barang tidak sesuai pesanan',
          refundTunai: Uang.Dari('39583.33'),
          kasir: rina,
          penyetuju: budi,
          k: k,
        );
        expect(tersimpan.potongPiutang, Uang.DariBulat(50000));
        expect(tersimpan.refundTransfer, Uang.Nol());
        final data = await DataOutbox('ReturPenjualan.Buat');
        final refund = [
          for (final r in data['Refund']! as List<Object?>)
            ((r! as Map<String, Object?>)['UuidMetodePembayaran'], (r as Map<String, Object?>)['Jumlah']),
        ];
        expect(refund, [('01K5MTD0000000000000000007', '50000.00'), ('01K5MTD0000000000000000001', '39583.33')]);
        expect((await u.db.select(u.db.returPenjualan).get()).single.MetodeRefund, 'Campuran');
      },
    );

    test('jumlah melebihi sisa, desimal untuk satuan bulat, alasan pendek, refund tidak sesuai, tanpa penyetuju → '
        'ditolak dan nomor tidak terpakai', () async {
      ServerStruk(
        StrukUji(
          baris: [
            BarisStrukUji(
              UuidStruk.kopiLiter,
              'Kopi Susu Literan 1 L',
              'btl',
              '3.0000',
              '100000.00',
              sudah: '1.0000',
              bisa: '2.0000',
              nilaiBisa: '66666.67',
            ),
          ],
        ),
      );
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      Future<ReturTersimpan> Simpan(String jumlah, {String alasan = 'Kemasan bocor', Uang? tunai, StafLokal? p}) =>
          layananRetur.Simpan(
            hasil: hasil,
            pilihan: Pilih(hasil, {UuidStruk.kopiLiter: (jumlah, KondisiRetur.layakJual)}),
            alasan: alasan,
            refundTunai: tunai ?? Uang.Dari('66666.67'),
            kasir: rina,
            penyetuju: p ?? budi,
            k: k,
          );

      await expectLater(Simpan('3'), GalatDengan('JumlahReturMelebihi'));
      await expectLater(Simpan('1.5'), GalatDengan('JumlahTidakValid'));
      await expectLater(Simpan('2', alasan: 'rus'), GalatDengan('AlasanDiperlukan'));
      await expectLater(Simpan('2', tunai: Uang.DariBulat(70000)), GalatDengan('RefundTidakSesuai'));
      await expectLater(Simpan('2', tunai: Uang.DariBulat(60000)), GalatDengan('MetodeBayarTidakDikenal'));
      await expectLater(Simpan('2', p: sari), GalatDengan('PenyetujuTidakBerwenang'));
      // K-22: hanya ber-izin void (tanpa penjualan.retur) tidak boleh menyetujui retur.
      final hanyaVoid = StafLokal(
        uuid: budi.uuid,
        nama: budi.nama,
        pemilik: false,
        izin: [
          for (final i in budi.izin)
            if (i != IzinKasir.penjualanRetur) i,
        ],
      );
      await expectLater(Simpan('2', p: hanyaVoid), GalatDengan('PenyetujuTidakBerwenang'));
      await expectLater(
        layananRetur.Simpan(
          hasil: hasil,
          pilihan: const [],
          alasan: 'Kemasan bocor',
          refundTunai: Uang.Nol(),
          kasir: rina,
          penyetuju: budi,
          k: k,
        ),
        GalatDengan('BarisKosong'),
      );
      expect(await u.db.select(u.db.returPenjualan).get(), isEmpty);
      expect(await u.db.select(u.db.nomorUrutReturPenjualan).get(), isEmpty);

      // Sisa penuh → nilai = NilaiBisaDiretur.
      final ok = await Simpan('2');
      expect(ok.totalRefund, Uang.Dari('66666.67'));
      expect(ok.nomor, 'RJ/SLB/260924/POS-001-0001');
    });

    test('nomor RJ dari data-awal: sekuens lokal = max(lokal, server) per tanggal', () async {
      await u.repositori.SimpanDataAwal(
        DataAwal.DariJson(DataAwalUji(nomorUrutRetur: {'260924': 5, '260923': 30, 'bukan-tanggal': 9})),
        u.jam,
      );
      ServerStruk(StrukUji());
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      Future<ReturTersimpan> Simpan() => layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {UuidStruk.airMineral: ('1', KondisiRetur.layakJual)}),
        alasan: 'Salah ambil barang',
        refundTunai: Uang.Nol(),
        kasir: budi,
        k: k,
      );
      expect((await Simpan()).nomor, 'RJ/SLB/260924/POS-001-0006', reason: 'Pasang ulang tidak memakai RJ 0001–0005.');

      // Nilai server lebih kecil tidak menurunkan sekuens lokal.
      await u.repositori.SimpanDataAwal(DataAwal.DariJson(DataAwalUji(nomorUrutRetur: {'260924': 2})), u.jam);
      expect((await Simpan()).nomor, 'RJ/SLB/260924/POS-001-0007');

      final urut = await u.db.select(u.db.nomorUrutReturPenjualan).get();
      expect({for (final n in urut) n.Tanggal: n.Terakhir}, {'260924': 7, '260923': 30});
    });

    test('BolehDesimal dari server menentukan jumlah desimal. Null → heuristik lama', () {
      BarisPenjualanCariPos Baris(String jumlah, {bool? boleh, String? bisa}) => BarisPenjualanCariPos.DariJson(
        BarisStrukUji('01K5BRS0000000000000000009', 'Uji', 'kg', jumlah, '10000.00', bisa: bisa, bolehDesimal: boleh),
      );
      // Jumlah jual bulat, satuan boleh desimal menurut server → boleh.
      expect(LayananReturPenjualan.CekBolehDesimal(Baris('2.0000', boleh: true), katalog), isTrue);
      expect(LayananReturPenjualan.CekBolehDesimal(Baris('2.0000', boleh: false), katalog), isFalse);
      // Sisa pecahan selalu boleh agar bisa diretur habis.
      expect(LayananReturPenjualan.CekBolehDesimal(Baris('2.5000', boleh: false), katalog), isTrue);
      expect(LayananReturPenjualan.CekBolehDesimal(Baris('2.0000', boleh: false, bisa: '0.5000'), katalog), isTrue);
      // Server lama (null) & produk tak dikenal katalog → bulat.
      expect(LayananReturPenjualan.CekBolehDesimal(Baris('2.0000'), katalog), isFalse);
    });

    test('baris obat racikan tidak bisa diretur: difilter dari pilihan, ditolak sebelum outbox', () async {
      ServerStruk(
        StrukUji(
          baris: [
            {
              ...BarisStrukUji(
                UuidStruk.kopiLiter,
                'Puyer batuk pilek anak',
                'bks',
                '10.0000',
                '15000.00',
                bisa: '0.0000',
              ),
              'Racikan': true,
            },
          ],
        ),
      );
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      expect(hasil.baris.single.racikan, isTrue);
      expect(
        () => LayananReturPenjualan.ValidasiPilihan(
          hasil,
          Pilih(hasil, {UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual)}),
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ReturRacikanTidakDidukung')),
      );
      // Server lama tanpa kunci Racikan → false.
      expect(
        BarisPenjualanCariPos.DariJson(BarisStrukUji(UuidStruk.kopiLiter, 'Uji', 'pcs', '1.0000', '1000.00')).racikan,
        isFalse,
      );
    });

    test('BolehDesimal = true dari server: retur 0,5 atas jumlah jual bulat diterima', () async {
      ServerStruk(
        StrukUji(
          baris: [
            BarisStrukUji(
              UuidStruk.bijiKopi,
              'Biji Kopi Arabika Gayo',
              'kg',
              '2.0000',
              '150000.00',
              bolehDesimal: true,
            ),
          ],
        ),
      );
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      final ok = await layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {UuidStruk.bijiKopi: ('0.5', KondisiRetur.rusak)}),
        alasan: 'Kemasan sobek',
        refundTunai: Uang.Dari('37500.00'),
        kasir: rina,
        penyetuju: budi,
        k: k,
      );
      expect(ok.totalRefund, Uang.Dari('37500.00'));
    });

    test('struk tidak bisa diretur (void / lewat batas hari) → pesan jelas', () async {
      ServerStruk(StrukUji(bisaDiretur: false, alasan: 'LewatBatasHari'));
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      expect(
        LayananReturPenjualan.AmbilPesanTidakBisaDiretur(hasil.penjualan),
        'Batas retur 7 hari untuk transaksi ${UuidStruk.nomor} sudah lewat (sampai 27/09/2026).',
      );
      await expectLater(
        layananRetur.Simpan(
          hasil: hasil,
          pilihan: Pilih(hasil, {UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual)}),
          alasan: 'Kemasan bocor',
          refundTunai: Uang.Dari('33333.33'),
          kasir: budi,
          k: k,
        ),
        GalatDengan('ReturTidakDiizinkan'),
      );
    });

    test('offline → "Retur butuh koneksi internet untuk mencari struk."; tidak ditemukan. Struk lokal belum '
        'terkirim. Retur sebelumnya belum terkirim', () async {
      u.server.penangan = (_) async => throw http.ClientException('offline');
      await expectLater(
        layananRetur.Cari(UuidStruk.nomor),
        throwsA(isA<GalatKasir>().having((g) => g.pesan, 'pesan', 'Retur butuh koneksi internet untuk mencari struk.')),
      );

      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'PenjualanTidakDitemukan', 'Pesan': 'Penjualan tidak ditemukan.'},
      }, 404);
      await expectLater(layananRetur.Cari('INV/SLB/260924/POS-001-9999'), GalatDengan('PenjualanTidakDitemukan'));
      final jual = await JualTunai();
      await expectLater(layananRetur.Cari(jual.nomor), GalatDengan('PenjualanBelumTerkirim'));

      ServerStruk(StrukUji());
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      await layananRetur.Simpan(
        hasil: hasil,
        pilihan: Pilih(hasil, {UuidStruk.kopiLiter: ('1', KondisiRetur.layakJual)}),
        alasan: 'Kemasan bocor',
        refundTunai: Uang.Dari('33333.33'),
        kasir: budi,
        k: k,
      );
      await expectLater(layananRetur.Cari(UuidStruk.nomor), GalatDengan('ReturSebelumnyaBelumTerkirim'));
    });

    test(
      'penjualan asal di perangkat ini: status lokal DireturSebagian lalu Diretur. Tidak bisa di-void lagi',
      () async {
        final jual = await JualTunai();
        final detail = await u.repositoriPenjualan.AmbilDetail(jual.uuid);
        Map<String, Object?> Struk(String sudahKopi) =>
            StrukUji(
                baris: [
                  for (final d in detail)
                    BarisStrukUji(
                      d.Uuid,
                      d.NamaProduk,
                      'pcs',
                      d.Jumlah,
                      d.TotalBaris,
                      sudah: d.UuidProduk == UuidUji.kopiSusu ? sudahKopi : '0.0000',
                      bisa: d.UuidProduk == UuidUji.kopiSusu
                          ? Kuantitas.Dari(d.Jumlah).Kurangi(Kuantitas.Dari(sudahKopi)).KeString()
                          : null,
                    ),
                ],
              )
              ..['Penjualan'] = {
                ...(StrukUji()['Penjualan']! as Map<String, Object?>),
                'Uuid': jual.uuid,
                'Nomor': jual.nomor,
              };

        ServerStruk(Struk('0.0000'));
        final hasil = await layananRetur.Cari(jual.nomor);
        final kopi = hasil.baris.firstWhere((b) => b.namaProduk == 'Es Kopi Susu Aren');
        await layananRetur.Simpan(
          hasil: hasil,
          pilihan: [PilihanReturBaris(baris: kopi, jumlah: Kuantitas.Dari('2'))],
          alasan: 'Kopi terlalu manis',
          refundTunai: PenghitungNilaiRetur.Hitung(kopi, Kuantitas.Dari('2')),
          kasir: budi,
          k: k,
        );
        expect((await u.repositoriPenjualan.CariPenjualan(jual.uuid))!.Status, StatusPenjualanLokal.direturSebagian);
        await expectLater(
          layananVoid.Void(uuidPenjualan: jual.uuid, kasir: budi, alasan: 'Salah input'),
          GalatDengan('VoidTidakDiizinkan'),
        );

        // Retur pertama sudah diterima server (outbox terkirim).
        await u.repositori.HapusOutbox([for (final r in await u.db.select(u.db.returPenjualan).get()) r.Uuid]);
        ServerStruk(Struk('2.0000'));
        final lagi = await layananRetur.Cari(jual.nomor);
        await layananRetur.Simpan(
          hasil: lagi,
          pilihan: [
            for (final b in lagi.baris)
              if (!Kuantitas.Dari(b.jumlahBisaDiretur).BernilaiNol())
                PilihanReturBaris(baris: b, jumlah: Kuantitas.Dari(b.jumlahBisaDiretur)),
          ],
          alasan: 'Semua dikembalikan',
          refundTunai: Uang.Nol(),
          metodeTransfer: Metode('Transfer'),
          kasir: budi,
          k: k,
        );
        expect((await u.repositoriPenjualan.CariPenjualan(jual.uuid))!.Status, StatusPenjualanLokal.diretur);
      },
    );
  });

  group('kas shift setelah void & retur (sama dengan server RingkasanPenjualanShift)', () {
    test('tunai masuk bersih tetap menghitung penjualan yang di-void. Refund tunai void & retur mengurangi kas '
        'seharusnya. Laporan X menampilkan jumlah & nominal void/retur', () async {
      final jual = await JualTunai();
      await JualCampuran();
      final awal = await u.tutupShift.SusunLaporan(shift.Uuid);
      // Kas awal 500.000 + tunai bersih (67.100 + 27.100) = 594.200.
      expect(awal.kasSeharusnya, Uang.DariBulat(594200));

      await layananVoid.Void(uuidPenjualan: jual.uuid, kasir: budi, alasan: 'Salah input pesanan');
      ServerStruk(StrukUji());
      final hasil = await layananRetur.Cari(UuidStruk.nomor);
      await layananRetur.Simpan(
        hasil: hasil,
        pilihan: [PilihanReturBaris(baris: hasil.baris.first, jumlah: Kuantitas.Dari('1'))],
        alasan: 'Kemasan bocor',
        refundTunai: Uang.DariBulat(20000),
        metodeTransfer: Metode('Transfer'),
        kasir: budi,
        k: k,
      );

      final laporan = await u.tutupShift.SusunLaporan(shift.Uuid);
      expect(laporan.tunaiMasukBersih, Uang.DariBulat(94200), reason: 'Penjualan yang di-void tetap dihitung.');
      expect(laporan.refundTunai, Uang.DariBulat(87100), reason: 'Void Rp 67.100 + retur tunai Rp 20.000.');
      expect(laporan.kasSeharusnya, Uang.DariBulat(507100));
      expect((laporan.jumlahTransaksi, laporan.totalAkhir), (1, Uang.DariBulat(67100)));
      expect((laporan.jumlahVoid, laporan.nominalVoid), (1, Uang.DariBulat(67100)));
      expect((laporan.jumlahRetur, laporan.nominalRetur), (1, Uang.Dari('33333.33')));
      expect(await u.repositoriPenjualan.PantauRefundTunaiShift(shift.Uuid).first, Uang.DariBulat(87100));

      // Tutup shift memakai kas seharusnya yang sama.
      await u.tutupShift.TutupShift(shift: shift, penutup: rina, kasAktual: Uang.DariBulat(507100));
      final tutup = await DataOutbox('Shift.Tutup');
      expect(tutup['Ringkasan'], {'KasSeharusnya': '507100.00', 'Selisih': '0.00'});
    });
  });
}
