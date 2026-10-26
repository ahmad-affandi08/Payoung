import 'dart:convert';
import 'dart:typed_data';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/Printer/TransportCetakSistem.dart';
import 'package:pdf/pdf.dart';

/// PRD v1.97: printer sistem (PDF/AirPrint/driver OS) mencetak struk sebagai PDF gulungan dari tata letak yang sama
/// dengan printer thermal.
void main() {
  final dokumen = DokumenStruk([
    const BarisTeks('Kopi Senja', rata: RataStruk.Tengah, tebal: true),
    const BarisDuaKolom('Es Kopi Susu Aren', '36.000'),
    const BarisGaris(),
    const BarisDuaKolom('TOTAL', 'Rp 67.100', tebal: true),
    const BarisQr('https://payoung.id/s/1c.01K5'),
    BarisGambar(GambarMonokrom(8, 2, Uint8List.fromList(List.filled(16, 1)))),
  ]);

  test('PDF gulungan: lebar 58/80 mm, tinggi mengikuti isi, berkas PDF sah', () async {
    expect(PenyusunPdfStruk.AmbilFormat(LebarKertas.Mm58).width, closeTo(58 * PdfPageFormat.mm, 0.01));
    expect(PenyusunPdfStruk.AmbilFormat(LebarKertas.Mm80).width, closeTo(80 * PdfPageFormat.mm, 0.01));
    final pdf = await PenyusunPdfStruk.Susun(dokumen, LebarKertas.Mm58);
    expect(latin1.decode(pdf.sublist(0, 5)), '%PDF-');
    expect(pdf.length, greaterThan(1000));
  });

  test('dialog cetak dibuka dengan PDF & format kertas. Batal atau tidak tersedia = GalatPrinter berpesan', () async {
    final panggilan = <(Uint8List, String, PdfPageFormat)>[];
    await TransportCetakSistem(
      cetak: (pdf, nama, format) async {
        panggilan.add((pdf, nama, format));
        return true;
      },
    ).CetakDokumen(dokumen, LebarKertas.Mm80);
    expect(panggilan.single.$2, 'Struk Payoung');
    expect(panggilan.single.$3.width, closeTo(80 * PdfPageFormat.mm, 0.01));

    await expectLater(
      TransportCetakSistem(cetak: (_, _, _) async => false).CetakDokumen(dokumen, LebarKertas.Mm58),
      throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', 'Cetak lewat printer sistem dibatalkan.')),
    );
    await expectLater(
      TransportCetakSistem(cetak: (_, _, _) async => throw Exception('tanpa layanan cetak'))
          .CetakDokumen(dokumen, LebarKertas.Mm58),
      throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', contains('tidak tersedia'))),
    );
    await expectLater(const TransportCetakSistem().Kirim([0x1B]), throwsA(isA<GalatPrinter>()));
  });
}
