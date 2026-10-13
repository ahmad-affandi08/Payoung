import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// P-09: tombol Bantuan di Aplikasi Owner. Daftar tiket tenant dengan batas respons SLA jam kerja, buat tiket dengan
/// konteks layar asal, dan percakapan dengan balasan.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;
  late List<Map<String, Object?>> pesan;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
    pesan = [
      {
        'Uuid': 'P1',
        'JenisPengirim': 'Pengguna',
        'NamaPengirim': 'Bu Sari',
        'Isi': 'Laporan omzet beda dengan kasir.',
        'DibuatPada': '2026-09-24T02:00:00Z',
      },
      {
        'Uuid': 'P2',
        'JenisPengirim': 'Pengelola',
        'NamaPengirim': 'Dewi | Tim Dukungan',
        'Isi': 'Boleh kirim tanggal dan outletnya?',
        'DibuatPada': '2026-09-24T03:00:00Z',
      },
    ];
  });

  Map<String, Object?> Tiket({String status = 'Ditangani', bool lewatSla = false, List<Map<String, Object?>>? isi}) => {
    'Uuid': '01K5TIKET00000000000000001',
    'Nomor': 'TKT-2026-000012',
    'Judul': 'Laporan omzet tidak sesuai',
    'LabelKategori': 'Laporan',
    'LabelPrioritas': 'Normal',
    'Status': status,
    'LabelStatus': status == 'Ditutup' ? 'Ditutup' : 'Ditangani',
    'DibuatPada': '2026-09-24T02:00:00Z',
    'BatasSlaPada': '2026-09-24T10:00:00Z',
    'ResponsPertamaPada': null,
    'LewatSla': lewatSla,
    'SlaJamKerja': true,
    'BisaDibalas': status != 'Ditutup',
    'Pesan': isi ?? const <Map<String, Object?>>[],
  };

  Future<http.Response> Penangan(http.Request p) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch ((p.method, jalur)) {
      ('GET', 'profil') => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      ('GET', 'dasbor') => JsonUji(DasborUji()),
      ('GET', 'bantuan/tiket') => JsonUji({
        'Tiket': [Tiket()],
      }),
      ('POST', 'bantuan/tiket') => JsonUji({'Tiket': Tiket(status: 'Baru')}, 201),
      ('GET', 'bantuan/tiket/01K5TIKET00000000000000001') => JsonUji({'Tiket': Tiket(isi: pesan)}),
      ('POST', 'bantuan/tiket/01K5TIKET00000000000000001/balas') => () {
        pesan.add({
          'Uuid': 'P3',
          'JenisPengirim': 'Pengguna',
          'NamaPengirim': 'Bu Sari',
          'Isi': (jsonDecode(p.body) as Map<String, Object?>)['Isi'],
          'DibuatPada': '2026-09-24T04:00:00Z',
        });
        return JsonUji({'Tiket': Tiket(isi: pesan)});
      }(),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('tombol Bantuan membuka daftar tiket dengan status dan batas respons jam kerja', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    await tester.tap(find.byTooltip('Bantuan'));
    await tester.pumpAndSettle();

    expect(find.text('TKT-2026-000012 | Laporan omzet tidak sesuai'), findsOneWidget);
    expect(find.textContaining('respons paling lambat'), findsOneWidget);
    expect(find.textContaining('(jam kerja)'), findsOneWidget);
    expect(find.text('Buat tiket'), findsOneWidget);
  });

  testWidgets('buat tiket: kategori dipilih, isian diperiksa, dan layar asal ikut dikirim', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.byTooltip('Bantuan'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Buat tiket'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Kirim tiket'));
    await tester.tap(find.text('Kirim tiket'));
    await tester.pumpAndSettle();
    expect(find.text('Judul minimal 5 huruf.'), findsOneWidget);
    expect(server.permintaan.where((p) => p.method == 'POST'), isEmpty, reason: 'Isian kosong tidak boleh terkirim.');

    await tester.tap(find.widgetWithText(ChoiceChip, 'Laporan'));
    await tester.enterText(find.widgetWithText(TextField, 'Judul'), 'Laporan omzet tidak sesuai');
    await tester.enterText(
      find.widgetWithText(TextField, 'Apa yang terjadi?'),
      'Omzet kemarin di Owner beda dengan kasir.',
    );
    await tester.ensureVisible(find.text('Kirim tiket'));
    await tester.tap(find.text('Kirim tiket'));
    await tester.pumpAndSettle();

    final kirim = server.permintaan.singleWhere((p) => p.method == 'POST' && p.url.path.endsWith('/bantuan/tiket'));
    expect(jsonDecode(kirim.body), {
      'Kategori': 'Laporan',
      'Judul': 'Laporan omzet tidak sesuai',
      'Isi': 'Omzet kemarin di Owner beda dengan kasir.',
      'LayarAsal': 'Beranda',
    });
    expect(kirim.headers['X-Tenant'], 'T1');
    expect(find.text('Tiket TKT-2026-000012 terkirim.'), findsOneWidget);
  });

  testWidgets('percakapan menampilkan pesan tim dan pelapor; balasan terkirim dan muncul', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.byTooltip('Bantuan'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('TKT-2026-000012 | Laporan omzet tidak sesuai'));
    await tester.pumpAndSettle();

    expect(find.text('Boleh kirim tanggal dan outletnya?'), findsOneWidget);
    expect(find.textContaining('Dewi | Tim Dukungan'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'Balas'), 'Tanggal 23 Sep, outlet Solo Baru.');
    await tester.ensureVisible(find.text('Kirim balasan'));
    await tester.tap(find.text('Kirim balasan'));
    await tester.pumpAndSettle();

    expect(find.text('Tanggal 23 Sep, outlet Solo Baru.'), findsOneWidget);
  });
}
