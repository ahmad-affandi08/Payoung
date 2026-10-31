import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// Penunjuk ke situs pemasaran di layar masuk/aktivasi: orang yang mengunduh aplikasi lebih dulu tidak boleh buntu.
void main() {
  Future<void> Pasang(WidgetTester tester, {Future<bool> Function(Uri)? saatBuka}) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(360, 740);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: BuatTema(),
        home: Scaffold(
          body: Center(child: TautanSitusPemasaran(saatBuka: saatBuka)),
        ),
      ),
    );
  }

  testWidgets('alamat bawaan adalah situs pemasaran payoung.id', (tester) async {
    expect(TautanSitusPemasaran.alamat.toString(), 'https://payoung.id');
  });

  testWidgets('menjelaskan keadaannya dan membuka situs pemasaran saat diketuk', (tester) async {
    final dibuka = <Uri>[];
    await Pasang(
      tester,
      saatBuka: (a) async {
        dibuka.add(a);
        return true;
      },
    );

    expect(find.text('Belum punya akun Payoung?'), findsOneWidget);
    expect(find.text('Daftar atau kenali Payoung di payoung.id'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('TautanSitusPemasaran')));
    await tester.pump();

    expect(dibuka.map((a) => a.toString()), ['https://payoung.id']);
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('peramban gagal dibuka: pesan memuat alamat supaya bisa diketik sendiri', (tester) async {
    await Pasang(tester, saatBuka: (_) async => false);

    await tester.tap(find.byKey(const ValueKey('TautanSitusPemasaran')));
    await tester.pump();

    expect(find.text('Peramban tidak bisa dibuka. Buka payoung.id secara manual.'), findsOneWidget);
  });

  testWidgets('galat dari platform tidak menjatuhkan aplikasi, tetap tampil sebagai pesan', (tester) async {
    await Pasang(tester, saatBuka: (_) => throw StateError('tidak ada peramban'));

    await tester.tap(find.byKey(const ValueKey('TautanSitusPemasaran')));
    await tester.pump();

    expect(find.text('Peramban tidak bisa dibuka. Buka payoung.id secara manual.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
