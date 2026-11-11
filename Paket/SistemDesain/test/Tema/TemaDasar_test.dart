import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

void main() {
  group('BuatTema (PRD §17.5, §17.6)', () {
    test('memakai font Lato yang di-bundle', () {
      final tema = BuatTema();
      expect(tema.textTheme.bodyMedium!.fontFamily, 'packages/sistem_desain/Lato');
    });

    test('selalu tema terang dengan token warna bawaan (D-14)', () {
      final tema = BuatTema();
      expect(tema.brightness, Brightness.light);
      expect(tema.colorScheme.brightness, Brightness.light);
      expect(tema.extension<TokenWarna>(), TokenWarna.bawaan);
      expect(BuatTema(kepadatan: KepadatanTipografi.Ringkas).brightness, Brightness.light);
    });

    test('skala tipografi mengikuti mode kepadatan', () {
      final nyaman = BuatTema().textTheme;
      final ringkas = BuatTema(kepadatan: KepadatanTipografi.Ringkas).textTheme;
      expect(nyaman.displayMedium!.fontSize, 36);
      expect(nyaman.bodyMedium!.fontSize, 16);
      expect(ringkas.displayMedium!.fontSize, 30);
      expect(ringkas.bodyMedium!.fontSize, 14);
      expect(nyaman.headlineSmall!.fontWeight, FontWeight.w700);
      expect(nyaman.headlineSmall!.letterSpacing, 0);
    });
  });
}
