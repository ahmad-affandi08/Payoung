import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/Komponen/KomponenModern.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-03 / X4 persetujuan jarak jauh di Aplikasi Owner: lencana jumlah menunggu, kartu permintaan (judul, nilai,
/// outlet | perangkat | pemohon, rincian), setujui dengan konfirmasi, tolak wajib alasan ≥ 5 huruf, galat server
/// (sudah diputuskan/kedaluwarsa) tampil sebagai pesan; di 360 dan 800 dp.

/// Lencana jumlah menunggu di bilah navigasi bawah (Beranda punya lencana "perlu tindakan" sendiri).
Finder LencanaNav(String jumlah) =>
    find.descendant(of: find.byType(BilahNavigasiMengambang), matching: find.widgetWithText(LencanaAngka, jumlah));

void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Map<String, Object?> Permintaan(String uuid, String judul, {String? nilai}) => {
    'Uuid': uuid,
    'Status': 'Menunggu',
    'LabelStatus': 'Menunggu keputusan',
    'Judul': judul,
    'Rincian': [
      {'Label': 'Keterangan', 'Nilai': 'Kas keluar ini di atas batas. Pilih supervisor yang menyetujui.'},
      {'Label': 'Catatan', 'Nilai': 'Galon 12 + es balok 4'},
    ],
    'Nilai': nilai,
    'NamaOutlet': 'Solo Baru',
    'NamaPerangkat': 'Kasir Depan',
    'NamaPemohon': 'Rina Wulandari',
    'DibuatPada': '2026-09-26T07:58:00Z',
    'KedaluwarsaPada': '2026-09-26T08:08:00Z',
  };

  Future<http.Response> Penangan(http.Request p, List<Map<String, Object?>> antrean) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    if (jalur == 'masuk') {
      return JsonUji({
        'Token': '5|rahasia',
        'Pengguna': {'Uuid': 'U1', 'Nama': 'Bu Sari', 'Email': 'sari@contoh.id'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      });
    }
    if (jalur == 'dasbor') {
      return JsonUji(DasborUji());
    }
    if (jalur == 'laporan/penjualan') {
      return JsonUji({
        'Kelompok': 'Produk',
        'Baris': <Object?>[],
        'Total': {'Jumlah': '0', 'Omzet': '0.00'},
      });
    }
    if (jalur == 'shift') {
      return JsonUji({'Shift': <Object?>[]});
    }
    if (jalur == 'perangkat') {
      return JsonUji({'Perangkat': <Object?>[]});
    }
    if (jalur == 'persetujuan') {
      return JsonUji({'Persetujuan': antrean});
    }
    if (jalur.endsWith('/setujui')) {
      final uuid = jalur.split('/')[1];
      if (uuid == 'KEDALUWARSA') {
        return JsonUji({
          'Galat': {'Kode': 'SudahKedaluwarsa', 'Pesan': 'Permintaan ini sudah kedaluwarsa. Kasir perlu meminta lagi.'},
        }, 409);
      }
      antrean.removeWhere((a) => a['Uuid'] == uuid);
      return JsonUji({
        'Persetujuan': {...Permintaan(uuid, 'x'), 'Status': 'Disetujui'},
      });
    }
    if (jalur.endsWith('/tolak')) {
      final uuid = jalur.split('/')[1];
      antrean.removeWhere((a) => a['Uuid'] == uuid);
      return JsonUji({
        'Persetujuan': {...Permintaan(uuid, 'x'), 'Status': 'Ditolak'},
      });
    }
    return JsonUji({}, 404);
  }

  Future<void> Masuk(WidgetTester tester) async {
    await tester.enterText(find.widgetWithText(TextField, 'Email'), 'sari@contoh.id');
    await tester.enterText(find.widgetWithText(TextField, 'Kata sandi'), 'rahasia123');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pumpAndSettle();
  }

  for (final ukuran in const [Size(360, 740), Size(800, 1280)]) {
    testWidgets('lencana, setujui dengan konfirmasi, tolak dengan alasan (${ukuran.width.toInt()} dp)', (tester) async {
      final antrean = [
        Permintaan('KAS1', 'Kas keluar di atas batas', nilai: '350000.00'),
        Permintaan('DSK1', 'Diskon di atas batas'),
      ];
      server.penangan = (p) => Penangan(p, antrean);
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);
      expect(sesi.isi[PenyimpanSesi.kunciTenant], 'T1');

      // Lencana jumlah menunggu di navigasi bawah.
      expect(LencanaNav('2'), findsOneWidget);
      await tester.tap(find.text('Persetujuan'));
      await tester.pumpAndSettle();
      expect(find.text('Kas keluar di atas batas'), findsOneWidget);
      expect(find.text('Rp 350.000'), findsOneWidget);
      expect(find.textContaining('Solo Baru | Kasir Depan | Rina Wulandari'), findsWidgets);
      expect(find.textContaining('Galon 12 + es balok 4'), findsWidgets);

      await tester.ensureVisible(find.widgetWithText(FilledButton, 'Setujui').first);
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Setujui').first);
      await tester.pumpAndSettle();
      expect(find.text('Setujui "Kas keluar di atas batas"?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Ya, setujui'));
      await tester.pumpAndSettle();
      expect(server.permintaan.any((p) => p.url.path.endsWith('/persetujuan/KAS1/setujui')), isTrue);
      expect(find.text('Kas keluar di atas batas'), findsNothing);
      expect(LencanaNav('1'), findsOneWidget);

      // Pesan hasil (snackbar melayang) menutup kartu berikutnya sampai hilang sendiri.
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(OutlinedButton, 'Tolak'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('AlasanTolak')), 'no');
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();
      expect(find.text('Tulis alasan minimal 5 huruf agar kasir tahu.'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('AlasanTolak')), 'Diskon terlalu besar untuk hari biasa');
      await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
      await tester.pumpAndSettle();

      final tolak = server.permintaan.lastWhere((p) => p.url.path.endsWith('/tolak'));
      expect(jsonDecode(tolak.body), {'Alasan': 'Diskon terlalu besar untuk hari biasa'});
      expect(tolak.headers['X-Tenant'], 'T1');
      expect(find.textContaining('Tidak ada permintaan persetujuan'), findsOneWidget);
      expect(find.byType(Badge), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('permintaan kedaluwarsa: pesan server tampil dan antrean dimuat ulang', (tester) async {
    final antrean = [Permintaan('KEDALUWARSA', 'Penjualan tempo Toko Makmur Jaya', nilai: '2750000.00')];
    server.penangan = (p) => Penangan(p, antrean);
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.tap(find.text('Persetujuan'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.widgetWithText(FilledButton, 'Setujui'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Setujui'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Ya, setujui'));
    await tester.pumpAndSettle();
    expect(find.text('Permintaan ini sudah kedaluwarsa. Kasir perlu meminta lagi.'), findsOneWidget);
    expect(server.permintaan.where((p) => p.url.path.endsWith('/persetujuan')).length, greaterThanOrEqualTo(2));
  });
}
