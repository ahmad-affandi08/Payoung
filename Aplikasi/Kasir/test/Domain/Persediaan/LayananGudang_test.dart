import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Persediaan/LayananGudang.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';

import '../../Pendukung/GudangUji.dart';
import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// POS-25 modul Gudang di perangkat: izin per pekerjaan, draf lokal tahan tutup-aplikasi, validasi isian (sisa, batch,
/// nomor seri, satuan bulat), bentuk permintaan ke `/api/pos/v1/gudang/*`, dan kunci idempotensi yang sama saat kirim
/// ulang setelah koneksi putus.
void main() {
  late LingkunganUji u;
  late StafLokal budi;
  late StafLokal rina;

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    await u.SiapkanKatalog();
    budi = await u.Staf('Budi Santoso');
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  PesananGudangPos Pesanan() => PesananGudangPos.DariJson(PesananGudangUji());

  test('izin: terima barang boleh pembelian.kelola atau persediaan.kelola. Transfer & opname persediaan.kelola', () {
    const pembelian = StafLokal(uuid: 'S1', nama: 'Dewi', pemilik: false, izin: ['pembelian.kelola']);
    for (final j in JenisGudang.values) {
      expect(LayananGudang.CekBoleh(budi, j), isTrue, reason: j.name);
      expect(LayananGudang.CekBoleh(rina, j), isFalse, reason: j.name);
    }
    expect(LayananGudang.CekBoleh(pembelian, JenisGudang.Penerimaan), isTrue);
    expect(LayananGudang.CekBoleh(pembelian, JenisGudang.Opname), isFalse);
  });

  test(
    'draf tersimpan lokal lalu dimuat lagi utuh (kunci idempotensi tetap). Hapus = draf baru berkunci baru',
    () async {
      final draf = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po);
      draf.jumlah[1] = '2';
      draf.pelacakan[2] = PelacakanDraf(nomorBatch: 'UHT-2610B', tanggalKedaluwarsa: '2027-04-30');
      draf.nomorSuratJalan = 'SJ/SPN/0142';
      await u.gudang.SimpanDraf(draf);

      final lagi = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po);
      expect(lagi.kunciIdempotensi, draf.kunciIdempotensi);
      expect(lagi.jumlah, {1: '2'});
      expect(lagi.pelacakan[2]!.nomorBatch, 'UHT-2610B');
      expect(lagi.nomorSuratJalan, 'SJ/SPN/0142');
      // Dokumen lain tidak ikut.
      expect((await u.gudang.MuatDraf(JenisGudang.Transfer, UuidGudangUji.po)).kosong, isTrue);

      await u.gudang.HapusDraf(lagi);
      final baru = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po);
      expect(baru.kosong, isTrue);
      expect(baru.kunciIdempotensi, isNot(draf.kunciIdempotensi));
    },
  );

  test('jumlah: koma desimal, tambah/kurang tidak di bawah 0, satuan bulat menolak desimal', () {
    expect(LayananGudang.BacaJumlah('1,5', bolehDesimal: true)?.KeString(), '1.5000');
    expect(LayananGudang.BacaJumlah('', bolehDesimal: true), isNull);
    expect(LayananGudang.BacaJumlah('0', bolehDesimal: true), isNull);
    expect(LayananGudang.BacaJumlah('0', bolehDesimal: false, nolBoleh: true)?.KeString(), '0.0000');
    expect(() => LayananGudang.BacaJumlah('1,5', bolehDesimal: false), throwsA(isA<GalatKasir>()));
    expect(() => LayananGudang.BacaJumlah('satu', bolehDesimal: true), throwsA(isA<GalatKasir>()));
    expect(LayananGudang.TambahJumlah('2,5'), '3.5');
    expect(LayananGudang.TambahJumlah('', langkah: -1), '0');
    expect(LayananGudang.FormatJumlah('24.0000'), '24');
  });

  test(
    'terima barang: validasi sisa, batch wajib, tanggal, nomor seri, tanpa izin ditolak sebelum ke server',
    () async {
      Future<String> Coba(void Function(DrafGudang d) isi, {StafLokal? staf, PesananGudangPos? pesanan}) async {
        final draf = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po);
        isi(draf);
        try {
          await u.gudang.KirimPenerimaan(pesanan ?? Pesanan(), draf, staf ?? budi);
          return 'Terkirim';
        } on GalatKasir catch (galat) {
          return galat.kode;
        }
      }

      expect(await Coba((d) {}), 'TidakAdaJumlah');
      expect(await Coba((d) => d.jumlah[3] = '5'), 'MelebihiSisa');
      expect(await Coba((d) => d.jumlah[2] = '24'), 'NomorBatchWajib');
      expect(
        await Coba(
          (d) => d
            ..jumlah[2] = '24'
            ..pelacakan[2] = PelacakanDraf(nomorBatch: 'UHT-2610B', tanggalKedaluwarsa: '30-04-2027'),
        ),
        'TanggalTidakValid',
      );
      expect(await Coba((d) => d.jumlah[1] = '1', staf: rina), 'TanpaIzin');

      // Baris ber-nomor seri: 5 nomor seri untuk satuan isi 12 tidak pas (0,41666… tidak muat 4 desimal).
      final seri = PesananGudangPos.DariJson({
        ...PesananGudangUji(),
        'Baris': [
          {
            ...(PesananGudangUji()['Baris']! as List<Object?>).first! as Map<String, Object?>,
            'Pelacakan': 'Seri',
            'Konversi': '12.0000',
          },
        ],
      });
      expect(
        await Coba(
          (d) => d.pelacakan[1] = PelacakanDraf(nomorSeri: ['SN-1', 'SN-2', 'SN-3', 'SN-4', 'SN-5']),
          pesanan: seri,
        ),
        'NomorSeriKurang',
      );
      expect(u.server.permintaan, isEmpty, reason: 'Semua galat di atas ditolak di perangkat.');
    },
  );

  test(
    'terima barang: bentuk permintaan, satuan PO apa adanya, surat jalan. Koneksi putus → kunci sama saat coba lagi',
    () async {
      final draf = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po);
      draf
        ..jumlah[1] = '1,5'
        ..jumlah[2] = '24'
        ..pelacakan[2] = PelacakanDraf(nomorBatch: ' UHT-2610B ', tanggalKedaluwarsa: '2027-04-30')
        ..nomorSuratJalan = 'SJ/SPN/0142';
      await u.gudang.SimpanDraf(draf);

      u.server.penangan = (_) async => throw http.ClientException('putus');
      await expectLater(
        u.gudang.KirimPenerimaan(Pesanan(), draf, budi),
        throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
      );
      // Draf tidak hilang saat gagal.
      expect((await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po)).jumlah, {1: '1,5', 2: '24'});

      u.server.penangan = (_) async => JsonUji({
        'Penerimaan': {'Uuid': '01K5GR00000000000000000001', 'Nomor': 'GR/SLB/2609/0012'},
        'Pesanan': PesananGudangUji(sisaRoti: '0.5000'),
      }, 201);
      final hasil = await u.gudang.KirimPenerimaan(Pesanan(), draf, budi);

      expect(hasil.nomor, 'GR/SLB/2609/0012');
      expect(hasil.pesanan!.baris.first.sisa, '0.5000');
      final kirim = u.server.permintaan.where((p) => p.url.path.endsWith('/gudang/penerimaan')).toList();
      expect(kirim, hasLength(2));
      expect(kirim.map((p) => p.headers['Idempotency-Key']).toSet(), {draf.kunciIdempotensi});
      expect(jsonDecode(kirim.last.body), {
        'UuidPesananPembelian': UuidGudangUji.po,
        'UuidPengguna': budi.uuid,
        'NomorSuratJalan': 'SJ/SPN/0142',
        'Baris': [
          {'Urutan': 1, 'Jumlah': '1.5000'},
          {'Urutan': 2, 'Jumlah': '24.0000', 'NomorBatch': 'UHT-2610B', 'TanggalKedaluwarsa': '2027-04-30'},
        ],
      });
      // Berhasil → draf dihapus.
      expect((await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po)).kosong, isTrue);
    },
  );

  test('galat server diteruskan dengan pesannya (mis. PO sudah diterima perangkat lain)', () async {
    final draf = await u.gudang.MuatDraf(JenisGudang.Penerimaan, UuidGudangUji.po)
      ..jumlah[3] = '4';
    u.server.penangan = (_) async => JsonUji({
      'Galat': {'Kode': 'MelebihiPesanan', 'Pesan': 'Jumlah diterima melebihi sisa pesanan.'},
    }, 422);
    await expectLater(
      u.gudang.KirimPenerimaan(Pesanan(), draf, budi),
      throwsA(
        isA<GalatKasir>()
            .having((g) => g.kode, 'kode', 'MelebihiPesanan')
            .having((g) => g.pesan, 'pesan', 'Jumlah diterima melebihi sisa pesanan.'),
      ),
    );
  });

  test('transfer masuk: melebihi sisa & desimal di satuan bulat ditolak. Kirim Urutan + Jumlah satuan dasar', () async {
    final transfer = TransferGudangPos.DariJson(TransferGudangUji());
    final draf = await u.gudang.MuatDraf(JenisGudang.Transfer, UuidGudangUji.transfer);

    draf.jumlah[1] = '31';
    await expectLater(u.gudang.KirimTerimaTransfer(transfer, draf, budi), throwsA(isA<GalatKasir>()));
    draf.jumlah[1] = '2,5';
    await expectLater(u.gudang.KirimTerimaTransfer(transfer, draf, budi), throwsA(isA<GalatKasir>()));

    draf.jumlah[1] = '30';
    u.server.penangan = (_) async => JsonUji({'Transfer': TransferGudangUji(sisa: '0.0000', status: 'Diterima')});
    final hasil = await u.gudang.KirimTerimaTransfer(transfer, draf, budi);
    expect(hasil.status, 'Diterima');
    expect(u.server.permintaan.single.url.path, '/api/pos/v1/gudang/transfer/${UuidGudangUji.transfer}/terima');
    expect(jsonDecode(u.server.permintaan.single.body), {
      'UuidPengguna': budi.uuid,
      'Baris': [
        {'Urutan': 1, 'Jumlah': '30.0000'},
      ],
    });
  });

  test('opname: hanya baris yang diisi (boleh 0) + produk baru hasil pindai. Seri hanya 0/1', () async {
    final opname = OpnameGudangPos.DariJson(OpnameGudangUji());
    final draf = await u.gudang.MuatDraf(JenisGudang.Opname, UuidGudangUji.opname);
    await expectLater(u.gudang.KirimHitung(opname, draf, budi), throwsA(isA<GalatKasir>()));

    draf
      ..jumlah[1] = '0'
      ..produkBaru[UuidUji.gulaAren] = '3';
    u.server.penangan = (_) async => JsonUji({'Opname': OpnameGudangUji(dihitung: 3, fisikRoti: '0.0000')});
    final hasil = await u.gudang.KirimHitung(opname, draf, budi);

    expect(hasil.jumlahDihitung, 3);
    expect(jsonDecode(u.server.permintaan.single.body), {
      'UuidPengguna': budi.uuid,
      'Hitung': [
        {'Urutan': 1, 'JumlahFisik': '0.0000'},
        {'UuidProduk': UuidUji.gulaAren, 'JumlahFisik': '3.0000'},
      ],
    });

    final seri = OpnameGudangPos.DariJson({
      ...OpnameGudangUji(),
      'Baris': [
        {...(OpnameGudangUji()['Baris']! as List<Object?>).first! as Map<String, Object?>, 'NomorSeri': 'RC-0001'},
      ],
    });
    final drafSeri = await u.gudang.MuatDraf(JenisGudang.Opname, UuidGudangUji.opname)
      ..jumlah[1] = '2';
    await expectLater(
      u.gudang.KirimHitung(seri, drafSeri, budi),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'JumlahTidakValid')),
    );
  });
}
