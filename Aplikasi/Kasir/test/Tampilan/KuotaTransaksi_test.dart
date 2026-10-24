import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Penjualan/KuotaTransaksiEfektif.dart';
import 'package:kasir/Tampilan/LayarJual.dart';
import 'package:kasir/Tampilan/RuangKerja/PanelKuotaTransaksiHabis.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// P-04: kuota transaksi penjualan per bulan di Ruang Kerja. Hampir habis = peringatan di bilah status; habis = layar
/// jual diganti panel, tetapi layar lain tetap bisa dipakai. Data kuota bulan lain atau paket tanpa batas tidak
/// mengunci apa pun.
void main() {
  Future<LingkunganUji> MasukDenganKuota(WidgetTester tester, Map<String, Object?>? transaksi) async {
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
          'Aplikasi': {'VersiSaatIni': '0.2.0', 'VersiTerbaru': '0.2.0', 'VersiMinimal': '0.1.0'},
          'FlagFitur': {'pos.mode-meja': false},
          'Kuota': {'Transaksi': ?transaksi},
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

  Map<String, Object?> Kuota(LingkunganUji u, {required int terpakai, int? batas = 500, String? periode}) => {
    'Periode': periode ?? KuotaTransaksiEfektif.Periode(u.jam),
    'Terpakai': terpakai,
    'Batas': batas,
    'Status': 'Normal',
  };

  testWidgets('kuota habis: layar jual diganti panel, bilah status memberi tahu, tidak ada tombol bayar', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    await u.Tutup();
    final lingkungan = await MasukDenganKuota(tester, Kuota(u, terpakai: 500));

    expect(find.byType(PanelKuotaTransaksiHabis), findsOneWidget);
    expect(find.byType(LayarJual), findsNothing);
    expect(find.text('Kuota transaksi habis'), findsOneWidget);
    expect(find.textContaining('mencakup 500 transaksi per bulan'), findsOneWidget);
    expect(find.text('Cek ulang'), findsOneWidget);
    await Lepas(tester, lingkungan);
  });

  testWidgets('kuota hampir habis: layar jual tetap bisa dipakai, bilah status menyebut sisa', (tester) async {
    final u = LingkunganUji.Buat();
    await u.Tutup();
    final lingkungan = await MasukDenganKuota(tester, Kuota(u, terpakai: 430));

    expect(find.byType(LayarJual), findsOneWidget);
    expect(find.byType(PanelKuotaTransaksiHabis), findsNothing);
    expect(find.text('Sisa 70 transaksi bulan ini'), findsOneWidget);
    await Lepas(tester, lingkungan);
  });

  testWidgets('kuota masih longgar, paket tanpa batas, atau data bulan lain: tidak ada peringatan dan tidak terkunci', (
    tester,
  ) async {
    final u = LingkunganUji.Buat();
    await u.Tutup();

    for (final data in [
      (terpakai: 10, batas: 500 as int?, periode: null as String?),
      (terpakai: 9999, batas: null, periode: null),
      (terpakai: 500, batas: 500, periode: '2020-01'),
    ]) {
      final lingkungan = await MasukDenganKuota(
        tester,
        Kuota(u, terpakai: data.terpakai, batas: data.batas, periode: data.periode),
      );
      expect(find.byType(LayarJual), findsOneWidget, reason: '$data');
      expect(find.byType(PanelKuotaTransaksiHabis), findsNothing, reason: '$data');
      expect(find.textContaining('transaksi bulan ini'), findsNothing, reason: '$data');
      await Lepas(tester, lingkungan);
    }
  });

  testWidgets('server lama tanpa Kuota: tidak terkunci', (tester) async {
    final lingkungan = await MasukDenganKuota(tester, null);

    expect(find.byType(LayarJual), findsOneWidget);
    expect(find.byType(PanelKuotaTransaksiHabis), findsNothing);
    await Lepas(tester, lingkungan);
  });
}
