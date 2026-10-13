import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import '../Pendukung/PasangPemilik.dart';

/// OWN-04 persetujuan PO & penyesuaian stok jarak jauh: kartu dokumen di tab Persetujuan (lencana ikut menghitung),
/// setujui dengan konfirmasi, tolak wajib alasan yang dikirim ke server, dokumen buatan sendiri tidak bisa disetujui
/// (360 dan 800 dp).
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori();
  });

  Future<http.Response> Penangan(http.Request p, List<Map<String, Object?>> po, List<Map<String, Object?>> penyesuaian) async {
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
    return switch (jalur) {
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': 'Produk',
        'Baris': <Object?>[],
        'Total': {'Jumlah': '0', 'Omzet': '0.00'},
      }),
      'shift' => JsonUji({'Shift': <Object?>[]}),
      'perangkat' => JsonUji({'Perangkat': <Object?>[]}),
      'persetujuan' => JsonUji({'Persetujuan': <Object?>[]}),
      'persetujuan-dokumen' => JsonUji({'PesananPembelian': po, 'PenyesuaianStok': penyesuaian}),
      _ when jalur.startsWith('persetujuan-dokumen/') => () {
        final bagian = jalur.split('/');
        final daftar = bagian[1] == 'pesanan-pembelian' ? po : penyesuaian;
        daftar.removeWhere((d) => d['Uuid'] == bagian[2]);
        return JsonUji({
          'Dokumen': {'Uuid': bagian[2], 'Nomor': 'X', 'Status': bagian[3] == 'setujui' ? 'Disetujui' : 'Draf'},
        });
      }(),
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
    testWidgets('setujui PO dan tolak penyesuaian stok (${ukuran.width.toInt()} dp)', (tester) async {
      final po = <Map<String, Object?>>[
        {
          'Uuid': 'PO1',
          'Nomor': 'PO/SLB/2610/0003',
          'NamaPemasok': 'PT Sumber Pangan Nusantara',
          'Tanggal': '2026-09-25',
          'JumlahBaris': 3,
          'Total': '6300000.00',
          'Catatan': 'Kirim sebelum Jumat',
          'DiajukanOleh': 'Dewi',
          'DiajukanPada': '2026-09-26T03:00:00Z',
          'BisaDisetujui': true,
        },
      ];
      final penyesuaian = <Map<String, Object?>>[
        {
          'Uuid': 'PS1',
          'Nomor': 'PS/SLB/2610/0001',
          'NamaGudang': 'Toko',
          'NamaOutlet': 'Solo Baru',
          'Alasan': 'Hilang',
          'Keterangan': 'Hilang saat bongkar muat',
          'JumlahBaris': 1,
          'NilaiPerkiraan': '500500.00',
          'DiajukanOleh': 'Bu Sari',
          'DiajukanPada': '2026-09-26T04:00:00Z',
          'BisaDisetujui': false,
        },
      ];
      server.penangan = (p) => Penangan(p, po, penyesuaian);
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      // Lencana hanya menghitung dokumen yang bisa disetujui pengguna.
      expect(find.widgetWithText(Badge, '1'), findsOneWidget);
      await tester.tap(find.text('Persetujuan'));
      await tester.pumpAndSettle();
      expect(find.text('PO PT Sumber Pangan Nusantara'), findsOneWidget);
      expect(find.text('Rp 6.300.000'), findsOneWidget);
      expect(find.text('Penyesuaian stok | Hilang'), findsOneWidget);
      expect(find.text('Dokumen ini Anda buat sendiri, jadi harus disetujui orang lain.'), findsOneWidget);

      // Penyesuaian buatan sendiri: kedua tombol mati.
      final tombol = tester.widgetList<FilledButton>(find.widgetWithText(FilledButton, 'Setujui')).toList();
      expect(tombol.where((t) => t.onPressed == null), hasLength(1));

      await tester.tap(find.widgetWithText(FilledButton, 'Setujui').first);
      await tester.pumpAndSettle();
      expect(find.text('Setujui PO PT Sumber Pangan Nusantara?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'Ya, setujui'));
      await tester.pumpAndSettle();
      final minta = server.permintaan.lastWhere((p) => p.url.path.contains('persetujuan-dokumen/'));
      expect(minta.url.path, endsWith('/persetujuan-dokumen/pesanan-pembelian/PO1/setujui'));
      expect(find.text('PO PT Sumber Pangan Nusantara'), findsNothing);
      expect(jsonDecode(minta.body.isEmpty ? '{}' : minta.body), isA<Map<String, Object?>>());
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('tolak dokumen mengirim alasan ke server', (tester) async {
    final po = <Map<String, Object?>>[
      {
        'Uuid': 'PO2',
        'Nomor': 'PO/SLB/2610/0004',
        'NamaPemasok': 'CV Mitra Gula',
        'Tanggal': '2026-09-25',
        'JumlahBaris': 1,
        'Total': '2100000.00',
        'Catatan': null,
        'DiajukanOleh': 'Dewi',
        'DiajukanPada': '2026-09-26T03:00:00Z',
        'BisaDisetujui': true,
      },
    ];
    server.penangan = (p) => Penangan(p, po, []);
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.tap(find.text('Persetujuan'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(OutlinedButton, 'Tolak'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('AlasanTolak')), 'Harga terlalu tinggi');
    await tester.tap(find.widgetWithText(FilledButton, 'Tolak'));
    await tester.pumpAndSettle();

    final minta = server.permintaan.lastWhere((p) => p.url.path.contains('persetujuan-dokumen/'));
    expect(minta.url.path, endsWith('/pesanan-pembelian/PO2/tolak'));
    expect(jsonDecode(minta.body), {'Alasan': 'Harga terlalu tinggi'});
    expect(find.text('CV Mitra Gula'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
