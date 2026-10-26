import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Shift/LayananShift.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// F-06 BR-06.1: satu kasir hanya boleh punya satu shift terbuka per outlet. Saat online aplikasi menanyakan server
/// sebelum membuka shift dan menolak bila kasir masih punya shift di perangkat lain; saat offline, lambat, atau galat
/// shift tetap dibuka (server menandainya `PerluTinjauan`), dan shift bersama (BR-06.2) tidak dicek.
void main() {
  late LingkunganUji u;
  late StafLokal rina;
  final lain = ShiftKasirPerangkatLainPos(
    uuid: '01J9SHIFTLAIN00000000000A',
    dibukaPada: DateTime.utc(2026, 10, 12, 1, 5),
    namaPerangkat: 'Kasir Belakang',
  );

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
  });

  tearDown(() => u.Tutup());

  LayananShift LayananDenganCek(Future<List<ShiftKasirPerangkatLainPos>> Function(String) cek) =>
      LayananShift(repositori: u.repositori, cekShiftKasirLain: cek, jam: () => u.jam);

  test('shift kasir di perangkat lain ditolak dengan nama perangkat. Tidak ada shift atau outbox tersimpan', () async {
    String? ditanya;
    final layanan = LayananDenganCek((uuid) async {
      ditanya = uuid;
      return [lain];
    });

    await expectLater(
      layanan.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000)),
      GalatDengan('ShiftKasirDiPerangkatLain'),
    );
    expect(ditanya, rina.uuid);
    expect(await u.repositori.AmbilShiftAktif(), isNull);
    expect(await u.db.select(u.db.outbox).get(), isEmpty);

    try {
      await layanan.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
    } on GalatKasir catch (galat) {
      expect(galat.pesan, contains('Kasir Belakang'));
      expect(galat.pesan, contains('Rina Wulandari'));
    }
  });

  test('server menjawab kosong: shift dibuka seperti biasa', () async {
    final layanan = LayananDenganCek((_) async => const []);

    final shift = await layanan.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

    expect(shift.DibukaOleh, rina.uuid);
    expect(await u.repositori.AmbilShiftAktif(), isNotNull);
  });

  test('shift bersama (BR-06.2): server tidak ditanya dan shift dibuka', () async {
    await u.repositori.SimpanPengaturan(KunciPengaturan.shiftBersama, '1');
    var ditanya = false;
    final layanan = LayananDenganCek((_) async {
      ditanya = true;
      return [lain];
    });

    await layanan.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

    expect(ditanya, isFalse);
    expect(await u.repositori.AmbilShiftAktif(), isNotNull);
  });

  test('tanpa pemeriksa (perilaku lama): shift dibuka', () async {
    final shift = await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

    expect(shift.DibukaOleh, rina.uuid);
  });

  group('LayananSinkron.CariShiftKasirDiPerangkatLain', () {
    test('menerjemahkan jawaban server dan menyertakan UuidPengguna di alamat', () async {
      Uri? alamat;
      u.server.penangan = (p) async {
        alamat = p.url;
        return JsonUji({
          'Shift': [
            {'Uuid': lain.uuid, 'DibukaPada': '2026-10-12T01:05:00Z', 'NamaPerangkat': 'Kasir Belakang'},
          ],
        });
      };

      final hasil = await u.sinkron.CariShiftKasirDiPerangkatLain(rina.uuid);

      expect(hasil, hasLength(1));
      expect(hasil.first.uuid, lain.uuid);
      expect(hasil.first.namaPerangkat, 'Kasir Belakang');
      expect(alamat!.path, endsWith('/shift/kasir-terbuka'));
      expect(alamat!.queryParameters['UuidPengguna'], rina.uuid);
    });

    test('offline atau galat server (500, 403): daftar kosong, tidak melempar', () async {
      u.server.penangan = (p) async => throw http.ClientException('offline');
      expect(await u.sinkron.CariShiftKasirDiPerangkatLain(rina.uuid), isEmpty);

      u.server.penangan = (p) async => http.Response(
        jsonEncode({
          'Galat': {'Kode': 'X', 'Pesan': 'x'},
        }),
        500,
      );
      expect(await u.sinkron.CariShiftKasirDiPerangkatLain(rina.uuid), isEmpty);

      u.server.penangan = (p) async => http.Response(
        jsonEncode({
          'Galat': {'Kode': 'PenyetujuTidakBerwenang', 'Pesan': 'x'},
        }),
        403,
      );
      expect(await u.sinkron.CariShiftKasirDiPerangkatLain(rina.uuid), isEmpty);
    });
  });
}
