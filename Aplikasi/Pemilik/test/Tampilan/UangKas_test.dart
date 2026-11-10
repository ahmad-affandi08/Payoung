import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/FormatTampilan.dart';

import '../Pendukung/PasangPemilik.dart';

/// D-255 uang masuk dan keluar di Aplikasi Owner: kartu "Uang bulan ini" di Beranda (bulan berjalan sampai hari ini,
/// perubahan dalam kata), mengetuknya membuka Laporan di mode Uang dengan rincian per kelompok, kartu tersembunyi bila
/// server menolak (tanpa izin atau di luar paket), dan kalimat perubahannya (360 dan 800 dp).
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Map<String, Object?> Uang({String cocok = 'true'}) => {
    'Periode': {
      'Dari': '2026-09-01',
      'Sampai': '2026-09-26',
      'DariSebelumnya': '2026-08-01',
      'SampaiSebelumnya': '2026-08-26',
      'Pembanding': 'BulanLalu',
      'Hari': 26,
    },
    'Ringkasan': {
      'Masuk': '134300000.00',
      'Keluar': '102915000.00',
      'Selisih': '31385000.00',
      'MasukSebelumnya': '128900000.00',
      'KeluarSebelumnya': '99600000.00',
      'SelisihSebelumnya': '29300000.00',
      'PerubahanMasuk': '4.2',
      'PerubahanKeluar': '3.3',
      'PerubahanSelisih': '7.1',
      'DiLuarUsaha': '0.00',
      'PerubahanKas': '31385000.00',
    },
    'KelompokMasuk': [
      {'Kelompok': 'Penjualan', 'Nilai': '126650000.00', 'NilaiSebelumnya': '120200000.00', 'Porsi': '94.3'},
      {'Kelompok': 'Pelunasan piutang', 'Nilai': '7650000.00', 'NilaiSebelumnya': '8700000.00', 'Porsi': '5.7'},
    ],
    'KelompokKeluar': [
      {'Kelompok': 'Belanja stok', 'Nilai': '78000000.00', 'NilaiSebelumnya': '75000000.00', 'Porsi': '75.8'},
      {'Kelompok': 'Gaji', 'Nilai': '24915000.00', 'NilaiSebelumnya': '24600000.00', 'Porsi': '24.2'},
    ],
    'Kecocokan': {'Cocok': cocok == 'true', 'Selisih': cocok == 'true' ? '0.00' : '150000.00'},
    'AdaAkunKas': true,
  };

  Future<http.Response> Penangan(http.Request p, {Map<String, Object?>? uang}) async {
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
      'laporan/pemasukan-pengeluaran' => JsonUji(uang ?? Uang()),
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
    testWidgets('kartu Beranda memakai bulan berjalan, lalu membuka Laporan mode Uang (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      // Bulan berjalan sampai hari ini (26 Sep 2026), apa pun rentang yang dipilih di Laporan.
      final minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/laporan/pemasukan-pengeluaran'));
      expect(minta.url.queryParameters['dari'], '2026-09-01');
      expect(minta.url.queryParameters['sampai'], '2026-09-26');

      await tester.scrollUntilVisible(find.text('Uang bulan ini'), 200, scrollable: find.byType(Scrollable).first);
      expect(find.text('Uang bulan ini'), findsOneWidget);
      expect(find.text('Rp 134.300.000'), findsOneWidget);
      expect(find.text('Rp 102.915.000'), findsOneWidget);
      expect(find.text('Rp 31.385.000'), findsOneWidget);
      expect(find.text('naik 4,2% dari bulan lalu'), findsOneWidget);
      expect(find.text('naik 3,3% dari bulan lalu'), findsOneWidget);
      expect(find.text('naik 7,1% dari bulan lalu'), findsOneWidget);

      await tester.tap(find.text('Uang bulan ini'));
      await tester.pumpAndSettle();

      // Pindah ke tab Laporan dengan mode Uang terpilih, lengkap dengan rincian per kelompok.
      expect(find.text('Uang masuk dari mana'), findsOneWidget);
      expect(find.text('Uang keluar ke mana'), findsOneWidget);
      expect(find.text('Belanja stok'), findsOneWidget);
      expect(find.text('75,8% dari total'), findsOneWidget);
      expect(find.text('Pelunasan piutang'), findsOneWidget);
      expect(find.text('Periode 2026-09-01 s.d. 2026-09-26'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('selisih yang belum cocok dengan saldo kas diberi catatan berteks', (tester) async {
    server.penangan = (p) => Penangan(p, uang: Uang(cocok: 'false'));
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.scrollUntilVisible(find.text('Uang bulan ini'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.textContaining('Belum cocok dengan saldo kas dan bank: selisih Rp 150.000'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('tanpa izin atau di luar paket: kartu tidak tampil dan Beranda tetap utuh', (tester) async {
    server.penangan = (p) async {
      if (p.url.path.endsWith('/laporan/pemasukan-pengeluaran')) {
        return JsonUji({
          'Galat': {'Kode': 'TanpaIzin', 'Pesan': 'Anda tidak punya izin untuk laporan keuangan.'},
        }, 403);
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    expect(find.text('Uang bulan ini'), findsNothing);
    expect(find.textContaining('tidak punya izin'), findsNothing);
    expect(find.text('Perlu tindakan', skipOffstage: false), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  test('kalimat perubahan: naik, turun, sama, dan tanpa pembanding', () {
    expect(FormatTampilan.KalimatPerubahan('4.2', 'BulanLalu'), 'naik 4,2% dari bulan lalu');
    expect(FormatTampilan.KalimatPerubahan('-3.3', 'MingguLalu'), 'turun 3,3% dari minggu lalu');
    expect(FormatTampilan.KalimatPerubahan('12.0', 'Kemarin'), 'naik 12% dari kemarin');
    expect(FormatTampilan.KalimatPerubahan('0.0', 'TahunLalu'), 'sama seperti tahun lalu');
    expect(FormatTampilan.KalimatPerubahan('5.0', 'PeriodeSebelumnya'), 'naik 5% dari periode sebelumnya');
    expect(FormatTampilan.KalimatPerubahan(null, 'BulanLalu'), isNull);
  });
}
