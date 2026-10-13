import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/AturanApotek.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPerintahKerja.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunStrukPenjualan.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Bengkel bagian 2 (§9.10, K-27) & Apotek bagian 2 (§9.5, K-26) di perangkat kasir: perintah kerja dimuat ke keranjang
/// dengan harga & diskon yang disepakati, mekanik → staf baris, `UuidPerintahKerja` di `Penjualan.Buat`; aturan
/// penyerahan obat (resep dokter, apoteker, alamat psikotropika/narkotika) memblokir pembayaran sebelum dikirim, dan
/// muatan `Resep`/`Baris[].DenganResep`/`UuidApoteker` persis kontrak server. Barang bukan obat tidak pernah diblokir.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  late KatalogLokal katalog;
  late KonteksPenjualan k;

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

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

  Future<Map<String, Object?>> OutboxTerakhir() async {
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    return jsonDecode(outbox.last.Data) as Map<String, Object?>;
  }

  Future<List<String>> Struk(String uuid) async {
    final jual = (await u.repositoriPenjualan.CariPenjualan(uuid))!;
    return TataLetakStruk.KeTeks(
      PenyusunStrukPenjualan.Susun(
        await IdentitasStruk.Muat(u.repositori),
        DataStrukPenjualan(
          penjualan: jual,
          detail: await u.repositoriPenjualan.AmbilDetail(uuid),
          pembayaran: await u.repositoriPenjualan.AmbilPembayaran(uuid),
        ),
      ),
      LebarKertas.Mm80,
    ).map((b) => b.trim()).toList();
  }

  Future<PenjualanTersimpan> BayarTunai(
    Keranjang keranjang, {
    StafLokal? kasir,
    ResepPenjualan? resep,
    String? uuidApoteker,
  }) {
    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    return u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: u.penjualan.Hitung(keranjang, k).hasil.totalAkhir)],
      kasir: kasir ?? rina,
      k: k,
      katalog: katalog,
      resep: resep,
      uuidApoteker: uuidApoteker,
    );
  }

  Keranjang KeranjangDari(List<String> produk) {
    var keranjang = Keranjang();
    for (final uuid in produk) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(uuid)!),
        katalog,
        k,
      );
    }
    return keranjang;
  }

  const resepUji = ResepPenjualan(
    nomorResep: 'RX/2026/09/0012',
    tanggalResep: '2026-09-23',
    namaDokter: 'Andi Wijaya, Sp.PD',
    noSipDokter: '503/SIP-DR/2024',
    namaPasien: 'Siti Rahmawati',
    umurPasien: '42 tahun',
    alamatPasien: 'Jl. Slamet Riyadi No. 45, Laweyan, Surakarta',
  );

  group('Bengkel bagian 2: perintah kerja ke keranjang', () {
    test('offline ditolak PerluOnline; daftar siap tagih & satu perintah kerja lewat jalur POS', () async {
      u.server.penangan = (p) async => throw http.ClientException('offline');
      await expectLater(
        u.perintahKerja.Ambil(),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );

      u.server.penangan = (p) async => p.url.path.endsWith('/perintah-kerja')
          ? Json({
              'PerintahKerja': [PerintahKerjaUji()],
            }, 200)
          : Json({'PerintahKerja': PerintahKerjaUji()}, 200);
      final daftar = await u.perintahKerja.Ambil();
      expect(u.server.permintaan.last.url.queryParameters, {'status': 'siap-tagih'});
      expect(daftar.single.nomorPolisi, 'AD 1234 XY');
      await u.perintahKerja.Ambil(semuaAktif: true);
      expect(u.server.permintaan.last.url.queryParameters, {'status': 'aktif'});
      final satu = await u.perintahKerja.AmbilSatu(daftar.single.uuid);
      expect(u.server.permintaan.last.url.path, endsWith('/perintah-kerja/01K6PK000000000000000000A1'));
      expect(satu.baris, hasLength(2));
    });

    test(
      'harga & diskon perintah kerja dipakai, mekanik jadi staf baris, pelanggan terpasang; keranjang tersimpan (K-4) '
      'membawa tautannya; bayar mengirim UuidPerintahKerja; struk mencetak nomor & nomor polisi',
      () async {
        final keranjang = u.perintahKerja.MuatKeKeranjang(PerintahKerjaPos.DariJson(PerintahKerjaUji()), katalog, k);
        final servis = keranjang.baris.first;
        final oli = keranjang.baris.last;
        // Jasa harga terbuka tanpa harga daftar tetap bisa ditagih dengan harga perintah kerja.
        expect((servis.uuidProduk, servis.hargaSatuan, servis.diskon), (jasaServisMotor, Uang.DariBulat(50000), null));
        expect(servis.staf, ['01K6KRY00000000000000MKN01']);
        // Oli: harga daftar Rp 60.000, perintah kerja menyepakati Rp 55.000 diskon Rp 5.000; tanpa mekanik.
        expect(
          (oli.hargaSatuan, oli.diskon?.jumlah, oli.uuidProdukSatuan),
          (Uang.DariBulat(55000), Uang.DariBulat(5000), psOliMesin),
        );
        expect(oli.staf, isEmpty);
        expect(keranjang.pelanggan?.nama, 'Bambang Sutrisno');
        expect(
          (keranjang.perintahKerja?.nomor, keranjang.perintahKerja?.nomorPolisi),
          ('WO/SLB/2610/0007', 'AD 1234 XY'),
        );
        final tersimpan = Keranjang.DariJson(jsonDecode(jsonEncode(keranjang.KeJson())) as Map<String, Object?>);
        expect(
          (tersimpan.perintahKerja?.uuid, tersimpan.perintahKerja?.nomorPolisi),
          ('01K6PK000000000000000000A1', 'AD 1234 XY'),
        );
        expect(tersimpan.baris.first.staf, ['01K6KRY00000000000000MKN01']);
        expect(LayananPenjualan.AmbilNomorAntrian('INV/SLB/260924/POS-001-0001', keranjang, k), isNull);

        final hasil = await BayarTunai(keranjang);
        final data = await OutboxTerakhir();
        expect(data['UuidPerintahKerja'], '01K6PK000000000000000000A1');
        expect(data['UuidPelanggan'], '01K5PELANGGAN0000000000009');
        final baris = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(baris.first['Staf'], ['01K6KRY00000000000000MKN01']);
        expect(baris.first['HargaSatuan'], '50000.00');
        expect(baris.last.containsKey('Staf'), isFalse);
        expect(baris.last['HargaSatuan'], '55000.00');
        expect(baris.last['DiskonManual'], {'Jumlah': '5000.00'});
        expect(data.containsKey('Resep'), isFalse);
        expect(data.containsKey('UuidApoteker'), isFalse);
        expect(baris.any((b) => b.containsKey('DenganResep')), isFalse);

        final jual = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
        expect(jsonDecode(jual.PerintahKerja!), {
          'Uuid': '01K6PK000000000000000000A1',
          'Nomor': 'WO/SLB/2610/0007',
          'NomorPolisi': 'AD 1234 XY',
        });
        final struk = await Struk(hasil.uuid);
        expect(struk, containsAll(['Perintah kerja WO/SLB/2610/0007', 'Kendaraan AD 1234 XY']));
      },
    );

    test('servis elektronik (D-142): unit Perangkat jadi judul keranjang (merek & tipe), tersimpan di K-4, dan struk '
        'mencetak "Perangkat ..." bukan "Kendaraan ..."', () async {
      final wo = PerintahKerjaUji();
      wo['Kendaraan'] = {
        'Uuid': '01K6UNIT0000000000000000P1',
        'Jenis': 'Perangkat',
        'NomorPolisi': null,
        'Label': 'Samsung Galaxy A54',
      };
      final keranjang = u.perintahKerja.MuatKeKeranjang(PerintahKerjaPos.DariJson(wo), katalog, k);
      expect(
        (keranjang.perintahKerja?.jenisUnit, keranjang.perintahKerja?.pengenalUnit),
        ('Perangkat', 'Samsung Galaxy A54'),
      );
      final tersimpan = Keranjang.DariJson(jsonDecode(jsonEncode(keranjang.KeJson())) as Map<String, Object?>);
      expect(
        (tersimpan.perintahKerja?.jenisUnit, tersimpan.perintahKerja?.pengenalUnit),
        ('Perangkat', 'Samsung Galaxy A54'),
      );

      final hasil = await BayarTunai(keranjang);
      final jual = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
      expect((jsonDecode(jual.PerintahKerja!) as Map<String, Object?>)['LabelUnit'], 'Samsung Galaxy A54');
      final struk = await Struk(hasil.uuid);
      expect(struk, contains('Perangkat Samsung Galaxy A54'));
      expect(struk.any((baris) => baris.startsWith('Kendaraan')), isFalse);
    });

    test(
      'bagian 3: nomor seri sparepart dari perintah kerja ikut baris & Penjualan.Buat; kosong = kasir mengisi dulu',
      () async {
        // Katalog bengkel + satu sparepart bernomor seri (pakai produk seri uji: ponsel).
        final dasar = KatalogBengkelApotekUji();
        final seri = KatalogPonselUji();
        for (final kunci in ['Produk', 'ProdukSatuan', 'ProdukHarga']) {
          final ada = {for (final x in (dasar[kunci]! as List<Object?>)) (x! as Map<String, Object?>)['Uuid']};
          dasar[kunci] = [
            ...(dasar[kunci]! as List<Object?>),
            ...(seri[kunci]! as List<Object?>).where((x) => !ada.contains((x! as Map<String, Object?>)['Uuid'])),
          ];
        }
        await u.SiapkanKatalog(dasar);
        katalog = await u.MuatKatalog();
        k = await u.MuatKonteks();

        Map<String, Object?> Wo(List<String>? nomorSeri) {
          final pk = PerintahKerjaUji();
          pk['Baris'] = [
            ...(pk['Baris']! as List<Object?>),
            {
              'Uuid': '01K6PKD0000000000000000003',
              'Jenis': 'Sparepart',
              'UuidProduk': ponsel,
              'UuidProdukSatuan': psPonsel,
              'NamaProduk': 'Ponsel Android 8/256 GB Hitam',
              'Jumlah': '2.0000',
              'HargaSatuan': '6500000.00',
              'Diskon': '0.00',
              'UuidKaryawan': null,
              'NamaKaryawan': null,
              'Catatan': null,
              'NomorSeri': ?nomorSeri,
            },
          ];
          return pk;
        }

        // Server lama (tanpa NomorSeri) & WO tanpa nomor: baris tetap 2 unit, Bayar menolak sampai kasir mengisi.
        final kosong = u.perintahKerja.MuatKeKeranjang(PerintahKerjaPos.DariJson(Wo(null)), katalog, k);
        expect(kosong.baris.last.jumlah, Kuantitas.DariBulat(2));
        expect(kosong.baris.last.nomorSeri, isEmpty);
        await expectLater(BayarTunai(kosong), throwsA(isA<GalatKasir>()));

        final keranjang = u.perintahKerja.MuatKeKeranjang(
          PerintahKerjaPos.DariJson(Wo(['IMEI-3561001', 'IMEI-3561002'])),
          katalog,
          k,
        );
        expect(keranjang.baris.last.nomorSeri, ['IMEI-3561001', 'IMEI-3561002']);
        expect(keranjang.baris.first.nomorSeri, isEmpty);
        await BayarTunai(keranjang);
        final baris = ((await OutboxTerakhir())['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(baris.last['NomorSeri'], ['IMEI-3561001', 'IMEI-3561002']);
        expect(baris.last['Jumlah'], '2.0000');
        expect(baris.first.containsKey('NomorSeri'), isFalse);
      },
    );

    test('perintah kerja belum siap, produk belum di katalog, dan kasir tanpa izin berjualan ditolak', () async {
      expect(
        () => u.perintahKerja.MuatKeKeranjang(
          PerintahKerjaPos.DariJson(PerintahKerjaUji(siapTagih: false, status: 'Dikerjakan')),
          katalog,
          k,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerintahKerjaBelumSiap')),
      );
      final asli = PerintahKerjaUji();
      final barisAsli = (asli['Baris']! as List<Object?>).cast<Map<String, Object?>>();
      final asing = <String, Object?>{
        ...asli,
        'Baris': [
          barisAsli.first,
          <String, Object?>{
            ...barisAsli.last,
            'UuidProduk': '01K6TIDAKADA00000000000001',
            'NamaProduk': 'Kampas rem belakang',
          },
        ],
      };
      expect(
        () => u.perintahKerja.MuatKeKeranjang(PerintahKerjaPos.DariJson(asing), katalog, k),
        throwsA(
          isA<GalatKasir>()
              .having((g) => g.kode, 'kode', 'ProdukTidakDikenal')
              .having((g) => g.pesan, 'pesan', contains('Kampas rem belakang')),
        ),
      );
      const tamu = StafLokal(uuid: '01K5TAMU000000000000000001', nama: 'Dodi', pemilik: false, izin: []);
      expect(
        () => LayananPerintahKerja.PeriksaIzin(tamu),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TanpaIzin')),
      );
    });

    test('mode latihan menolak keranjang perintah kerja (tidak meninggalkan jejak di data nyata)', () async {
      final keranjang = u.perintahKerja.MuatKeKeranjang(PerintahKerjaPos.DariJson(PerintahKerjaUji()), katalog, k);
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      await expectLater(
        u.penjualan.Bayar(
          keranjang: keranjang,
          pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(200000))],
          kasir: rina,
          k: k,
          katalog: katalog,
          latihan: true,
        ),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'TidakUntukLatihan')),
      );
    });
  });

  group('Apotek bagian 2: aturan penyerahan obat', () {
    test('evaluator: wajib resep (keras bukan OWA, psikotropika), apoteker (keras+OWA, psikotropika), alamat', () {
      final keranjang = KeranjangDari([amoxicillin, asamMefenamat, diazepam, paracetamol, ctm, UuidUji.croissant]);
      final syarat = AturanApotek.Periksa(keranjang, katalog);
      expect(syarat.barisWajibResep.map((b) => b.uuidProduk), [amoxicillin, diazepam]);
      expect(syarat.barisWajibApoteker.map((b) => b.uuidProduk), [amoxicillin, asamMefenamat, diazepam]);
      expect(syarat.wajibAlamat, isTrue);

      final tanpaPsiko = AturanApotek.Periksa(KeranjangDari([amoxicillin, asamMefenamat]), katalog);
      expect((tanpaPsiko.CekWajibResep, tanpaPsiko.CekWajibApoteker, tanpaPsiko.wajibAlamat), (true, true, false));
      final owaSaja = AturanApotek.Periksa(KeranjangDari([asamMefenamat]), katalog);
      expect((owaSaja.CekWajibResep, owaSaja.CekWajibApoteker), (false, true), reason: 'OWA tanpa resep, apoteker');
      expect(AturanApotek.Periksa(KeranjangDari([paracetamol, ctm, UuidUji.croissant]), katalog).CekKosong, isTrue);
      expect(AturanApotek.Periksa(KeranjangDari([amoxicillin]), null).CekKosong, isTrue, reason: 'Tanpa katalog');

      String Kode(String uuid) => InfoObat.Dari(katalog.CariProduk(uuid)!)!.Kode;
      expect([amoxicillin, asamMefenamat, diazepam, paracetamol, ctm].map(Kode), [
        'K',
        'K | OWA',
        'P',
        'Bebas',
        'B. terbatas',
      ]);
      expect(InfoObat.Dari(katalog.CariProduk(amoxicillin)!)!.Label, 'Obat keras, wajib resep');
      expect(InfoObat.Dari(katalog.CariProduk(UuidUji.croissant)!), isNull);
      // Server lama tanpa bendera WajibResep: tetap dihitung dari golongan.
      expect(const InfoObat(golongan: GolonganObat.Narkotika).CekWajibResep, isTrue);
      expect(const InfoObat(golongan: GolonganObat.Keras, obatWajibApotek: true).CekWajibResep, isFalse);
    });

    test('isian resep: wajib & panjang sesuai server, tanggal tidak setelah hari ini, alamat untuk psikotropika', () {
      ({ResepPenjualan? resep, String? galat}) Susun({String tanggal = '2026-09-24', bool alamat = false}) =>
          AturanApotek.Susun(
            nomorResep: ' RX/2026/09/0012 ',
            tanggalResep: tanggal,
            namaDokter: 'Andi Wijaya',
            namaPasien: 'Siti Rahmawati',
            hariIni: '2026-09-24',
            wajibAlamat: alamat,
          );
      final ok = Susun();
      expect(ok.galat, isNull);
      expect(ok.resep!.KeJson(), {
        'NomorResep': 'RX/2026/09/0012',
        'TanggalResep': '2026-09-24',
        'NamaDokter': 'Andi Wijaya',
        'NamaPasien': 'Siti Rahmawati',
      });
      expect(Susun(tanggal: '2026-09-25').galat, 'Tanggal resep tidak boleh setelah hari ini.');
      expect(Susun(alamat: true).galat, 'Psikotropika/narkotika wajib mencatat alamat pasien.');
      expect(
        AturanApotek.Susun(
          nomorResep: 'X' * 51,
          tanggalResep: '2026-09-24',
          namaDokter: 'Andi',
          namaPasien: 'Siti',
          hariIni: '2026-09-24',
          wajibAlamat: false,
        ).galat,
        contains('50 karakter'),
      );
      expect(
        AturanApotek.Susun(
          nomorResep: 'RX-1',
          tanggalResep: '2026-09-24',
          namaDokter: ' ',
          namaPasien: 'Siti',
          hariIni: '2026-09-24',
          wajibAlamat: false,
        ).galat,
        'Isi nama dokter penulis resep.',
      );
      expect(AturanApotek.SusunBarisStruk('RX-1', 'Andi Wijaya'), 'Resep: no. RX-1, dr. Andi Wijaya');
      expect(AturanApotek.SusunBarisStruk('RX-1', 'dr. Andi Wijaya'), 'Resep: no. RX-1, dr. Andi Wijaya');
      // Tanggal kalender outlet (WIB), bukan zona perangkat: 24 Sep 18.30 UTC = 25 Sep 01.30 WIB.
      expect(AturanApotek.HitungHariIni(DateTime.utc(2026, 9, 24, 18, 30), 'Asia/Jakarta'), '2026-09-25');
    });

    test(
      'pembayaran diblokir tanpa resep, lalu tanpa apoteker; resep + PIN apoteker → muatan persis kontrak',
      () async {
        final keranjang = KeranjangDari([amoxicillin, asamMefenamat, diazepam, paracetamol]);
        await expectLater(
          BayarTunai(keranjang),
          throwsA(
            isA<GalatKasir>()
                .having((g) => g.kode, 'kode', 'ResepWajib')
                .having((g) => g.pesan, 'pesan', contains('Amoxicillin')),
          ),
        );
        const tanpaAlamat = ResepPenjualan(
          nomorResep: 'RX/2026/09/0012',
          tanggalResep: '2026-09-23',
          namaDokter: 'Andi Wijaya',
          namaPasien: 'Siti Rahmawati',
        );
        await expectLater(
          BayarTunai(keranjang, resep: tanpaAlamat),
          throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ResepTidakLengkap')),
        );
        await expectLater(
          BayarTunai(keranjang, resep: resepUji),
          throwsA(
            isA<GalatKasir>()
                .having((g) => g.kode, 'kode', 'ApotekerWajib')
                .having((g) => g.pesan, 'pesan', contains('Asam Mefenamat')),
          ),
        );
        expect(
          (await u.db.select(u.db.outbox).get()).where((o) => o.Jenis == 'Penjualan.Buat'),
          isEmpty,
          reason: 'Tidak ada yang tersimpan saat diblokir.',
        );

        final apoteker = await u.Staf('apt. Dewi Anggraini');
        final hasil = await BayarTunai(keranjang, resep: resepUji, uuidApoteker: apoteker.uuid);
        final data = await OutboxTerakhir();
        expect(data['Resep'], {
          'NomorResep': 'RX/2026/09/0012',
          'TanggalResep': '2026-09-23',
          'NamaDokter': 'Andi Wijaya, Sp.PD',
          'NoSipDokter': '503/SIP-DR/2024',
          'NamaPasien': 'Siti Rahmawati',
          'UmurPasien': '42 tahun',
          'AlamatPasien': 'Jl. Slamet Riyadi No. 45, Laweyan, Surakarta',
        });
        expect(data['UuidApoteker'], '01K6STAF0000000000APOTEK01');
        final baris = (data['Baris']! as List<Object?>).cast<Map<String, Object?>>();
        expect(
          {for (final b in baris) b['UuidProduk']: b['DenganResep']},
          {amoxicillin: true, asamMefenamat: null, diazepam: true, paracetamol: null},
          reason: 'Hanya baris wajib resep yang ditandai; OWA & obat bebas tidak.',
        );
        expect(baris.where((b) => b.containsKey('DenganResep')), hasLength(2));

        // Penjualan lokal & struk: nomor resep + dokter, tanpa data pasien.
        final jual = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
        expect(jsonDecode(jual.Resep!), {
          'NomorResep': 'RX/2026/09/0012',
          'TanggalResep': '2026-09-23',
          'NamaDokter': 'Andi Wijaya, Sp.PD',
        });
        final struk = (await Struk(hasil.uuid)).join(' ');
        expect(struk, contains('Resep: no. RX/2026/09/0012, dr. Andi Wijaya, Sp.PD'));
        expect(struk, isNot(contains('Siti')));
        expect(struk, isNot(contains('Slamet Riyadi')));
      },
    );

    test('kasir ber-izin apoteker cukup dirinya (UuidApoteker tidak dikirim); OWA tanpa resep', () async {
      final apoteker = await u.Staf('apt. Dewi Anggraini');
      await BayarTunai(KeranjangDari([asamMefenamat]), kasir: apoteker);
      final data = await OutboxTerakhir();
      expect(data.containsKey('Resep'), isFalse);
      expect(data.containsKey('UuidApoteker'), isFalse);
      expect((data['Baris']! as List<Object?>).cast<Map<String, Object?>>().single.containsKey('DenganResep'), isFalse);
    });

    test('barang bukan obat & obat bebas tidak pernah diblokir; muatan tidak berubah', () async {
      await BayarTunai(KeranjangDari([UuidUji.croissant, paracetamol, ctm]));
      final data = await OutboxTerakhir();
      expect(data.keys, isNot(containsAll(['Resep', 'UuidApoteker', 'UuidPerintahKerja'])));
      expect(data.containsKey('Resep') || data.containsKey('UuidApoteker'), isFalse);
      expect(
        (data['Baris']! as List<Object?>).cast<Map<String, Object?>>().any((b) => b.containsKey('DenganResep')),
        isFalse,
      );
    });
  });
}
