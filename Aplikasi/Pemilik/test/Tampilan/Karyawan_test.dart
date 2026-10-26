import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-10: kartu "Karyawan hari ini" di Beranda dan layar Karyawan (kehadiran, belum masuk, target, komisi). Tanpa izin
/// `karyawan.lihat` (403) kartu tidak tampil dan Beranda tetap utuh.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p, {int statusKaryawan = 200}) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'karyawan' when statusKaryawan == 200 => JsonUji({
        'Tanggal': '2026-09-26',
        'Periode': '2026-09',
        'Ringkasan': {'Hadir': 1, 'SedangBekerja': 1, 'Terlambat': 1, 'BelumMasuk': 1},
        'Kehadiran': [
          {
            'NamaKaryawan': 'Rina',
            'NamaOutlet': 'Solo Baru',
            'Jadwal': '08:00–16:00',
            'JamMasuk': '08:20',
            'JamKeluar': null,
            'TerlambatMenit': 20,
            'Sumber': 'Web',
          },
        ],
        'BelumMasuk': [
          {'NamaKaryawan': 'Budi', 'NamaOutlet': 'Solo Baru', 'Jadwal': '09:00–16:00'},
        ],
        'Komisi': [
          {'NamaKaryawan': 'Rina', 'Komisi': '150000.00'},
        ],
        'Target': [
          {
            'LabelCakupan': 'Outlet',
            'NamaSasaran': 'Solo Baru',
            'Nilai': '10000000.00',
            'Realisasi': '4000000.00',
            'Persen': '40.0',
            'Proyeksi': '9500000.00',
          },
        ],
      }),
      'karyawan' => JsonUji({
        'Galat': {'Kode': 'TanpaIzin', 'Pesan': 'Tidak diizinkan.'},
      }, statusKaryawan),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('kartu Beranda meringkas kehadiran. Ketuk membuka layar Karyawan', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Karyawan hari ini'), findsOneWidget);
    expect(find.text('1 hadir | 1 terlambat | 1 belum masuk'), findsOneWidget);

    await tester.tap(find.text('Karyawan hari ini'));
    await tester.pumpAndSettle();

    expect(find.text('Budi'), findsOneWidget);
    expect(find.text('Jadwal 09:00–16:00 | Solo Baru'), findsOneWidget);
    expect(find.text('Masuk 08:20, masih bekerja | terlambat 20 menit | dari HP | Solo Baru'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Komisi bulan ini'), 200);
    expect(find.textContaining('(40,0%)'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
    await tester.scrollUntilVisible(find.textContaining('150.000'), 200);
    expect(find.textContaining('150.000'), findsOneWidget);
  });

  testWidgets('tanpa izin karyawan.lihat (403): kartu tidak tampil, Beranda tetap', (tester) async {
    server.penangan = (p) => Penangan(p, statusKaryawan: 403);
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Omzet'), findsOneWidget);
    expect(find.text('Karyawan hari ini'), findsNothing);
  });
}
