import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/AturanApotek.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/Racikan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunStrukPenjualan.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Apotek bagian 4 (§9.5): racikan disusun di kasir sebagai satu baris jasa racik. Golongan terkuat komponen (tidak
/// pernah OWA) menentukan resep & apoteker, muatan `Baris[].Racikan` persis kontrak server, racikan tersimpan di
/// keranjang (K-4) & penjualan lokal, dan dicetak di struk tanpa komposisi.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  late KatalogLokal katalog;
  late KonteksPenjualan k;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif(dataAwal: DataAwalApotekUji());
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog(KatalogBengkelApotekUji());
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
  });
  tearDown(() => u.Tutup());

  KomponenRacikan Komponen(String uuid, String jumlah) {
    final p = katalog.CariProduk(uuid)!;
    return KomponenRacikan(
      uuidProduk: uuid,
      nama: p.nama,
      uuidProdukSatuan: p.AmbilSatuanBawaan()?.uuid,
      namaSatuan: p.AmbilSatuanBawaan()?.nama,
      jumlah: Kuantitas.Dari(jumlah),
      golonganObat: p.golonganObat,
    );
  }

  RacikanBaris PuyerDemam() => RacikanBaris.Susun(
    nama: '  Puyer demam anak ',
    jumlahKemasan: 10,
    aturanPakai: '3 x 1 bungkus sesudah makan',
    komponen: [Komponen(paracetamol, '2'), Komponen(ctm, '1')],
  );

  test('Susun: nama, kemasan, komponen kosong/ganda/nol ditolak dengan pesan jelas', () {
    String Galat(void Function() kerja) {
      try {
        kerja();
      } on GalatKasir catch (g) {
        return g.pesan;
      }
      return '';
    }

    final para = Komponen(paracetamol, '2');
    expect(Galat(() => RacikanBaris.Susun(nama: ' ', jumlahKemasan: 10, komponen: [para])), 'Isi nama racikan.');
    expect(
      Galat(() => RacikanBaris.Susun(nama: 'Puyer', jumlahKemasan: 0, komponen: [para])),
      contains('1 sampai 999'),
    );
    expect(Galat(() => RacikanBaris.Susun(nama: 'Puyer', jumlahKemasan: 5, komponen: [])), contains('minimal satu'));
    expect(
      Galat(() => RacikanBaris.Susun(nama: 'Puyer', jumlahKemasan: 5, komponen: [para, para])),
      contains('dua kali'),
    );
    expect(
      Galat(() => RacikanBaris.Susun(nama: 'Puyer', jumlahKemasan: 5, komponen: [Komponen(paracetamol, '0')])),
      contains('lebih dari 0'),
    );
    final r = PuyerDemam();
    expect(
      (r.nama, r.aturanPakai, r.Ringkasan),
      ('Puyer demam anak', '3 x 1 bungkus sesudah makan', '10 kemasan | 3 x 1 bungkus sesudah makan'),
    );
  });

  test('golongan racikan = terkuat komponennya dan tidak pernah OWA. Ikut aturan penyerahan obat keranjang', () {
    expect(PuyerDemam().Obat?.golongan, GolonganObat.BebasTerbatas);
    expect(PuyerDemam().Obat?.CekWajibResep, isFalse);
    // Asam mefenamat OWA dijual satuan tanpa resep, tetapi di dalam racikan tetap wajib resep & apoteker.
    final keras = RacikanBaris.Susun(
      nama: 'Puyer nyeri',
      jumlahKemasan: 6,
      komponen: [Komponen(paracetamol, '1'), Komponen(asamMefenamat, '1')],
    );
    expect(
      (keras.Obat?.golongan, keras.Obat?.CekWajibResep, keras.Obat?.CekWajibApoteker),
      (GolonganObat.Keras, true, true),
    );
    final jasa = katalog.CariProduk(jasaServisMotor)!;
    final baris = u.penjualan.BuatBarisRacikan(katalog, k, jasa, keras, Uang.DariBulat(30000));
    final syarat = AturanApotek.Periksa(Keranjang(baris: [baris]), katalog);
    expect(syarat.barisWajibResep.single.uuid, baris.uuid);
    expect(syarat.barisWajibApoteker.single.uuid, baris.uuid);
    expect(
      RacikanBaris(nama: 'Bedak', jumlahKemasan: 1, komponen: [Komponen(oliMesin, '1')]).Obat,
      isNull,
      reason: 'Komponen bukan obat tidak memberi golongan.',
    );
  });

  test(
    'harga saran = jasa racik + harga obat × jumlah. Hanya jasa yang bisa membawa racikan. Bahan tanpa nomor seri',
    () {
      final jasa = katalog.CariProduk(jasaServisMotor)!;
      final saran = u.penjualan.HitungHargaSaranRacikan(katalog, k, jasa, PuyerDemam().komponen);
      // Jasa harga terbuka tanpa harga daftar = 0; paracetamol 2 × 5.000 + CTM 1 × 3.500.
      expect(saran.harga, Uang.DariBulat(13500));
      expect(saran.tanpaHarga, isEmpty);
      expect(
        () =>
            u.penjualan.BuatBarisRacikan(katalog, k, katalog.CariProduk(paracetamol)!, PuyerDemam(), Uang.DariBulat(1)),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'RacikanBukanJasa')),
      );
      final bahan = LayananPenjualan.AmbilBahanRacikan(katalog).map((p) => p.uuid);
      expect(bahan, containsAll([paracetamol, ctm, amoxicillin]));
      expect(bahan, isNot(contains(jasaServisMotor)));
      expect(LayananPenjualan.AmbilJasaRacik(katalog).map((p) => p.uuid), contains(jasaServisMotor));
    },
  );

  test(
    'bayar: muatan Baris[].Racikan persis kontrak, keranjang tersimpan membawa racikan, struk mencetak nama & signa',
    () async {
      final jasa = katalog.CariProduk(jasaServisMotor)!;
      final baris = u.penjualan.BuatBarisRacikan(katalog, k, jasa, PuyerDemam(), Uang.DariBulat(25000));
      final keranjang = u.penjualan.TambahBaris(Keranjang(), baris, katalog, k);
      expect(keranjang.baris.single.CekBisaDigabung(keranjang.baris.single), isFalse);
      final tersimpan = Keranjang.DariJson(jsonDecode(jsonEncode(keranjang.KeJson())) as Map<String, Object?>);
      expect(tersimpan.baris.single.racikan?.komponen.map((c) => c.jumlah.KeString()), ['2.0000', '1.0000']);

      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir)],
        kasir: rina,
        k: k,
        katalog: katalog,
      );
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      final barisMuatan = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>().single;
      expect(barisMuatan['UuidProduk'], jasaServisMotor);
      expect(barisMuatan['HargaSatuan'], '25000.00');
      expect(barisMuatan['Racikan'], {
        'Nama': 'Puyer demam anak',
        'JumlahKemasan': 10,
        'AturanPakai': '3 x 1 bungkus sesudah makan',
        'Komponen': [
          {'UuidProduk': paracetamol, 'UuidProdukSatuan': psParacetamol, 'Jumlah': '2.0000'},
          {'UuidProduk': ctm, 'UuidProdukSatuan': psCtm, 'Jumlah': '1.0000'},
        ],
      });

      final detail = await u.repositoriPenjualan.AmbilDetail(hasil.uuid);
      expect(RacikanBaris.DariJson(jsonDecode(detail.single.Racikan!))?.nama, 'Puyer demam anak');
      final struk = TataLetakStruk.KeTeks(
        PenyusunStrukPenjualan.Susun(
          await IdentitasStruk.Muat(u.repositori),
          DataStrukPenjualan(
            penjualan: (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!,
            detail: detail,
            pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
          ),
        ),
        LebarKertas.Mm80,
      ).map((b) => b.trim()).toList();
      expect(struk, containsAll(['Racikan: Puyer demam anak', '10 kemasan | 3 x 1 bungkus sesudah makan']));
      expect(struk.any((b) => b.contains('Paracetamol')), isFalse, reason: 'Komposisi tidak dicetak di struk.');
    },
  );
}
