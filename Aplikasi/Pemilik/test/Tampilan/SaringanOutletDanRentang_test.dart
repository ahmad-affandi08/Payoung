import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// Bilah saringan Aplikasi Owner: outlet bisa dipilih lalu dikembalikan ke "Semua outlet" (dulu pilihan itu tidak
/// pernah terkirim karena nilai menunya null), dan layar Laporan memakai rentang tanggal, bukan satu tanggal.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Future<http.Response> Penangan(http.Request p) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'masuk' => JsonUji({
        'Token': '5|rahasia',
        'Pengguna': {'Uuid': 'U1', 'Nama': 'Bu Sari', 'Email': 'sari@contoh.id'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': p.url.queryParameters['kelompok'],
        'Baris': [
          {'Nama': 'Es Kopi Susu Aren', 'Jumlah': '25', 'Omzet': '450000.00'},
        ],
        'Total': {'Jumlah': '25', 'Omzet': '450000.00'},
      }),
      _ => JsonUji({}, 404),
    };
  }

  Future<void> Masuk(WidgetTester tester) async {
    await tester.enterText(find.widgetWithText(TextField, 'Email'), 'sari@contoh.id');
    await tester.enterText(find.widgetWithText(TextField, 'Kata sandi'), 'rahasia123');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pumpAndSettle();
  }

  for (final ukuran in const [Size(360, 740), Size(800, 1280)]) {
    testWidgets('pilih outlet lalu kembali ke Semua outlet (${ukuran.width.toInt()} dp)', (tester) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      String? OutletTerakhir() =>
          server.permintaan.lastWhere((p) => p.url.path.endsWith('/dasbor')).url.queryParameters['outlet'];

      expect(find.text('Semua outlet'), findsOneWidget);
      expect(OutletTerakhir(), isNull);

      // Pilih satu outlet: kapsul berganti nama dan dasbor meminta outlet itu.
      await tester.tap(find.byTooltip('Pilih outlet'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Kartasura').last);
      await tester.pumpAndSettle();
      expect(OutletTerakhir(), 'O2');
      expect(find.text('Semua outlet'), findsNothing);

      // Kembali ke semua outlet: dulu langkah ini tidak berefek karena pilihan bernilai null.
      await tester.tap(find.byTooltip('Pilih outlet'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Semua outlet').last);
      await tester.pumpAndSettle();
      expect(OutletTerakhir(), isNull, reason: 'Permintaan terbaru tidak boleh membawa outlet.');
      expect(find.text('Semua outlet'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
    'Laporan memakai rentang tanggal: bawaan awal bulan sampai hari ini, bisa diganti lewat pemilih rentang',
    (tester) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi);
      await Masuk(tester);

      await tester.tap(find.text('Laporan'));
      await tester.pumpAndSettle();

      var minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/laporan/penjualan'));
      expect(minta.url.queryParameters['dari'], '2026-09-01');
      expect(minta.url.queryParameters['sampai'], '2026-09-26');
      expect(find.text('1 Sep 2026 - 26 Sep 2026'), findsOneWidget);

      await tester.tap(find.text('1 Sep 2026 - 26 Sep 2026'));
      await tester.pumpAndSettle();
      expect(find.text('Pilih rentang tanggal'), findsOneWidget);
      await tester.tap(find.text('10').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('20').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Pilih'));
      await tester.pumpAndSettle();

      minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/laporan/penjualan'));
      expect(minta.url.queryParameters['dari'], '2026-09-10');
      expect(minta.url.queryParameters['sampai'], '2026-09-20');
      expect(find.text('10 Sep 2026 - 20 Sep 2026'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('Beranda tetap memakai satu tanggal, tanpa rentang', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);

    expect(find.text('26 Sep 2026'), findsOneWidget);
    expect(find.textContaining(' - '), findsNothing);
  });
}
