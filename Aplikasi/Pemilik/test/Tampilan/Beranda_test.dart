import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:pemilik/Tampilan/Komponen/GrafikKomposisi.dart';
import 'package:pemilik/Tampilan/Komponen/KomponenModern.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-02 (D-56, D-231): Beranda Aplikasi Owner yang modern: sapaan, kartu omzet bermerek dengan kurva per jam, ubin
/// angka, kartu sorotan jam tersibuk, dan bagian berkartu (perlu tindakan dengan lencana jumlah, per outlet berbatang,
/// produk terlaris berperingkat).
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('sapaan, kartu omzet berkurva, ubin angka, sorotan jam tersibuk, dan bagian berkartu tampil', (
    tester,
  ) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.textContaining('Selamat sore'), findsOneWidget, reason: 'Sapaan mengikuti jam (15.00).');
    expect(find.text('Omzet'), findsOneWidget);
    expect(find.text('Rp 1.250.000'), findsOneWidget);
    expect(find.byType(GrafikLengkung), findsOneWidget, reason: 'Kurva omzet per jam di kartu omzet.');
    expect(
      find.bySemanticsLabel(RegExp('Omzet per jam: jam 9 Rp 100.000, jam 12 Rp 700.000, jam 15 Rp 450.000')),
      findsOneWidget,
      reason: 'Kurva punya label bacaan angka per jam untuk pembaca layar.',
    );
    expect(find.text('Transaksi'), findsOneWidget);
    expect(find.text('Rata-rata'), findsOneWidget);
    expect(find.text('Laba kotor'), findsOneWidget);
    expect(find.text('Rp 480.000'), findsOneWidget);

    await tester.scrollUntilVisible(find.text('Perlu tindakan'), 200);
    expect(find.text('Perlu tindakan'), findsOneWidget);
    expect(find.byType(LencanaAngka), findsOneWidget, reason: 'Lencana jumlah hal perlu tindakan.');

    await tester.scrollUntilVisible(find.text('Jam tersibuk: 12.00'), 200);
    expect(find.text('Es Kopi Susu Aren paling laku hari ini.'), findsOneWidget);
    expect(find.byKey(const ValueKey('IlustrasiToko')), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Omzet per jam'), 200);
    expect(find.byType(GrafikBatang), findsOneWidget, reason: 'D-236: batang omzet per jam berlabel jam.');
    expect(find.bySemanticsLabel(RegExp(r'Jam 12: Rp\s700\.000')), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Per outlet'), 200);
    expect(find.byType(GrafikDonat), findsOneWidget, reason: 'D-236: donat komposisi omzet per outlet.');
    expect(find.text('64%'), findsOneWidget, reason: 'Solo Baru 800.000 dari 1.250.000.');
    expect(find.text('Rp 800.000 | 30 transaksi'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Produk terlaris'), 200);
    expect(find.text('Es Kopi Susu Aren'), findsOneWidget);
    expect(find.text('25 terjual'), findsOneWidget);
    expect(find.text('1'), findsOneWidget, reason: 'Nomor peringkat produk teratas.');
    expect(tester.takeException(), isNull);
  });

  testWidgets('tanpa hal yang perlu tindakan: keadaan kosong menjelaskan apa yang akan muncul', (tester) async {
    server.penangan = (p) async {
      final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
      if (jalur == 'dasbor') {
        return JsonUji({...DasborUji(), 'PerluTindakan': <Object?>[]});
      }
      return Penangan(p);
    };
    await PasangPemilik(tester, server: server, sesi: sesi);

    await tester.scrollUntilVisible(find.text('Tidak ada yang perlu ditindaklanjuti.'), 200);
    expect(find.text('Tidak ada yang perlu ditindaklanjuti.'), findsOneWidget);
    expect(find.byType(KeadaanKosong), findsOneWidget);
    expect(find.byType(LencanaAngka), findsNothing);
  });
}
