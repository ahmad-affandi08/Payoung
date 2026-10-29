import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/Komponen/GrafikKomposisi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/PasangPemilik.dart';

/// D-236: laporan penjualan Aplikasi Owner menggambar donat komposisi omzet (produk, kategori, kasir, kanal) atau batang
/// per jam, dan hanya bila ada minimal dua baris berangka. Daftar bertulis di bawahnya tetap memuat semua angka.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p, List<Map<String, Object?>> baris) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': p.url.queryParameters['kelompok'],
        'Baris': baris,
        'Total': {'Jumlah': '40', 'Omzet': '1000000.00'},
      }),
      _ => JsonUji({}, 404),
    };
  }

  Future<void> BukaLaporan(WidgetTester tester, List<Map<String, Object?>> baris) async {
    server.penangan = (p) => Penangan(p, baris);
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
  }

  testWidgets('per produk: donat komposisi dengan legenda persen', (tester) async {
    await BukaLaporan(tester, [
      {'Nama': 'Es Kopi Susu Aren', 'Jumlah': '25', 'Omzet': '750000.00'},
      {'Nama': 'Roti Bakar', 'Jumlah': '15', 'Omzet': '250000.00'},
    ]);

    expect(find.text('Komposisi omzet'), findsOneWidget);
    expect(find.byType(GrafikDonat), findsOneWidget);
    expect(find.text('75%'), findsOneWidget);
    expect(find.text('25%'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('satu baris saja: tanpa grafik, daftar tetap tampil', (tester) async {
    await BukaLaporan(tester, [
      {'Nama': 'Es Kopi Susu Aren', 'Jumlah': '25', 'Omzet': '1000000.00'},
    ]);

    expect(find.byType(GrafikDonat), findsNothing);
    expect(find.text('Es Kopi Susu Aren'), findsOneWidget);
  });

  testWidgets('per jam: batang omzet per jam, bukan donat', (tester) async {
    await BukaLaporan(tester, [
      {'Nama': 9, 'Jumlah': '5', 'Omzet': '200000.00'},
      {'Nama': 11, 'Jumlah': '10', 'Omzet': '800000.00'},
    ]);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Jam'));
    await tester.pumpAndSettle();

    expect(find.text('Omzet per jam'), findsOneWidget);
    expect(find.byType(GrafikBatang), findsOneWidget);
    expect(find.byType(GrafikDonat), findsNothing);
    expect(find.bySemanticsLabel(RegExp(r'Jam 10: Rp\s0')), findsOneWidget, reason: 'Jam kosong tetap batang nol.');
    expect(tester.takeException(), isNull);
  });
}
