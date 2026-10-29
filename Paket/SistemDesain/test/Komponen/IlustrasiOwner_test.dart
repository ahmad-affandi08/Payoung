import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

void main() {
  testWidgets('semua ilustrasi Owner (D-231) termuat dari aset bundel', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: Column(children: [for (final i in IlustrasiOwner.values) IlustrasiMerek(i, lebar: 80)]),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    for (final i in IlustrasiOwner.values) {
      final gambar = tester.widget<Image>(find.byKey(ValueKey('Ilustrasi${i.name}')));
      expect(gambar.image, isA<AssetImage>());
      expect((gambar.image as AssetImage).assetName, 'assets/ilustrasi/owner/${i.name}.png');
    }
    // Tidak ada galat pemuatan: berkasnya benar-benar ada di bundel.
    expect(tester.takeException(), isNull);
  });

  test('BuatTemaModern: kartu besar tanpa garis, tombol 52dp, tetap tema terang bertoken', () {
    final tema = BuatTemaModern();
    expect(tema.brightness, Brightness.light);
    expect(tema.extension<TokenWarna>(), TokenWarna.bawaan);
    final bentuk = tema.cardTheme.shape! as RoundedRectangleBorder;
    expect(bentuk.borderRadius, BorderRadius.circular(TokenJarak.radiusKartu));
    expect(bentuk.side, BorderSide.none);
    expect(tema.filledButtonTheme.style!.minimumSize!.resolve({}), const Size(64, 52));
    expect(tema.cardTheme.elevation, 0);
  });
}
