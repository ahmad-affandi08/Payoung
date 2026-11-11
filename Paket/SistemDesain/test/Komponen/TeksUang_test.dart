import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

void main() {
  testWidgets('TeksUang menampilkan Rupiah dengan angka tabular', (tester) async {
    await tester.pumpWidget(MaterialApp(theme: BuatTema(), home: TeksUang(Uang.DariBulat(1250000))));
    final teks = tester.widget<Text>(find.text('Rp 1.250.000'));
    expect(teks.style!.fontFeatures, contains(const FontFeature.tabularFigures()));
    expect(teks.textAlign, TextAlign.right);
  });

  testWidgets('TeksKode memakai font Lato (tanpa varian mono, D-265)', (tester) async {
    await tester.pumpWidget(MaterialApp(theme: BuatTema(), home: const TeksKode('IL1O0-8B5S')));
    final teks = tester.widget<Text>(find.text('IL1O0-8B5S'));
    expect(teks.style!.fontFamily, 'packages/sistem_desain/Lato');
  });
}
