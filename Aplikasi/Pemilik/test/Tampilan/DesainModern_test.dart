import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/Komponen/KomponenModern.dart';

import '../Pendukung/PasangPemilik.dart';

/// D-231: bahasa visual modern Aplikasi Owner. Empat tab di bilah kapsul mengambang, ilustrasi merek di Masuk, Beranda,
/// Persetujuan kosong, dan Lainnya, serta tata letak yang muat di 360 dan 800 dp tanpa luapan.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p, {bool hanyaPerusahaan = false, bool adaPermintaan = false}) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'persetujuan' => JsonUji({
        'Persetujuan': adaPermintaan
            ? [
                {
                  'Uuid': 'A',
                  'Status': 'Menunggu',
                  'LabelStatus': 'Menunggu keputusan',
                  'Judul': 'Void penjualan',
                  'Rincian': <Object?>[],
                  'Nilai': '85000.00',
                  'NamaOutlet': 'Solo Baru',
                  'NamaPerangkat': 'Kasir 1',
                  'NamaPemohon': 'Sari',
                  'DibuatPada': '2026-09-26T07:58:00Z',
                  'KedaluwarsaPada': '2026-09-26T08:08:00Z',
                },
              ]
            : <Object?>[],
      }),
      'konfigurasi-aplikasi' when hanyaPerusahaan => JsonUji({
        'Aplikasi': {
          'Platform': 'Android',
          'VersiSaatIni': '1.0.0',
          'VersiTerbaru': '1.0.0',
          'VersiMinimal': '1.0.0',
          'AdaPembaruan': false,
          'WajibPembaruan': false,
        },
        'FlagFitur': <String, bool>{},
        'Sektor': ['BIZ-JSA'],
        'HanyaPerusahaan': true,
      }),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('layar Masuk memakai panel merek dengan ilustrasi payung', (tester) async {
    await PasangPemilik(tester, server: server, sesi: PenyimpanSesiMemori());

    expect(find.byKey(const ValueKey('IlustrasiPayung')), findsOneWidget);
    expect(find.text('Masuk ke Payoung Owner'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('bilah navigasi mengambang punya empat tab dan lencana jumlah persetujuan', (tester) async {
    server.penangan = (p) => Penangan(p, adaPermintaan: true);
    await PasangPemilik(tester, server: server, sesi: sesi);

    final bilah = find.byType(BilahNavigasiMengambang);
    expect(bilah, findsOneWidget);
    for (final label in ['Beranda', 'Laporan', 'Persetujuan', 'Lainnya']) {
      expect(
        find.descendant(of: bilah, matching: find.text(label)),
        findsOneWidget,
        reason: label,
      );
    }
    expect(
      find.descendant(of: bilah, matching: find.text('Shift')),
      findsNothing,
      reason: 'Shift pindah ke Lainnya.',
    );
    expect(find.descendant(of: bilah, matching: find.text('Perangkat')), findsNothing);
    expect(find.descendant(of: bilah, matching: find.widgetWithText(LencanaAngka, '1')), findsOneWidget);
  });

  testWidgets('Persetujuan kosong menampilkan ilustrasi papan persetujuan', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Persetujuan'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('IlustrasiPapanSetuju')), findsOneWidget);
    expect(find.text('Tidak ada permintaan persetujuan.'), findsOneWidget);
  });

  testWidgets('Lainnya: profil berilustrasi, empat aksi cepat, shift dan perangkat, bantuan, keluar', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('IlustrasiToko')), findsOneWidget);
    expect(find.byKey(const ValueKey('IlustrasiAvatar')), findsWidgets);
    for (final aksi in ['Ubah harga', 'Tandai habis', 'Promo', 'Catat pengeluaran']) {
      expect(find.text(aksi), findsOneWidget, reason: aksi);
    }
    // D-234: aksi cepat memakai ilustrasi mini, bukan ikon Material polos.
    for (final nama in ['IkonHarga', 'IkonHabis', 'IkonPromo', 'IkonPengeluaran']) {
      expect(find.byKey(ValueKey('Ilustrasi$nama')), findsOneWidget, reason: nama);
    }
    expect(find.text('Shift'), findsOneWidget);
    expect(find.text('Perangkat kasir'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Keluar'), 200);
    expect(find.text('Bantuan'), findsOneWidget);
    expect(find.text('Kunci aplikasi'), findsOneWidget);
    for (final nama in ['IkonKunci', 'IkonBantuan', 'IkonKeluar']) {
      expect(find.byKey(ValueKey('Ilustrasi$nama')), findsOneWidget, reason: nama);
    }
  });

  testWidgets('Beranda: ubin Transaksi, Rata-rata, dan Laba kotor memakai ilustrasi mini (D-234)', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    for (final nama in ['IkonTransaksi', 'IkonRataRata']) {
      expect(find.byKey(ValueKey('Ilustrasi$nama')), findsOneWidget, reason: nama);
    }
  });

  testWidgets('D-183: usaha tanpa kasir tidak punya shift, perangkat kasir, dan aksi cepat di Lainnya', (tester) async {
    server.penangan = (p) => Penangan(p, hanyaPerusahaan: true);
    await PasangPemilik(tester, server: server, sesi: sesi);
    await tester.tap(find.text('Lainnya'));
    await tester.pumpAndSettle();

    expect(find.text('Shift'), findsNothing);
    expect(find.text('Perangkat kasir'), findsNothing);
    expect(find.text('Pusat notifikasi'), findsOneWidget);
  });

  for (final ukuran in const [Size(360, 640), Size(800, 1000)]) {
    testWidgets('keempat tab muat di ${ukuran.width.toInt()} lebar tanpa luapan', (tester) async {
      server.penangan = (p) => Penangan(p, adaPermintaan: true);
      await PasangPemilik(tester, server: server, sesi: sesi, ukuran: ukuran);

      for (final tab in ['Beranda', 'Laporan', 'Persetujuan', 'Lainnya']) {
        await tester.tap(find.descendant(of: find.byType(BilahNavigasiMengambang), matching: find.text(tab)));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: 'Tab $tab');
      }
    });
  }
}
