import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// Aplikasi Owner v1 (OWN-01/02/05/08): masuk (2FA), pilih usaha, dasbor omzet + perbandingan + perlu tindakan,
/// laporan per kelompok, shift & selisih, status perangkat, sesi berakhir (401), dan keadaan offline.
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
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'laporan/penjualan' => JsonUji({
        'Kelompok': p.url.queryParameters['kelompok'],
        'Baris': [
          {'Nama': 'Es Kopi Susu Aren', 'Jumlah': '25', 'Omzet': '450000.00'},
        ],
        'Total': {'Jumlah': '25', 'Omzet': '450000.00'},
      }),
      'shift' => JsonUji({
        'Shift': [
          {
            'Uuid': 'S1',
            'Outlet': 'Solo Baru',
            'Kasir': 'Rina',
            'DibukaPada': '2026-09-26T01:00:00Z',
            'DitutupPada': '2026-09-26T08:00:00Z',
            'Status': 'Ditutup',
            'Selisih': '-20000.00',
          },
        ],
      }),
      'perangkat' => JsonUji({
        'Perangkat': [
          {
            'Uuid': 'P1',
            'Kode': 'SLB-K01',
            'Nama': 'Kasir Depan',
            'Jenis': 'Kasir',
            'Outlet': 'Solo Baru',
            'Status': 'Aktif',
            'TerakhirAktifPada': '2026-09-25T01:00:00Z',
            'JumlahOutboxTertunda': 3,
            'VersiAplikasi': '0.1.0',
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

  testWidgets('D-35: masuk ke server toko sendiri. Alamat tersimpan dan bertahan setelah keluar', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    await tester.tap(find.text('Toko memakai server sendiri?'));
    await tester.pump();
    await tester.enterText(find.byKey(const ValueKey('AlamatServer')), 'bukan alamat?x=1');
    await tester.enterText(find.widgetWithText(TextField, 'Email'), 'sari@contoh.id');
    await tester.enterText(find.widgetWithText(TextField, 'Kata sandi'), 'rahasia123');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pump();
    expect(find.text('Isi alamat server toko, misal https://kasir.tokoanda.com'), findsOneWidget);
    expect(server.permintaan, isEmpty);

    await tester.enterText(find.byKey(const ValueKey('AlamatServer')), 'kasir.tokoabc.id');
    await tester.tap(find.widgetWithText(FilledButton, 'Masuk'));
    await tester.pumpAndSettle();

    expect(server.permintaan.first.url.toString(), 'https://kasir.tokoabc.id/api/pemilik/v1/masuk');
    expect(sesi.isi[PenyimpanSesi.kunciAlamatServer], 'https://kasir.tokoabc.id/');

    await sesi.HapusSemua();
    expect(sesi.isi[PenyimpanSesi.kunciAlamatServer], 'https://kasir.tokoabc.id/');
  });

  for (final ukuran in const [Size(360, 740), Size(800, 1280)]) {
    testWidgets('masuk → dasbor: omzet, perbandingan, perlu tindakan, per outlet (${ukuran.width.toInt()} dp)', (
      tester,
    ) async {
      server.penangan = Penangan;
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);
      await Masuk(tester);

      final masuk = server.permintaan.firstWhere((p) => p.url.path.endsWith('/masuk'));
      expect(jsonDecode(masuk.body), containsPair('Email', 'sari@contoh.id'));
      expect(sesi.isi[PenyimpanSesi.kunciToken], '5|rahasia');
      expect(sesi.isi[PenyimpanSesi.kunciTenant], 'T1', reason: 'Satu usaha langsung dipilih.');

      expect(find.text('Kopi Senja'), findsOneWidget);
      expect(find.text('Rp 1.250.000'), findsOneWidget);
      expect(find.textContaining('+25% dari kemarin'), findsOneWidget);
      expect(find.textContaining('-17% dari minggu lalu'), findsOneWidget);
      await tester.scrollUntilVisible(find.text('Selisih kas shift Rina'), 200);
      expect(find.text('Selisih kas shift Rina'), findsOneWidget);
      final dasbor = server.permintaan.lastWhere((p) => p.url.path.endsWith('/dasbor'));
      expect(dasbor.headers['Authorization'], 'Bearer 5|rahasia');
      expect(dasbor.headers['X-Tenant'], 'T1');
      expect(dasbor.url.queryParameters['tanggal'], '2026-09-26');

      await tester.scrollUntilVisible(find.text('Per outlet'), 200);
      await tester.pumpAndSettle();
      expect(find.text('Kartasura'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('laporan per kategori, shift dengan selisih, perangkat lama tidak tersambung', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);

    await tester.tap(find.text('Laporan'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'Kategori'));
    await tester.pumpAndSettle();
    expect(server.permintaan.last.url.queryParameters['kelompok'], 'Kategori');
    expect(find.text('Total'), findsOneWidget);

    // D-231: shift dan perangkat kasir ada di tab Lainnya, dibuka sebagai halaman turunan.
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Shift'));
    await tester.pumpAndSettle();
    expect(find.text('Rina | Solo Baru'), findsOneWidget);
    expect(find.text('−Rp 20.000'), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Perangkat kasir'));
    await tester.pumpAndSettle();
    expect(find.textContaining('(lama tidak tersambung)'), findsOneWidget);
    expect(find.textContaining('3 data belum terkirim'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('OWN-08 cabut perangkat: konfirmasi lalu POST cabut, galat izin tampil sebagai pesan', (tester) async {
    var dicabut = false;
    server.penangan = (p) async {
      if (p.url.path.endsWith('/perangkat/P1/cabut')) {
        dicabut = true;
        return JsonUji({
          'Perangkat': {
            'Uuid': 'P1',
            'Kode': 'SLB-K01',
            'Nama': 'Kasir Depan',
            'Jenis': 'Kasir',
            'Outlet': 'Solo Baru',
            'Status': 'Dicabut',
            'TerakhirAktifPada': '2026-09-25T01:00:00Z',
            'JumlahOutboxTertunda': 3,
            'VersiAplikasi': '0.1.0',
          },
        });
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Perangkat kasir'));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Cabut perangkat'));
    await tester.pumpAndSettle();
    expect(find.text('Cabut "Kasir Depan"?'), findsOneWidget);
    await tester.tap(find.widgetWithText(TextButton, 'Batal'));
    await tester.pumpAndSettle();
    expect(dicabut, isFalse);

    await tester.tap(find.byTooltip('Cabut perangkat'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Ya, cabut'));
    await tester.pumpAndSettle();
    expect(dicabut, isTrue);
    expect(find.text('"Kasir Depan" dicabut.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('2FA: kode diminta setelah kata sandi. Banyak usaha → pilih usaha', (tester) async {
    server.penangan = (p) async {
      if (p.url.path.endsWith('/masuk')) {
        return JsonUji({'PerluDuaFaktor': true, 'TokenTantangan': 'tantangan-1'});
      }
      if (p.url.path.endsWith('/masuk/dua-faktor')) {
        return JsonUji({
          'Token': '9|rahasia',
          'Pengguna': {'Nama': 'Bu Sari'},
          'Tenant': [
            {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
            {'Uuid': 'T2', 'Nama': 'Laundry Bersih', 'Pemilik': false},
          ],
        });
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    expect(find.text('Verifikasi dua langkah'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'Kode verifikasi'), '123 456');
    await tester.tap(find.widgetWithText(FilledButton, 'Verifikasi'));
    await tester.pumpAndSettle();
    expect(jsonDecode(server.permintaan.last.body), {
      'TokenTantangan': 'tantangan-1',
      'Kode': '123456',
      'NamaPerangkat': 'Aplikasi Owner',
    });

    expect(find.text('Pilih usaha'), findsOneWidget);
    await tester.tap(find.text('Laundry Bersih'));
    await tester.pumpAndSettle();
    expect(sesi.isi[PenyimpanSesi.kunciTenant], 'T2');
    expect(find.text('Laundry Bersih'), findsOneWidget);
  });

  testWidgets('kata sandi salah tampil. Sesi tersimpan + 401 → kembali ke layar masuk', (tester) async {
    server.penangan = (p) async => p.url.path.endsWith('/masuk')
        ? JsonUji({
            'Galat': {'Kode': 'KredensialSalah', 'Pesan': 'Email atau kata sandi salah.'},
          }, 422)
        : JsonUji({'message': 'Unauthenticated.'}, 401);
    await PasangPemilik(tester, server: server, sesi: sesi);
    await Masuk(tester);
    expect(find.text('Email atau kata sandi salah.'), findsOneWidget);

    sesi.isi
      ..[PenyimpanSesi.kunciToken] = 'lama'
      ..[PenyimpanSesi.kunciTenant] = 'T1';
    // Aplikasi dibuka ulang dengan sesi tersimpan (ProviderScope baru).
    await tester.pumpWidget(const SizedBox());
    await PasangPemilik(tester, server: server, sesi: sesi);
    expect(find.text('Masuk ke Payoung Owner'), findsOneWidget);
    expect(find.text('Sesi berakhir. Masuk lagi.'), findsOneWidget);
    expect(sesi.isi, isEmpty);
  });

  testWidgets('offline: dasbor menampilkan pesan & Coba lagi', (tester) async {
    sesi.isi
      ..[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..[PenyimpanSesi.kunciTenant] = 'T1'
      ..[PenyimpanSesi.kunciNamaTenant] = 'Kopi Senja';
    var online = false;
    server.penangan = (p) async => online ? Penangan(p) : throw http.ClientException('offline');
    await PasangPemilik(tester, server: server, sesi: sesi);
    expect(find.textContaining('Tidak tersambung ke server'), findsOneWidget);

    online = true;
    await tester.tap(find.widgetWithText(OutlinedButton, 'Coba lagi'));
    await tester.pumpAndSettle();
    expect(find.text('Rp 1.250.000'), findsOneWidget);
  });
}
