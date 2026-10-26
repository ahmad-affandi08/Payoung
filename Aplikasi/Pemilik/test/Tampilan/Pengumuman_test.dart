import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:pemilik/Data/PenyimpanSesi.dart';

import '../Pendukung/PasangPemilik.dart';

/// P-10 PGL-19 (v3.47): pengumuman platform tampil di atas Beranda Aplikasi Pemilik. Penting & Pemeliharaan tidak
/// bisa ditutup; Info & Yang baru bisa. Server lama tanpa rute pengumuman (404) tidak mengganggu Beranda.
void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;

  setUp(() {
    server = ServerTiruan();
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
  });

  Future<http.Response> Penangan(http.Request p, {bool adaPengumuman = true}) async {
    final jalur = p.url.path.replaceFirst('/api/pemilik/v1/', '');
    return switch (jalur) {
      'profil' => JsonUji({
        'Pengguna': {'Nama': 'Bu Sari'},
        'Tenant': [
          {'Uuid': 'T1', 'Nama': 'Kopi Senja', 'Pemilik': true},
        ],
      }),
      'dasbor' => JsonUji(DasborUji()),
      'pengumuman' when adaPengumuman => JsonUji({
        'Pengumuman': [
          {
            'Uuid': 'A1',
            'Judul': 'Pemeliharaan server Sabtu malam',
            'Isi': 'Sinkron berhenti sebentar. Transaksi tetap tersimpan di perangkat.',
            'Jenis': 'Pemeliharaan',
            'LabelJenis': 'Pemeliharaan terjadwal',
            'Tautan': null,
            'BolehDitutup': false,
            'PemeliharaanMulai': '2026-10-10T16:00:00Z',
            'PemeliharaanSelesai': '2026-10-10T18:00:00Z',
            'TampilSampai': '2026-10-10T18:00:00Z',
          },
          {
            'Uuid': 'A2',
            'Judul': 'Laporan per jam kini bisa diekspor',
            'Isi': 'Buka Laporan lalu pilih Ekspor.',
            'Jenis': 'YangBaru',
            'LabelJenis': 'Yang baru',
            'Tautan': 'https://payoung.id/blog/laporan-per-jam',
            'BolehDitutup': true,
            'PemeliharaanMulai': null,
            'PemeliharaanSelesai': null,
            'TampilSampai': '2026-10-12T00:00:00Z',
          },
        ],
      }),
      _ => JsonUji({}, 404),
    };
  }

  testWidgets('pengumuman tampil di atas Beranda; Yang baru bisa ditutup, Pemeliharaan tidak', (tester) async {
    server.penangan = Penangan;
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Pemeliharaan terjadwal: Pemeliharaan server Sabtu malam'), findsOneWidget);
    expect(find.textContaining('Jadwal: 10 Okt'), findsOneWidget);
    expect(find.text('Yang baru: Laporan per jam kini bisa diekspor'), findsOneWidget);
    expect(find.text('https://payoung.id/blog/laporan-per-jam'), findsOneWidget);
    expect(find.byTooltip('Tutup pengumuman'), findsOneWidget, reason: 'Hanya Yang baru yang bisa ditutup.');
    final minta = server.permintaan.lastWhere((p) => p.url.path.endsWith('/pengumuman'));
    expect(minta.headers['X-Tenant'], 'T1');

    await tester.tap(find.byTooltip('Tutup pengumuman'));
    await tester.pumpAndSettle();
    expect(find.text('Yang baru: Laporan per jam kini bisa diekspor'), findsNothing);
    expect(find.text('Pemeliharaan terjadwal: Pemeliharaan server Sabtu malam'), findsOneWidget);
    expect(find.text('Rp 1.250.000'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('server tanpa rute pengumuman: Beranda tetap tampil tanpa pengumuman', (tester) async {
    server.penangan = (p) => Penangan(p, adaPengumuman: false);
    await PasangPemilik(tester, server: server, sesi: sesi);

    expect(find.text('Rp 1.250.000'), findsOneWidget);
    expect(find.byTooltip('Tutup pengumuman'), findsNothing);
    expect(find.textContaining('Pemeliharaan'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
