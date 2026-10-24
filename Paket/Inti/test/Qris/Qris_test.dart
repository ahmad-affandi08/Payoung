import 'dart:convert';
import 'dart:io';

import 'package:inti/Inti.dart';
import 'package:test/test.dart';

/// D-189: vektor bersama PHP, Dart, dan TypeScript (`Spesifikasi/VektorUjiQris/QrisDinamis.json`).
void main() {
  final berkas = File('../../Spesifikasi/VektorUjiQris/QrisDinamis.json');
  final vektor = jsonDecode(berkas.readAsStringSync()) as Map<String, Object?>;
  final kasus = (vektor['Kasus']! as List).cast<Map<String, Object?>>();
  final tidakSah = (vektor['TidakSah']! as List).cast<Map<String, Object?>>();
  final nominalTidakSah = (vektor['NominalTidakSah']! as List).cast<Map<String, Object?>>();

  String? KodeGalat(void Function() kerja) {
    try {
      kerja();
    } on GalatQris catch (galat) {
      return galat.kode;
    }
    return null;
  }

  group('QRIS statis menjadi bernominal', () {
    for (final k in kasus) {
      test(k['Nama']! as String, () {
        final statis = k['Statis']! as String;

        expect(Qris.Periksa(statis).namaMerchant, isNotEmpty);
        expect(Qris.BuatDinamis(statis, k['Nominal']! as String), k['Dinamis']);
        // Hasilnya QRIS yang CRC-nya sah dan sudah bernominal (bukan statis lagi).
        expect(KodeGalat(() => Qris.Periksa(k['Dinamis']! as String)), 'BukanStatis');
      });
    }
  });

  group('QRIS tidak sah ditolak dengan kode yang sama di semua bahasa', () {
    for (final k in tidakSah) {
      test(k['Nama']! as String, () {
        expect(KodeGalat(() => Qris.Periksa(k['Teks']! as String)), k['Galat']);
        expect(KodeGalat(() => Qris.BuatDinamis(k['Teks']! as String, '25000')), k['Galat']);
      });
    }
  });

  test('nominal tidak sah ditolak', () {
    final statis = (kasus.first['Statis']! as String);
    for (final n in nominalTidakSah) {
      expect(KodeGalat(() => Qris.BuatDinamis(statis, n['Nominal']! as String)), n['Galat'], reason: '${n['Nominal']}');
    }
  });

  test('CRC16 CCITT-FALSE: nilai acuan standar "123456789" = 29B1', () {
    expect(Qris.HitungCrc16('123456789'), '29B1');
  });

  test('ringkasan menampilkan nama merchant dan kota', () {
    final info = Qris.Periksa(kasus.first['Statis']! as String);

    expect(info.namaMerchant, 'TOKO SEMBAKO MAJU JAYA');
    expect(info.kota, 'SURAKARTA');
  });
}
