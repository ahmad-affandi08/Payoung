import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Pembaruan/PemasangPembaruan.dart';
import 'package:kasir/Tampilan/LayarJual.dart';
import 'package:kasir/Tampilan/RuangKerja/BannerPengumuman.dart';
import 'package:kasir/Tampilan/RuangKerja/PanelWajibPembaruan.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Pemasang tiruan Windows: mencatat (tautan, checksum) yang diminta tanpa mengunduh apa pun.
class PemasangTiruan implements PemasangPembaruan {
  PemasangTiruan(this.dipasang);

  final List<(String, String)> dipasang;

  @override
  bool get bisaPasangSendiri => true;

  @override
  Future<void> UnduhDanPasang({
    required String tautan,
    required String sha256,
    void Function(TahapPembaruan tahap, double? bagian)? saatKemajuan,
  }) async => dipasang.add((tautan, sha256));
}

/// P-10 (§14.6): aplikasi membaca `konfigurasi-aplikasi` setelah sinkron berhasil, melaporkan jumlah outbox tertunda
/// (`X-Outbox-Tertunda`), menandai versi baru di bilah status, dan mengunci layar jual bila di bawah versi minimal
/// sambil tetap mengirim outbox.
void main() {
  Future<LingkunganUji> MasukDenganKonfigurasi(
    WidgetTester tester,
    Map<String, Object?> aplikasi, {
    List<Map<String, Object?>> pengumuman = const [],
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.namaOutlet, 'Kopi Senja Solo Baru');
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (permintaan) async {
      final jalur = permintaan.url.path;
      if (jalur.endsWith('/sinkron/kirim')) {
        final item = (jsonDecode(permintaan.body) as Map<String, Object?>)['Item']! as List<Object?>;
        return JsonUji({
          'Hasil': [
            for (final i in item.cast<Map<String, Object?>>())
              {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
        });
      }
      if (jalur.endsWith('/konfigurasi-aplikasi')) {
        return JsonUji({
          'Aplikasi': aplikasi,
          'FlagFitur': {'pos.mode-meja': false},
          'Pengumuman': pengumuman,
        });
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u);
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    expect(find.byType(RuangKerja), findsOneWidget);
    await Tunggu(tester, const Duration(milliseconds: 600));
    return u;
  }

  testWidgets('wajib perbarui: layar jual diganti panel perbarui, outbox tetap terkirim, header outbox dilaporkan', (
    tester,
  ) async {
    final u = await MasukDenganKonfigurasi(tester, {
      'VersiSaatIni': '0.1.0',
      'VersiTerbaru': '0.2.0',
      'VersiMinimal': '0.2.0',
      'TautanUnduh': 'https://unduh.payoung.id/payoung-kasir-0.2.0.apk',
      'CatatanRilis': 'Cetak struk Bluetooth.',
      'AdaPembaruan': true,
      'WajibPembaruan': true,
    });

    expect(find.byType(PanelWajibPembaruan), findsOneWidget);
    expect(find.byType(LayarJual), findsNothing);
    expect(find.text('Wajib perbarui aplikasi'), findsOneWidget);
    expect(find.textContaining('Perbarui ke versi 0.2.0'), findsOneWidget);
    expect(find.text('https://unduh.payoung.id/payoung-kasir-0.2.0.apk'), findsOneWidget);
    expect(find.text('Cetak struk Bluetooth.'), findsOneWidget);
    // Buka shift sudah terkirim (outbox tidak ditahan) dan jumlah outbox dilaporkan di setiap permintaan.
    final kirim = u.server.permintaan.where((p) => p.url.path.endsWith('/sinkron/kirim')).toList();
    expect(kirim, isNotEmpty);
    expect(kirim.first.headers['X-Outbox-Tertunda'], '1');
    final konfigurasi = u.server.permintaan.lastWhere((p) => p.url.path.endsWith('/konfigurasi-aplikasi'));
    expect(konfigurasi.headers['X-Outbox-Tertunda'], '0');
    expect(find.textContaining('Semua transaksi sudah terkirim'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('versi baru tersedia (tidak wajib): bilah status memberi tahu, layar jual tetap bisa dipakai', (
    tester,
  ) async {
    final u = await MasukDenganKonfigurasi(tester, {
      'VersiSaatIni': '0.1.0',
      'VersiTerbaru': '0.2.0',
      'VersiMinimal': '0.1.0',
      'AdaPembaruan': true,
      'WajibPembaruan': false,
    });

    expect(find.text('Versi 0.2.0 tersedia'), findsOneWidget);
    expect(find.byType(LayarJual), findsOneWidget);
    expect(find.byType(PanelWajibPembaruan), findsNothing);
    await Lepas(tester, u);
  });

  testWidgets(
    'P-10 PGL-19: banner pengumuman di atas area kerja; Penting tidak bisa ditutup, Info bisa; Lihat membuka semua',
    (tester) async {
      final u = await MasukDenganKonfigurasi(
        tester,
        {'VersiSaatIni': '0.2.0', 'VersiTerbaru': '0.2.0', 'VersiMinimal': '0.1.0'},
        pengumuman: [
          {
            'Uuid': 'P1',
            'Judul': 'Pemeliharaan server Sabtu malam',
            'Isi': 'Sinkron berhenti sebentar; transaksi tetap tersimpan di perangkat.',
            'Jenis': 'Pemeliharaan',
            'LabelJenis': 'Pemeliharaan terjadwal',
            'BolehDitutup': false,
            'PemeliharaanMulai': '2026-10-10T16:00:00Z',
            'PemeliharaanSelesai': '2026-10-10T18:00:00Z',
          },
          {
            'Uuid': 'P2',
            'Judul': 'Cetak ulang struk lebih cepat',
            'Isi': 'Tahan ubin riwayat untuk cetak ulang.',
            'Jenis': 'YangBaru',
            'LabelJenis': 'Yang baru',
            'BolehDitutup': true,
          },
        ],
      );

      expect(find.byType(BannerPengumuman), findsOneWidget);
      expect(find.textContaining('Pemeliharaan server Sabtu malam'), findsOneWidget);
      expect(find.byTooltip('Tutup pengumuman'), findsNothing);
      expect(find.byType(LayarJual), findsOneWidget);

      await tester.tap(find.text('Lihat (2)'));
      await tester.pumpAndSettle();
      expect(find.text('Pengumuman Payoung'), findsOneWidget);
      expect(find.text('Yang baru: Cetak ulang struk lebih cepat'), findsOneWidget);
      expect(find.textContaining('Sinkron berhenti sebentar'), findsOneWidget);
      await tester.tap(find.text('Tutup'));
      await tester.pumpAndSettle();
      await Lepas(tester, u);
    },
  );

  testWidgets('POS-23: Windows dengan checksum dari server menawarkan Unduh dan pasang (tanpa pemasang sendiri tetap tautan, test pertama)', (
    tester,
  ) async {
    final dipasang = <(String, String)>[];
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.namaOutlet, 'Kopi Senja Solo Baru');
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (permintaan) async {
      final jalur = permintaan.url.path;
      if (jalur.endsWith('/sinkron/kirim')) {
        final item = (jsonDecode(permintaan.body) as Map<String, Object?>)['Item']! as List<Object?>;
        return JsonUji({
          'Hasil': [
            for (final i in item.cast<Map<String, Object?>>())
              {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
        });
      }
      if (jalur.endsWith('/konfigurasi-aplikasi')) {
        return JsonUji({
          'Aplikasi': {
            'VersiSaatIni': '0.1.0',
            'VersiTerbaru': '0.2.0',
            'VersiMinimal': '0.2.0',
            'TautanUnduh': 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-0.2.0.exe',
            'Sha256Unduhan': 'a' * 64,
            'AdaPembaruan': true,
            'WajibPembaruan': true,
          },
          'FlagFitur': {'pos.mode-meja': false},
          'Pengumuman': <Object?>[],
        });
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, pemasangPembaruan: PemasangTiruan(dipasang));
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    await Tunggu(tester, const Duration(milliseconds: 600));

    expect(find.byType(PanelWajibPembaruan), findsOneWidget);
    expect(find.text('Salin tautan'), findsNothing, reason: 'Windows dengan checksum memasang sendiri.');
    await tester.tap(find.text('Unduh dan pasang'));
    await Tunggu(tester);
    expect(dipasang, [('https://unduh.payoung.id/Payoung-Kasir-Pemasang-0.2.0.exe', 'a' * 64)]);
    await Lepas(tester, u);
  });
}
