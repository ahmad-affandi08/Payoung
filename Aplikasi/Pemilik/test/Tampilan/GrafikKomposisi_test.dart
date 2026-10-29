import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Data/KlienPemilik.dart';
import 'package:pemilik/Tampilan/Komponen/GrafikKomposisi.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// D-236: donat komposisi dan batang perbandingan Aplikasi Owner. Angka selalu tertulis (legenda, nilai di atas batang),
/// jadi grafik tetap terbaca tanpa warna.
void main() {
  Widget Bungkus(Widget anak, {double lebar = 360}) => MaterialApp(
    theme: BuatTemaModern(),
    home: Scaffold(
      body: Center(
        child: SizedBox(width: lebar, child: anak),
      ),
    ),
  );

  const baris = [
    BarisNilai(nama: 'Kopi', omzet: '600000.00'),
    BarisNilai(nama: 'Teh', omzet: '300000.00'),
    BarisNilai(nama: 'Roti', omzet: '100000.00'),
    BarisNilai(nama: 'Kosong', omzet: '0.00'),
  ];

  test('DariBarisNilai: urut menurun, buang nol, gabung sisa jadi Lainnya', () {
    final irisan = IrisanKomposisi.DariBarisNilai(baris);
    expect(irisan.map((s) => s.label), ['Kopi', 'Teh', 'Roti']);

    final terbatas = IrisanKomposisi.DariBarisNilai(baris, maks: 2);
    expect(terbatas.map((s) => s.label), ['Kopi', 'Teh', 'Lainnya']);
    expect(terbatas.last.nilai, 100000);
    expect(terbatas.last.tampilNilai, 'Rp 100.000');
  });

  for (final lebar in const [360.0, 800.0]) {
    testWidgets('PanelKomposisi menulis nama, persen, dan nilai tiap irisan (${lebar.toInt()} dp)', (tester) async {
      await tester.pumpWidget(
        Bungkus(
          PanelKomposisi(
            irisan: IrisanKomposisi.DariBarisNilai(baris),
            judulPusat: 'Total',
            nilaiPusat: 'Rp 1.000.000',
          ),
          lebar: lebar,
        ),
      );

      expect(find.byType(GrafikDonat), findsOneWidget);
      expect(find.text('Kopi'), findsOneWidget);
      expect(find.text('60%'), findsOneWidget);
      expect(find.text('Rp 600.000'), findsOneWidget);
      expect(find.text('30%'), findsOneWidget);
      expect(find.text('10%'), findsOneWidget);
      expect(find.text('Rp 1.000.000'), findsOneWidget);
      expect(
        find.bySemanticsLabel(RegExp('Komposisi: Kopi 60 persen, Rp 600.000; Teh 30 persen')),
        findsOneWidget,
        reason: 'Pembaca layar mendapat ringkasan komposisi.',
      );
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('legenda tanpa nilai tampil hanya nama dan persen', (tester) async {
    await tester.pumpWidget(
      Bungkus(
        const PanelKomposisi(
          irisan: [
            IrisanKomposisi(label: 'Beban', nilai: 1),
            IrisanKomposisi(label: 'Laba bersih', nilai: 3),
          ],
        ),
      ),
    );

    expect(find.text('25%'), findsOneWidget);
    expect(find.text('75%'), findsOneWidget);
    expect(find.text('Laba bersih'), findsOneWidget);
  });
}
