import 'dart:convert';
import 'dart:io';

import 'package:cryptography/cryptography.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Pembaruan/PemasangPembaruan.dart';

/// POS-23 (D-154): pembaruan otomatis Windows hanya boleh menjalankan pemasang yang https, di bawah batas ukuran, dan
/// SHA-256-nya sama dengan yang dikirim server. Selain itu berkas dihapus dan tidak ada yang dijalankan.
void main() {
  late Directory folder;
  late List<(String, List<String>)> dijalankan;
  var keluar = 0;

  setUp(() {
    folder = Directory.systemTemp.createTempSync('pembaruan_');
    addTearDown(() => folder.deleteSync(recursive: true));
    dijalankan = [];
    keluar = 0;
  });

  Future<String> Sha256Hex(List<int> isi) async =>
      (await Sha256().hash(isi)).bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();

  PemasangPembaruanWindows Buat(http.Client klien) => PemasangPembaruanWindows(
    klien: klien,
    folderUnduhan: () async => Directory('${folder.path}/unduhan'),
    jalankan: (berkas, argumen) async => dijalankan.add((berkas, argumen)),
    keluar: () => keluar++,
  );

  final isi = utf8.encode('PEMASANG-PAYOUNG-0.2.0' * 1000);

  test('unduh https + checksum cocok: pemasang dijalankan diam-diam, aplikasi keluar, kemajuan dilaporkan', () async {
    final klien = MockClient.streaming(
      (p, _) async => http.StreamedResponse(
        Stream.fromIterable([isi.sublist(0, 10000), isi.sublist(10000)]),
        200,
        contentLength: isi.length,
      ),
    );
    final tahap = <TahapPembaruan>[];
    final bagian = <double?>[];

    await Buat(klien).UnduhDanPasang(
      tautan: 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-0.2.0.exe',
      sha256: (await Sha256Hex(isi)).toUpperCase(),
      saatKemajuan: (t, b) {
        tahap.add(t);
        bagian.add(b);
      },
    );

    expect(dijalankan, hasLength(1));
    expect(dijalankan.single.$1, endsWith('Payoung-Kasir-Pembaruan.exe'));
    expect(
      dijalankan.single.$2,
      containsAll(['/VERYSILENT', '/CLOSEAPPLICATIONS', '/RESTARTAPPLICATIONS', '/NORESTART']),
    );
    expect(File(dijalankan.single.$1).readAsBytesSync(), isi);
    expect(keluar, 1);
    expect(tahap, containsAllInOrder([TahapPembaruan.Mengunduh, TahapPembaruan.Memeriksa, TahapPembaruan.Memasang]));
    expect(bagian.whereType<double>().last, closeTo(1, 1e-9));
  });

  test('checksum tidak cocok: GalatKasir, berkas dihapus, tidak ada yang dijalankan atau ditutup', () async {
    final klien = MockClient.streaming(
      (p, _) async => http.StreamedResponse(Stream.value(isi), 200, contentLength: isi.length),
    );

    await expectLater(
      Buat(klien).UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: 'a' * 64),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'ChecksumTidakCocok')),
    );

    expect(dijalankan, isEmpty);
    expect(keluar, 0);
    expect(File('${folder.path}/unduhan/Payoung-Kasir-Pembaruan.exe').existsSync(), isFalse);
  });

  test(
    'menolak tautan bukan https, checksum kosong/rusak, dan jawaban server bukan 200 tanpa menjalankan apa pun',
    () async {
      var diminta = 0;
      final klien = MockClient.streaming((p, _) async {
        diminta++;
        return http.StreamedResponse(Stream.value(isi), 404, contentLength: isi.length);
      });
      final pemasang = Buat(klien);
      final sah = 'b' * 64;

      Future<void> Harapkan(Future<void> Function() panggil, String kode) =>
          expectLater(panggil(), throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode)));

      await Harapkan(
        () => pemasang.UnduhDanPasang(tautan: 'http://unduh.payoung.id/x.exe', sha256: sah),
        'TautanTidakAman',
      );
      await Harapkan(
        () => pemasang.UnduhDanPasang(tautan: 'ftp://unduh.payoung.id/x.exe', sha256: sah),
        'TautanTidakAman',
      );
      await Harapkan(() => pemasang.UnduhDanPasang(tautan: 'bukan tautan', sha256: sah), 'TautanTidakAman');
      await Harapkan(
        () => pemasang.UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: ''),
        'ChecksumTidakAda',
      );
      await Harapkan(
        () => pemasang.UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: 'zz' * 32),
        'ChecksumTidakAda',
      );
      expect(diminta, 0, reason: 'Tautan/checksum yang tidak sah ditolak sebelum menyentuh jaringan.');

      await Harapkan(
        () => pemasang.UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: sah),
        'UnduhGagal',
      );
      expect(dijalankan, isEmpty);
      expect(keluar, 0);
    },
  );

  test('berkas lebih besar dari batas ditolak dari Content-Length maupun dari aliran tanpa panjang', () async {
    final besar = PemasangPembaruanWindows.batasUkuranByte + 1;
    final dariHeader = MockClient.streaming(
      (p, _) async => http.StreamedResponse(const Stream.empty(), 200, contentLength: besar),
    );
    await expectLater(
      Buat(dariHeader).UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: 'c' * 64),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'UkuranTerlaluBesar')),
    );
    expect(dijalankan, isEmpty);
  });

  test('jaringan putus: PerluOnline dan berkas sebagian dihapus', () async {
    final klien = MockClient.streaming((p, _) async => throw const SocketException('putus'));
    await expectLater(
      Buat(klien).UnduhDanPasang(tautan: 'https://unduh.payoung.id/x.exe', sha256: 'd' * 64),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PerluOnline')),
    );
    expect(dijalankan, isEmpty);
  });

  test('platform tanpa pemasang sendiri tidak menawarkan pemasangan otomatis', () async {
    const pemasang = PemasangPembaruanTidakAda();
    expect(pemasang.bisaPasangSendiri, isFalse);
    await expectLater(
      pemasang.UnduhDanPasang(tautan: 'https://x', sha256: 'e' * 64),
      throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', 'PembaruanManual')),
    );
  });
}
