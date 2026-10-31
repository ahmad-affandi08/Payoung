import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-09 anti-fraud kasir di Aplikasi Owner: mode Anti-fraud di Laporan meminta rentang tanggal terpilih (bawaan awal bulan sampai hari ini),
/// menampilkan kasir berurut skor dengan alasan berteks, dan kasir tanpa pola mencurigakan ditandai bersih
/// (360 dan 800 dp).
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
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': 'Produk',
        'Baris': <Object?>[],
        'Total': {'Jumlah': '0', 'Omzet': '0.00'},
      }),
      'shift' => JsonUji({'Shift': <Object?>[]}),
      'perangkat' => JsonUji({'Perangkat': <Object?>[]}),
      'persetujuan' => JsonUji({'Persetujuan': <Object?>[]}),
      'laporan/anti-fraud' => JsonUji({
        'Kasir': [
          {
            'Kunci': 'K1',
            'NamaKasir': 'Rina Wulandari',
            'JumlahTransaksi': 4,
            'JumlahVoid': 3,
            'Skor': 50,
            'Tingkat': 'Sedang',
            'Alasan': ['3 void tunai ≤ 10 menit setelah bayar', 'Void 75,0% dari transaksi (rata-rata 21,4%)'],
          },
          {
            'Kunci': 'K2',
            'NamaKasir': 'Budi Santoso',
            'JumlahTransaksi': 10,
            'JumlahVoid': 0,
            'Skor': 0,
            'Tingkat': 'Rendah',
            'Alasan': <Object?>[],
          },
        ],
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
    testWidgets('mode Anti-fraud menampilkan skor dan alasan (${ukuran.width.toInt()} dp)', (tester) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      await tester.tap(find.text('Laporan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Anti-fraud'));
      await tester.pumpAndSettle();

      final minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/laporan/anti-fraud'));
      expect(minta.url.queryParameters['dari'], '2026-09-01');
      expect(minta.url.queryParameters['sampai'], '2026-09-26');

      expect(find.text('Rina Wulandari'), findsOneWidget);
      expect(find.text('Risiko sedang | skor 50'), findsOneWidget);
      expect(find.text('• 3 void tunai ≤ 10 menit setelah bayar'), findsOneWidget);
      expect(find.text('4 transaksi | 3 void'), findsOneWidget);
      expect(find.text('Budi Santoso'), findsOneWidget);
      expect(find.text('Tidak ada pola yang mencurigakan.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('paket belum memuat Anti-fraud (403): pesan server tampil dengan "Coba lagi", tanpa memuat berulang', (
    tester,
  ) async {
    server.penangan = (p) async {
      if (p.url.path.endsWith('/laporan/anti-fraud')) {
        return JsonUji({
          'Galat': {
            'Kode': 'FiturTidakAktif',
            'Pesan': 'Anti-fraud belum termasuk di paket usaha ini. Naik paket atau tambah add-on di menu Langganan.',
            'Detail': {'Fitur': 'keamanan.anti-fraud'},
          },
        }, 403);
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Anti-fraud'));
    // Riverpod 3 mencoba ulang provider gagal secara bawaan dan menahan status "memuat"; di sini galat harus langsung tampil.
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 10));

    expect(find.textContaining('Anti-fraud belum termasuk di paket usaha ini'), findsOneWidget);
    expect(find.text('Coba lagi'), findsOneWidget);
    expect(server.permintaan.where((p) => p.url.path.endsWith('/laporan/anti-fraud')), hasLength(1));
    expect(tester.takeException(), isNull);
  });
}
