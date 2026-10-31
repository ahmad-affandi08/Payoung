import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// P-10 PGL-18: Aplikasi Owner membaca versi terbaru/minimal dan flag fitur dari `konfigurasi-aplikasi`. Di bawah
/// versi minimal Beranda diganti layar "Perbarui aplikasi"; versi baru (belum wajib) hanya mengingatkan; flag `Global`
/// mati (kill switch) menyembunyikan fitur; server lama tanpa rute (404) tidak mengganggu apa pun.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Map<String, Object?> Konfigurasi({
    String saatIni = '1.0.0',
    String terbaru = '1.0.0',
    String minimal = '1.0.0',
    Map<String, bool> flag = const {},
    List<String>? sektor,
    bool hanyaPerusahaan = false,
  }) => {
    'Aplikasi': {
      'Platform': 'Android',
      'VersiSaatIni': saatIni,
      'VersiTerbaru': terbaru,
      'VersiMinimal': minimal,
      'TautanUnduh': 'https://unduh.payoung.id/pemilik-1.4.0',
      'CatatanRilis': 'Laporan laba rugi lebih cepat',
      'AdaPembaruan': saatIni != terbaru,
      'WajibPembaruan': saatIni != minimal && minimal.compareTo(saatIni) > 0,
    },
    'FlagFitur': flag,
    'Sektor': ?sektor,
    'HanyaPerusahaan': hanyaPerusahaan,
  };

  Future<http.Response> Penangan(http.Request p, {Map<String, Object?>? konfigurasi}) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'konfigurasi-aplikasi' when konfigurasi != null => JsonUji(konfigurasi),
      'laporan/penjualan' => JsonUji({
        'Kelompok': 'Produk',
        'Baris': <Object?>[],
        'Total': {'Jumlah': '0', 'Omzet': '0.00'},
      }),
      'karyawan' => JsonUji({
        'Tanggal': '2026-09-26',
        'Periode': '2026-09',
        'Ringkasan': {'Hadir': 1, 'SedangBekerja': 1, 'Terlambat': 0, 'BelumMasuk': 0},
        'Kehadiran': <Object?>[],
        'BelumMasuk': <Object?>[],
        'Komisi': <Object?>[],
        'Target': <Object?>[],
      }),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('di bawah versi minimal: layar Perbarui aplikasi menggantikan Beranda, dengan tautan & catatan rilis', (
    tester,
  ) async {
    server.penangan = (p) => Penangan(
      p,
      konfigurasi: Konfigurasi(saatIni: '1.0.0', terbaru: '1.4.0', minimal: '1.2.0'),
    );
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Perbarui aplikasi'), findsOneWidget);
    expect(find.textContaining('Perbarui ke versi 1.2.0'), findsOneWidget);
    expect(find.text('https://unduh.payoung.id/pemilik-1.4.0'), findsOneWidget);
    expect(find.text('Laporan laba rugi lebih cepat'), findsOneWidget);
    expect(find.text('Rp 1.250.000'), findsNothing, reason: 'Beranda tidak boleh bisa dipakai.');
    final minta = server.permintaan.firstWhere((p) => p.url.path.endsWith('/konfigurasi-aplikasi'));
    expect(minta.headers['X-Versi-Aplikasi'], '1.0.1');
    expect(minta.headers['X-Tenant'], 'T1');
    expect(minta.headers['X-Platform-Aplikasi'], 'Android');
  });

  testWidgets('versi baru tersedia tetapi belum wajib: kartu Pembaruan di Beranda, bisa ditutup', (tester) async {
    server.penangan = (p) => Penangan(
      p,
      konfigurasi: Konfigurasi(saatIni: '1.0.0', terbaru: '1.4.0', minimal: '1.0.0'),
    );
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Rp 1.250.000'), findsOneWidget);
    expect(find.text('Pembaruan: versi 1.4.0 tersedia'), findsOneWidget);
    expect(find.text('Perbarui aplikasi'), findsNothing);

    await tester.tap(find.byTooltip('Tutup pembaruan'));
    await tester.pumpAndSettle();
    expect(find.text('Pembaruan: versi 1.4.0 tersedia'), findsNothing);
    expect(find.text('Rp 1.250.000'), findsOneWidget);
  });

  testWidgets('kill switch pemilik.karyawan: kartu Karyawan hari ini hilang, tanpa flag tetap tampil', (tester) async {
    server.penangan = (p) => Penangan(p, konfigurasi: Konfigurasi(flag: {'pemilik.karyawan': false}));
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Rp 1.250.000'), findsOneWidget);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    expect(find.text('Karyawan hari ini'), findsNothing);
  });

  testWidgets('flag tidak mematikan fitur lain: pemilik.karyawan hidup = baris Karyawan tampil', (tester) async {
    server.penangan = (p) => Penangan(p, konfigurasi: Konfigurasi(flag: {'pemilik.insight': false}));
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Karyawan hari ini'), 200);
    expect(find.text('Karyawan hari ini'), findsOneWidget);
  });

  testWidgets('server lama tanpa konfigurasi-aplikasi (404): aplikasi tetap jalan penuh', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Rp 1.250.000'), findsOneWidget);
    expect(find.text('Perbarui aplikasi'), findsNothing);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Karyawan hari ini'), 200);
    expect(find.text('Karyawan hari ini'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('D-183: usaha jasa profesional tanpa kasir & stok: Laporan tanpa mode Anti-fraud dan Stok', (
    tester,
  ) async {
    server.penangan = (p) => Penangan(p, konfigurasi: Konfigurasi(sektor: ['BIZ-JSA'], hanyaPerusahaan: true));
    await PasangPemilik(tester, server: server, sesi: sesi);

    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('Laporan-Penjualan')), findsOneWidget);
    expect(find.byKey(const ValueKey('Laporan-LabaRugi')), findsOneWidget);
    expect(find.byKey(const ValueKey('Laporan-AntiFraud')), findsNothing);
    expect(find.byKey(const ValueKey('Laporan-Stok')), findsNothing);
  });

  testWidgets('D-183: jasa umum berkasir tanpa stok: Anti-fraud tampil, Stok tidak', (tester) async {
    server.penangan = (p) => Penangan(p, konfigurasi: Konfigurasi(sektor: ['SVC-GEN']));
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('Laporan-AntiFraud')), findsOneWidget);
    expect(find.byKey(const ValueKey('Laporan-Stok')), findsNothing);
  });

  testWidgets('D-183: restoran melihat mode Anti-fraud dan Stok', (tester) async {
    server.penangan = (p) => Penangan(p, konfigurasi: Konfigurasi(sektor: ['FNB-RST']));
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('Laporan-AntiFraud')), findsOneWidget);
    expect(find.byKey(const ValueKey('Laporan-Stok')), findsOneWidget);
  });
}
