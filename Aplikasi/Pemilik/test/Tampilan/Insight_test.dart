import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/LayarInsight.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-11: kartu "Insight minggu lalu" di Beranda dan layar Insight (perbandingan, terlaris, naik/turun, restock).
/// Tanpa penjualan untuk dibandingkan (`Insight: null`) kartu tidak tampil.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p, {bool kosong = false}) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'insight' when kosong => JsonUji({'Insight': null}),
      'insight' => JsonUji({
        'Insight': {
          'Dari': '2026-09-14',
          'Sampai': '2026-09-20',
          'Bersih': '2790000.00',
          'BersihSebelumnya': '330000.00',
          'PersenPerubahan': '745.5',
          'JumlahTransaksi': 2,
          'JumlahTransaksiSebelumnya': 2,
          'RataTransaksi': '1395000.00',
          'HariTeramai': {'Tanggal': '2026-09-15', 'Bersih': '2700000.00'},
          'Terlaris': [
            {'NamaProduk': 'Beras Pandan Wangi', 'Qty': '36.0000', 'Bersih': '2700000.00'},
          ],
          'Naik': [
            {'NamaProduk': 'Beras Pandan Wangi', 'Selisih': '2550000.00'},
          ],
          'Turun': [
            {'NamaProduk': 'Gula Pasir', 'Selisih': '-90000.00'},
          ],
          'Restock': [
            {
              'NamaProduk': 'Beras Pandan Wangi',
              'NamaGudang': 'Toko',
              'HariHabis': 1,
              'SaranBeli': '40.0000',
              'SimbolSatuan': 'krg',
            },
          ],
          'Lebaran': null,
        },
      }),
      _ => JsonUji({}, 404),
    };
  }

  test('persen perubahan berformat Indonesia dengan tanda', () {
    expect(LayarInsight.Persen('745.5'), '+745,5%');
    expect(LayarInsight.Persen('-12.0'), '-12,0%');
    expect(LayarInsight.Persen(null), isNull);
  });

  testWidgets('baris di tab Lainnya meringkas minggu lalu. Ketuk membuka layar Insight', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Insight minggu lalu'), 200);

    expect(find.text('Insight minggu lalu'), findsOneWidget);
    expect(find.textContaining('(+745,5%) | 2 transaksi'), findsOneWidget);

    await tester.tap(find.text('Insight minggu lalu'));
    await tester.pumpAndSettle();

    expect(find.textContaining('+745,5% dari minggu sebelumnya'), findsOneWidget);
    expect(find.text('36 terjual'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Segera restock'), 200);
    expect(find.text('habis ±1 hari lagi | Toko | saran beli 40 krg'), findsOneWidget);
  });

  testWidgets('tanpa penjualan untuk dibandingkan: baris Insight tidak tampil', (tester) async {
    server.penangan = (p) => Penangan(p, kosong: true);
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Omzet'), findsOneWidget);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    expect(find.text('Insight minggu lalu'), findsNothing);
  });
}
