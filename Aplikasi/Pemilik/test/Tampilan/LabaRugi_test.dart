import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/Komponen/GrafikKomposisi.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-05 laba rugi sederhana di Aplikasi Owner: pilih mode Laba rugi, kelima angka ringkasan + perubahan laba bersih
/// terhadap periode sebelumnya, rincian per akun, rentang tanggal terpilih, dan galat izin dari
/// server tampil sebagai pesan (360 dan 800 dp).
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Map<String, Object?> Nilai(String kini, String lalu) => {'Nilai': kini, 'NilaiSebelumnya': lalu};

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
      'laporan/laba-rugi' => JsonUji({
        'Periode': {
          'Dari': '2026-09-01',
          'Sampai': '2026-09-30',
          'DariSebelumnya': '2026-08-01',
          'SampaiSebelumnya': '2026-08-31',
        },
        'Baris': [
          {
            'Id': 'Akun|A1',
            'Jenis': 'Akun',
            'Kelompok': 'Pendapatan',
            'Kode': '4-1000',
            'Label': 'Penjualan Barang',
            'Nilai': '5000000.00',
            'NilaiSebelumnya': '4000000.00',
          },
          {
            'Id': 'Akun|A2',
            'Jenis': 'Akun',
            'Kelompok': 'Beban',
            'Kode': '6-2000',
            'Label': 'Beban Listrik & Air',
            'Nilai': '1250000.00',
            'NilaiSebelumnya': '1000000.00',
          },
        ],
        'Ringkasan': {
          'Pendapatan': Nilai('5000000.00', '4000000.00'),
          'Hpp': Nilai('3000000.00', '2400000.00'),
          'Beban': Nilai('1250000.00', '1000000.00'),
          'LabaKotor': Nilai('2000000.00', '1600000.00'),
          'LabaBersih': Nilai('750000.00', '600000.00'),
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
    testWidgets('mode Laba rugi menampilkan ringkasan dan rincian akun (${ukuran.width.toInt()} dp)', (tester) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);
      expect(sesi.isi[PenyimpanSesi.kunciTenant], 'T1');

      await tester.tap(find.text('Laporan'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Laba rugi'));
      await tester.pumpAndSettle();

      // Rentang bawaan: awal bulan sampai hari ini (26 Sep 2026).
      final minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/laporan/laba-rugi'));
      expect(minta.url.queryParameters['dari'], '2026-09-01');
      expect(minta.url.queryParameters['sampai'], '2026-09-26');

      expect(find.text('Rp 5.000.000'), findsWidgets);
      expect(find.text('Rp 2.000.000'), findsOneWidget);
      expect(find.text('Rp 750.000'), findsOneWidget);
      expect(find.text('+25% dari periode sebelumnya'), findsOneWidget);
      expect(find.text('Ke mana pendapatan pergi'), findsOneWidget);
      expect(find.byType(GrafikDonat), findsOneWidget, reason: 'D-236: donat HPP, beban, dan laba bersih.');
      expect(find.text('60%'), findsOneWidget, reason: 'HPP 3.000.000 dari pendapatan 5.000.000.');
      expect(find.text('25%'), findsOneWidget);
      expect(find.text('15%'), findsOneWidget);
      expect(find.text('Penjualan Barang'), findsOneWidget);
      expect(find.text('4-1000 | Pendapatan'), findsOneWidget);
      expect(find.text('6-2000 | Beban'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('tanpa izin keuangan: pesan galat server tampil, bukan layar kosong', (tester) async {
    server.penangan = (p) async {
      if (p.url.path.endsWith('/laporan/laba-rugi')) {
        return JsonUji({
          'Galat': {'Kode': 'TanpaIzin', 'Pesan': 'Anda tidak punya izin untuk laporan keuangan.'},
        }, 403);
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Laba rugi'));
    await tester.pumpAndSettle();
    expect(find.textContaining('tidak punya izin untuk laporan keuangan'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
