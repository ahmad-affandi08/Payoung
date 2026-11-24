import 'dart:async';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:test/test.dart';

/// Printer tiruan yang mencatat tumpang tindih: berapa pekerjaan berjalan bersamaan dan urutan isi yang tiba.
class _PrinterLambat implements TransportPrinter {
  _PrinterLambat(this.lama, {this.gagalUntuk});

  final Duration lama;
  final String? gagalUntuk;
  var berjalan = 0;
  var puncak = 0;
  final List<String> urutan = [];

  @override
  Future<void> Kirim(List<int> data) async {
    berjalan++;
    if (berjalan > puncak) {
      puncak = berjalan;
    }
    final isi = String.fromCharCodes(data);
    await Future<void>.delayed(lama);
    berjalan--;
    if (isi == gagalUntuk) {
      throw const GalatPrinter('printer mati');
    }
    urutan.add(isi);
  }
}

class _Sistem implements TransportDokumen {
  final List<String> dokumen = [];

  @override
  Future<void> CetakDokumen(DokumenStruk d, LebarKertas lebar) async => dokumen.add('dokumen');

  @override
  Future<void> Kirim(List<int> data) async {}
}

AntreanCetak _Antrean({Duration batas = const Duration(seconds: 5)}) =>
    AntreanCetak(jedaAntarPekerjaan: Duration.zero, batasPekerjaan: batas);

void main() {
  test('pekerjaan ke printer yang sama berjalan satu per satu sesuai urutan datang', () async {
    final antrean = _Antrean();
    final printer = _PrinterLambat(const Duration(milliseconds: 20));
    final struk = TransportBerantre(printer, 'LAN|10.0.0.5|9100', antrean);
    final dapur = TransportBerantre(printer, 'LAN|10.0.0.5|9100', antrean);
    await Future.wait([
      struk.Kirim('struk'.codeUnits),
      dapur.Kirim('dapur'.codeUnits),
      dapur.Kirim('checker'.codeUnits),
      struk.Kirim('laci'.codeUnits),
    ]);
    expect(printer.puncak, 1, reason: 'tidak boleh ada dua kiriman bersamaan ke printer yang sama');
    expect(printer.urutan, ['struk', 'dapur', 'checker', 'laci']);
    await Future<void>.delayed(const Duration(milliseconds: 10));
    expect(antrean.jumlahAktif, 0, reason: 'antrean dibersihkan setelah semua selesai');
  });

  test('printer berbeda tetap berjalan paralel', () async {
    final antrean = _Antrean();
    final a = _PrinterLambat(const Duration(milliseconds: 30));
    final b = _PrinterLambat(const Duration(milliseconds: 30));
    final pengukur = Stopwatch()..start();
    await Future.wait([
      TransportBerantre(a, 'A', antrean).Kirim([1]),
      TransportBerantre(b, 'B', antrean).Kirim([2]),
    ]);
    expect(pengukur.elapsedMilliseconds, lessThan(58));
    expect(a.puncak, 1);
    expect(b.puncak, 1);
  });

  test('pekerjaan gagal tidak menghentikan antrean dan galatnya sampai ke pemanggil yang benar', () async {
    final antrean = _Antrean();
    final printer = _PrinterLambat(const Duration(milliseconds: 5), gagalUntuk: 'rusak');
    final transport = TransportBerantre(printer, 'P', antrean);
    final pertama = transport.Kirim('rusak'.codeUnits);
    final kedua = transport.Kirim('lanjut'.codeUnits);
    await expectLater(pertama, throwsA(isA<GalatPrinter>()));
    await kedua;
    expect(printer.urutan, ['lanjut']);
  });

  test('pekerjaan yang menggantung dilepas setelah batas supaya cetak berikutnya tidak terkunci', () async {
    final antrean = _Antrean(batas: const Duration(milliseconds: 40));
    final macet = Completer<void>();
    final pertama = antrean.Jalankan('P', () => macet.future);
    var kedua = false;
    final kedaDua = antrean.Jalankan('P', () async => kedua = true);
    await kedaDua;
    expect(kedua, isTrue);
    macet.complete();
    await pertama;
  });

  test(
    'batas waktu dihitung sejak pekerjaan mulai, bukan sejak masuk antrean (tumpukan tetap satu per satu)',
    () async {
      // Lima pekerjaan 60 ms dengan batas 100 ms: total 300 ms melewati batas, tetapi tiap pekerjaan sendiri tidak.
      final antrean = _Antrean(batas: const Duration(milliseconds: 100));
      final printer = _PrinterLambat(const Duration(milliseconds: 60));
      final transport = TransportBerantre(printer, 'P', antrean);
      await Future.wait([for (var i = 0; i < 5; i++) transport.Kirim('k$i'.codeUnits)]);
      expect(
        printer.puncak,
        1,
        reason: 'antrean yang menumpuk tidak boleh melepas pekerjaan di belakangnya lebih awal',
      );
      expect(printer.urutan, ['k0', 'k1', 'k2', 'k3', 'k4']);
    },
  );

  test('Bungkus mempertahankan printer sistem sebagai TransportDokumen', () async {
    final antrean = _Antrean();
    final sistem = _Sistem();
    final bungkus = AntreanCetak.Bungkus(sistem, 'Sistem', antrean: antrean);
    expect(bungkus, isA<TransportDokumen>());
    await PrinterStruk(bungkus, LebarKertas.Mm58).Cetak(const DokumenStruk([BarisTeks('Halo')]));
    expect(sistem.dokumen, ['dokumen']);
    final thermal = AntreanCetak.Bungkus(_PrinterLambat(Duration.zero), 'T', antrean: antrean);
    expect(thermal, isNot(isA<TransportDokumen>()));
  });
}
