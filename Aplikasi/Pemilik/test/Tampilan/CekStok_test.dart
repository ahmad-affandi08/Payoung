import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import '../Pendukung/PasangPemilik.dart';

/// OWN-06 cek stok di Aplikasi Owner: mode Stok di Laporan menampilkan nilai persediaan, stok kritis, saldo per produk,
/// pencarian mengirim kata cari ke server, dan mengetuk baris membuka kartu stok (360 dan 800 dp).
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Map<String, Object?> Saldo(String nama, String jumlah, String nilai) => {
    'UuidProduk': 'P-$nama',
    'UuidGudang': 'G1',
    'NamaProduk': nama,
    'Sku': 'SKU-1',
    'SimbolSatuan': 'pcs',
    'NamaGudang': 'Toko',
    'NamaOutlet': 'Solo Baru',
    'JumlahTersedia': jumlah,
    'HppRataRata': '30000.0000',
    'NilaiPersediaan': nilai,
  };

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
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': 'Produk',
        'Baris': <Object?>[],
        'Total': {'Jumlah': '0', 'Omzet': '0.00'},
      }),
      'shift' => JsonUji({'Shift': <Object?>[]}),
      'perangkat' => JsonUji({'Perangkat': <Object?>[]}),
      'persetujuan' => JsonUji({'Persetujuan': <Object?>[]}),
      'stok' => JsonUji({
        'Ringkasan': {'NilaiPersediaan': '180000.00', 'JumlahProduk': 2, 'JumlahKritis': 1, 'JumlahMinus': 0},
        'Kritis': [
          {...Saldo('Minyak Goreng 2 L', '6.0000', '180000.00'), 'Saldo': '6.0000', 'StokMinimum': '6.0000'},
        ],
        'Saldo': {
          'Data': [Saldo('Minyak Goreng 2 L', '6.0000', '180000.00'), Saldo('Gula Pasir 1 Kg', '40.0000', '560000.00')],
          'Meta': {'Halaman': 1, 'PerHalaman': 25, 'Total': 30, 'JumlahHalaman': 2},
        },
      }),
      'stok/kartu' => JsonUji({
        'Produk': {'Uuid': 'P-Minyak', 'Nama': 'Minyak Goreng 2 L', 'Sku': 'SKU-1', 'SimbolSatuan': 'pcs'},
        'Gudang': {'Uuid': 'G1', 'Nama': 'Toko', 'NamaOutlet': 'Solo Baru'},
        'Periode': {'Dari': '2026-08-28', 'Sampai': '2026-09-26'},
        'SaldoAwal': {'Jumlah': '10.0000', 'Nilai': '300000.00'},
        'SaldoAkhir': {'Jumlah': '6.0000', 'Nilai': '180000.00'},
        'Mutasi': {
          'Data': [
            {
              'TanggalBisnis': '2026-09-20',
              'LabelJenisMutasi': 'Penjualan',
              'NomorReferensi': 'SLB-K01/2609/0007',
              'Masuk': null,
              'Keluar': '4.0000',
              'SaldoSetelah': '6.0000',
            },
          ],
          'Meta': {'Halaman': 1, 'PerHalaman': 50, 'Total': 1, 'JumlahHalaman': 1},
        },
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
    testWidgets('mode Stok: ringkasan, kritis, saldo, cari, kartu stok (${ukuran.width.toInt()} dp)', (tester) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      await tester.tap(find.text('Laporan'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Stok'));
      await tester.pumpAndSettle();

      expect(find.text('Rp 180.000'), findsWidgets);
      expect(find.text('2 produk | 1 stok kritis'), findsOneWidget);
      expect(find.text('Perlu dibeli'), findsOneWidget);
      expect(find.text('Gula Pasir 1 Kg'), findsOneWidget);
      expect(find.textContaining('Menampilkan 2 dari 30'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Cari nama, SKU, atau barcode'), 'minyak');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      expect(server.permintaan.where((p) => p.url.path.endsWith('/stok')).last.url.queryParameters['cari'], 'minyak');

      await tester.tap(find.text('Gula Pasir 1 Kg'));
      await tester.pumpAndSettle();
      expect(server.permintaan.last.url.path, endsWith('/stok/kartu'));
      expect(find.textContaining('Kartu stok | Gula Pasir 1 Kg'), findsOneWidget);
      expect(find.text('Penjualan'), findsOneWidget);
      expect(find.text('-4'), findsOneWidget);
      expect(find.textContaining('Saldo awal 10 pcs | saldo akhir 6 pcs'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
