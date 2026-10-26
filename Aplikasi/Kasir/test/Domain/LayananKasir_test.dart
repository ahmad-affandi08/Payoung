import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/PenyimpanRahasia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Shift/LayananShift.dart';

import '../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

void main() {
  late LingkunganUji u;

  setUp(() => u = LingkunganUji.Buat());
  tearDown(() => u.Tutup());

  group('aktivasi & data awal', () {
    test(
      'kode ditukar token. Token & kunci PIN ke secure storage, identitas & data awal ke basis data lokal',
      () async {
        u.server.penangan = (p) async => p.url.path.endsWith('aktivasi')
            ? JsonUji({
                'TokenPerangkat': '12|rahasia',
                'KunciPinOffline': vektorPin['KunciPerangkat'],
                'Perangkat': {'Uuid': 'P1', 'Kode': 'POS-001', 'Nama': 'Kasir Depan'},
                'Outlet': {'Uuid': 'O1', 'Nama': 'Kopi Senja Solo Baru'},
                'Tenant': {'Nama': 'Kopi Senja'},
              }, 201)
            : JsonUji(DataAwalUji());

        await u.perangkat.Aktifkan('ab12cd34');

        expect(u.rahasia.isi[PenyimpanRahasia.kunciToken], '12|rahasia');
        expect(u.rahasia.isi[PenyimpanRahasia.kunciPin], vektorPin['KunciPerangkat']);
        expect(await u.repositori.AmbilPengaturan(KunciPengaturan.namaOutlet), 'Kopi Senja Solo Baru');
        expect((await u.repositori.AmbilStaf()).map((s) => s.Nama), ['Budi Santoso', 'Rina Wulandari', 'Sari Lestari']);
        expect(u.server.permintaan.last.headers['Authorization'], 'Bearer 12|rahasia');
      },
    );

    test('kode salah dari server & tanpa internet menjadi pesan yang jelas', () async {
      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'KodeAktivasiTidakBerlaku', 'Pesan': 'Kode salah.'},
      }, 422);
      await expectLater(u.perangkat.Aktifkan('ZZ99ZZ99'), GalatDengan('KodeAktivasiTidakBerlaku'));

      u.server.penangan = (_) async => throw http.ClientException('putus');
      await expectLater(u.perangkat.Aktifkan('ZZ99ZZ99'), GalatDengan('Offline'));
      expect(u.rahasia.isi, isEmpty);
    });
  });

  group('masuk PIN (offline, §25.2 no. 3)', () {
    test('BR-06.3 PIN benar diterima tanpa server; 5 kali salah dikunci 5 menit, lalu bisa lagi', () async {
      await u.SiapkanAktif();
      final rina = await u.Staf('Rina Wulandari');
      u.server.penangan = (_) async => throw http.ClientException('offline');

      expect((await u.masuk.Masuk(rina, KasusPin(0)['Pin']! as String)).nama, 'Rina Wulandari');
      expect(u.server.permintaan, isEmpty);

      for (var i = 0; i < 4; i++) {
        await expectLater(u.masuk.Masuk(rina, '111111'), GalatDengan('PinSalah'));
      }
      await expectLater(u.masuk.Masuk(rina, '111111'), GalatDengan('PinTerkunci'));
      await expectLater(u.masuk.Masuk(rina, KasusPin(0)['Pin']! as String), GalatDengan('PinTerkunci'));

      u.jam = u.jam.add(const Duration(minutes: 5, seconds: 1));
      expect((await u.masuk.Masuk(rina, KasusPin(0)['Pin']! as String)).uuid, rina.uuid);
    });

    test('staf tanpa verifier offline memakai server, tanpa internet diberi tahu cara mengaturnya', () async {
      await u.SiapkanAktif();
      final sari = await u.Staf('Sari Lestari');
      u.server.penangan = (_) async => JsonUji({
        'Pengguna': {'Uuid': sari.uuid, 'Nama': sari.nama},
        'Pemilik': false,
        'Izin': <String>[],
      });
      expect((await u.masuk.Masuk(sari, '482915')).uuid, sari.uuid);

      u.server.penangan = (_) async => throw http.ClientException('offline');
      await expectLater(u.masuk.Masuk(sari, '482915'), GalatDengan('PinOfflineBelumTersedia'));
    });
  });

  group('buka shift & kas (F-06)', () {
    test(
      'buka shift: dokumen + outbox Shift.Buka dalam satu transaksi, data sama dengan kontrak server; BR-06.1',
      () async {
        await u.SiapkanAktif();
        final rina = await u.Staf('Rina Wulandari');

        final shift = await u.shift.BukaShift(
          kasir: rina,
          kasAwal: Uang.Dari('750000'),
          pecahan: const [
            BarisPecahan(100000, 5),
            BarisPecahan(50000, 4),
            BarisPecahan(2000, 25),
            BarisPecahan(500, 0),
          ],
        );
        final outbox = await u.repositori.AmbilOutboxSiapKirim(50, u.jam);
        final data = jsonDecode(outbox.single.Data) as Map<String, Object?>;

        expect(shift.KasAwal, '750000.00');
        expect(outbox.single.Jenis, 'Shift.Buka');
        expect(outbox.single.Uuid, shift.Uuid);
        expect(PembuatUlid.CekValid(shift.Uuid), isTrue);
        expect(data, {
          'UuidPengguna': rina.uuid,
          'DibukaPada': '2026-09-24T01:00:00.000Z',
          'KasAwal': '750000.00',
          'Pecahan': [
            {'Nominal': '100000', 'Jumlah': 5},
            {'Nominal': '50000', 'Jumlah': 4},
            {'Nominal': '2000', 'Jumlah': 25},
          ],
          'Bersama': false,
        });

        await expectLater(u.shift.BukaShift(kasir: rina, kasAwal: Uang.Nol()), GalatDengan('ShiftSudahTerbuka'));
      },
    );

    test('pecahan tidak sama dengan kas awal & kas awal minus ditolak. Tidak ada data tersimpan', () async {
      await u.SiapkanAktif();
      final rina = await u.Staf('Rina Wulandari');

      await expectLater(
        u.shift.BukaShift(kasir: rina, kasAwal: Uang.Dari('100000'), pecahan: const [BarisPecahan(50000, 1)]),
        GalatDengan('PecahanTidakSesuai'),
      );
      await expectLater(u.shift.BukaShift(kasir: rina, kasAwal: Uang.Dari('-1')), GalatDengan('KasAwalTidakValid'));
      expect(await u.repositori.AmbilShiftAktif(), isNull);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam), isEmpty);
    });

    test('BR-06.4 kas keluar di atas batas butuh supervisor berizin. Kategori wajib. Setoran tanpa kategori', () async {
      await u.SiapkanAktif();
      final rina = await u.Staf('Rina Wulandari');
      final budi = await u.Staf('Budi Santoso');
      final sari = await u.Staf('Sari Lestari');
      final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.Dari('500000'));
      const esBatu = '01K5KATEGORI00000000000001';

      expect(await u.shift.CekButuhPersetujuan(JenisMutasi.keluar, Uang.Dari('200000')), isFalse);
      expect(await u.shift.CekButuhPersetujuan(JenisMutasi.keluar, Uang.Dari('200000.01')), isTrue);
      await expectLater(
        u.shift.CatatMutasi(
          shift: shift,
          jenis: JenisMutasi.keluar,
          jumlah: Uang.Dari('350000'),
          pencatat: rina,
          uuidKategori: esBatu,
        ),
        GalatDengan('PersetujuanDiperlukan'),
      );
      await expectLater(
        u.shift.CatatMutasi(
          shift: shift,
          jenis: JenisMutasi.keluar,
          jumlah: Uang.Dari('350000'),
          pencatat: rina,
          uuidKategori: esBatu,
          penyetuju: sari,
        ),
        GalatDengan('PenyetujuTidakBerwenang'),
      );
      await expectLater(
        u.shift.CatatMutasi(shift: shift, jenis: JenisMutasi.keluar, jumlah: Uang.Dari('5000'), pencatat: rina),
        GalatDengan('KategoriTidakValid'),
      );

      final keluar = await u.shift.CatatMutasi(
        shift: shift,
        jenis: JenisMutasi.keluar,
        jumlah: Uang.Dari('350000'),
        pencatat: rina,
        uuidKategori: esBatu,
        catatan: '  Es batu 3 karung  ',
        penyetuju: budi,
      );
      await u.shift.CatatMutasi(
        shift: shift,
        jenis: JenisMutasi.setoran,
        jumlah: Uang.Dari('100000'),
        pencatat: rina,
        uuidKategori: esBatu,
      );

      final outbox = await u.repositori.AmbilOutboxSiapKirim(50, u.jam);
      final dataKeluar = jsonDecode(outbox[1].Data) as Map<String, Object?>;
      final dataSetoran = jsonDecode(outbox[2].Data) as Map<String, Object?>;

      expect(outbox.map((o) => o.Jenis), ['Shift.Buka', 'MutasiKas.Catat', 'MutasiKas.Catat']);
      expect(dataKeluar['UuidPenyetuju'], budi.uuid);
      expect(dataKeluar['Catatan'], 'Es batu 3 karung');
      expect(dataKeluar['Jumlah'], '350000.00');
      expect(keluar.NamaKategori, 'Beli es batu & galon');
      expect(dataSetoran['UuidKategori'], isNull);
      expect(
        LayananShift.HitungKasNonPenjualan(shift, await u.repositori.AmbilMutasi(shift.Uuid)).KeString(),
        '50000.00',
      );
    });

    test(
      'BR-06.2 shift bukan bersama: kasir lain ditolak, supervisor boleh. Shift bersama: kasir lain boleh',
      () async {
        await u.SiapkanAktif();
        final rina = await u.Staf('Rina Wulandari');
        final sari = await u.Staf('Sari Lestari');
        final budi = await u.Staf('Budi Santoso');
        const esBatu = '01K5KATEGORI00000000000001';
        final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.Nol());

        await expectLater(
          u.shift.CatatMutasi(
            shift: shift,
            jenis: JenisMutasi.keluar,
            jumlah: Uang.Dari('5000'),
            pencatat: sari,
            uuidKategori: esBatu,
          ),
          GalatDengan('BukanShiftSendiri'),
        );
        await u.shift.CatatMutasi(
          shift: shift,
          jenis: JenisMutasi.keluar,
          jumlah: Uang.Dari('5000'),
          pencatat: budi,
          uuidKategori: esBatu,
        );

        final lain = LingkunganUji.Buat();
        addTearDown(lain.Tutup);
        await lain.SiapkanAktif(shiftBersama: true);
        final shiftBersama = await lain.shift.BukaShift(kasir: await lain.Staf('Rina Wulandari'), kasAwal: Uang.Nol());
        expect(shiftBersama.Bersama, isTrue);
        await lain.shift.CatatMutasi(
          shift: shiftBersama,
          jenis: JenisMutasi.keluar,
          jumlah: Uang.Dari('5000'),
          pencatat: await lain.Staf('Sari Lestari'),
          uuidKategori: esBatu,
        );
      },
    );
  });

  group('sinkron outbox (PRD §18)', () {
    test('FIFO: shift sebelum mutasinya; Diterima/Duplikat dihapus, Ditolak ke Perlu Tindakan dengan alasan', () async {
      await u.SiapkanAktif();
      final rina = await u.Staf('Rina Wulandari');
      final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.Dari('500000'));
      final mutasi = await u.shift.CatatMutasi(
        shift: shift,
        jenis: JenisMutasi.masuk,
        jumlah: Uang.Dari('20000'),
        pencatat: rina,
        uuidKategori: '01K5KATEGORI00000000000002',
      );
      u.server.penangan = (p) async => JsonUji({
        'Hasil': [
          {'Uuid': shift.Uuid, 'Jenis': 'Shift.Buka', 'Status': 'Diterima', 'Galat': null},
          {
            'Uuid': mutasi.Uuid,
            'Jenis': 'MutasiKas.Catat',
            'Status': 'Ditolak',
            'Galat': {'Kode': 'KategoriNonaktif', 'Pesan': 'Kategori sudah dinonaktifkan.'},
          },
        ],
      });

      final ringkasan = await u.sinkron.KirimTertunda();
      final dikirim = jsonDecode(u.server.permintaan.single.body) as Map<String, Object?>;

      expect((dikirim['Item']! as List<Object?>).map((i) => (i! as Map<String, Object?>)['Uuid']), [
        shift.Uuid,
        mutasi.Uuid,
      ]);
      expect(ringkasan.terkirim, 1);
      expect(ringkasan.ditolak, 1);
      final perlu = await u.repositori.PantauPerluTindakan().first;
      expect(perlu.single.Uuid, mutasi.Uuid);
      expect(perlu.single.PesanGalat, 'Kategori sudah dinonaktifkan.');
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam), isEmpty);

      await u.repositori.CobaLagi(mutasi.Uuid, u.jam);
      expect((await u.repositori.AmbilOutboxSiapKirim(50, u.jam)).single.Uuid, mutasi.Uuid);
    });

    test('offline/5xx: item dijadwalkan ulang dengan mundur eksponensial, tidak dikirim sebelum waktunya', () async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.Nol());
      u.server.penangan = (_) async => http.Response('gangguan', 503);

      expect((await u.sinkron.KirimTertunda()).offline, isTrue);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam), isEmpty);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam.add(const Duration(seconds: 5))), hasLength(1));

      u.jam = u.jam.add(const Duration(seconds: 5));
      expect((await u.sinkron.KirimTertunda()).offline, isTrue);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam.add(const Duration(seconds: 9))), isEmpty);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam.add(const Duration(seconds: 10))), hasLength(1));
    });

    test('perangkat dicabut: rahasia & data PIN lokal dihapus, transaksi yang belum terkirim tetap disimpan', () async {
      await u.SiapkanAktif();
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.Nol());
      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'PerangkatDicabut', 'Pesan': 'Dicabut.'},
      }, 403);

      expect((await u.sinkron.KirimTertunda()).perangkatDicabut, isTrue);
      expect(u.rahasia.isi, isEmpty);
      expect(await u.repositori.AmbilStaf(), isEmpty);
      expect(await u.repositori.AmbilShiftAktif(), isNotNull);
      expect(await u.repositori.AmbilOutboxSiapKirim(50, u.jam), hasLength(1));
    });

    test('audit P0 F-01 masa pemulihan: outbox dikosongkan dulu (termasuk yang menunggu jadwal ulang), baru token '
        'dihapus. Item membawa perangkat pembuatnya', () async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.uuidPerangkat, '01K5PRGLAMA000000000000001');
      final rina = await u.Staf('Rina Wulandari');
      final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.Nol());
      final mutasi = await u.shift.CatatMutasi(
        shift: shift,
        jenis: JenisMutasi.masuk,
        jumlah: Uang.Dari('20000'),
        pencatat: rina,
        uuidKategori: '01K5KATEGORI00000000000002',
      );
      // Mutasi sedang menunggu jadwal ulang (gagal jaringan sebelumnya).
      final barisMutasi = (await u.repositori.AmbilOutboxSiapKirim(50, u.jam)).last;
      await u.repositori.JadwalkanUlang([barisMutasi], u.jam, 'Putus');
      final dikirim = <String>[];
      u.server.penangan = (p) async {
        final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
            .cast<Map<String, Object?>>();
        dikirim.addAll(item.map((i) => '${i['Uuid']}@${i['UuidPerangkatAsal']}'));
        return JsonUji({
          'Hasil': [
            for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
          'PerangkatDicabut': true,
        });
      };

      final ringkasan = await u.sinkron.KirimTertunda();

      expect(ringkasan.perangkatDicabut, isTrue);
      expect(ringkasan.terkirim, 2);
      expect(dikirim, ['${shift.Uuid}@01K5PRGLAMA000000000000001', '${mutasi.Uuid}@01K5PRGLAMA000000000000001']);
      expect(await u.repositori.HitungJumlahTertunda(), 0);
      expect(u.rahasia.isi, isEmpty);
      expect(await u.repositori.AmbilStaf(), isEmpty);
    });

    test('audit P0 F-01 aktivasi ulang: outbox lama dikirim atas nama perangkat lama, item baru atas nama perangkat '
        'baru', () async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.uuidPerangkat, '01K5PRGLAMA000000000000001');
      final lama = await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.Nol());
      u.server.penangan = (_) async => JsonUji({
        'Galat': {'Kode': 'PerangkatDicabut', 'Pesan': 'Dicabut.'},
      }, 403);
      expect((await u.sinkron.KirimTertunda()).perangkatDicabut, isTrue);

      // Diaktifkan ulang sebagai perangkat baru (token & identitas baru), lalu tutup shift lama di perangkat baru.
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.uuidPerangkat, '01K5PRGBARU000000000000001');
      await u.shift.CatatMutasi(
        shift: lama,
        jenis: JenisMutasi.masuk,
        jumlah: Uang.Dari('5000'),
        pencatat: await u.Staf('Rina Wulandari'),
        uuidKategori: '01K5KATEGORI00000000000002',
      );
      late List<Map<String, Object?>> item;
      u.server.penangan = (p) async {
        item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>).cast<Map<String, Object?>>();
        return JsonUji({
          'Hasil': [
            for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
          'PerangkatDicabut': false,
        });
      };

      expect((await u.sinkron.KirimTertunda()).terkirim, 2);
      expect(item.map((i) => i['UuidPerangkatAsal']), ['01K5PRGLAMA000000000000001', '01K5PRGBARU000000000000001']);
      expect(u.rahasia.isi, isNotEmpty);
    });

    test(
      'audit P0 F-01 SelesaikanPencabutan: offline = token dipertahankan. Online = sisa dikirim lalu token dihapus',
      () async {
        await u.SiapkanAktif();
        await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.Nol());
        u.server.penangan = (_) async => http.Response('gangguan', 503);

        expect(await u.sinkron.SelesaikanPencabutan(), isFalse);
        expect(u.rahasia.isi, isNotEmpty);
        expect(await u.repositori.HitungJumlahTertunda(), 1);

        u.server.penangan = (p) async {
          final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
              .cast<Map<String, Object?>>();
          return JsonUji({
            'Hasil': [
              for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
            ],
            'PerangkatDicabut': true,
          });
        };
        // Item sedang menunggu jadwal ulang; pencabutan tetap mengirimnya sekarang.
        expect(await u.sinkron.SelesaikanPencabutan(), isTrue);
        expect(await u.repositori.HitungJumlahTertunda(), 0);
        expect(u.rahasia.isi, isEmpty);
      },
    );
  });
}
