import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// Layar masuk Aplikasi Owner: orang yang mengunduh aplikasi lebih dulu belum punya akun, jadi ada penunjuk ke situs
/// pemasaran. Toko berserver sendiri (edisi Lisensi) tidak mendaftar lewat situs Payoung, jadi penunjuknya hilang.
void main() {
  testWidgets('layar masuk menunjuk ke situs pemasaran dan menyembunyikannya untuk server sendiri', (tester) async {
    await PasangPemilik(tester, server: ServerTiruan(), sesi: PenyimpanSesiMemori());

    expect(find.text('Masuk ke Payoung Owner'), findsOneWidget);
    expect(find.text('Belum punya akun Payoung?'), findsOneWidget);
    expect(find.byKey(const ValueKey('TautanSitusPemasaran')), findsOneWidget);

    await tester.ensureVisible(find.text('Toko memakai server sendiri?'));
    await tester.tap(find.text('Toko memakai server sendiri?'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('TautanSitusPemasaran')), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
