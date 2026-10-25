import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';

/// F-11: shift lama perangkat masih terbuka di server (aplikasi dipasang ulang / tidak pernah ditutup) sehingga
/// `Shift.Buka` baru ditolak `ShiftSudahTerbuka`. Layar Sinkron menjelaskannya dan supervisor bisa menutup shift lama
/// dengan PIN; sesudah itu data yang tertahan dikirim ulang otomatis.
void main() {
  const uuidShiftLama = '01K5SHIFTLAMA0000000000001';
  const uuidBudi = '01K5STAF000000000000000002';

  for (final ukuran in const [Size(1280, 900), Size(360, 740)]) {
    testWidgets(
      'banner shift lama → alasan + PIN supervisor → tutup paksa → data tertahan terkirim (${ukuran.width.toInt()} dp)',
      (tester) async {
        final u = LingkunganUji.Buat();
        var shiftLamaDitutup = false;
        // Sinkron otomatis (masuk kasir, pemicu K-6) tidak boleh mendahului ketukan "Kirim sekarang": server sinkron baru
        // menjawab sesudah tombol diketuk, sehingga penolakan pertama tampil sebagai dialog hasil.
        var serverSinkronSiap = false;
        final tutupPaksa = <Map<String, Object?>>[];
        await tester.runAsync(() async {
          await u.SiapkanAktif();
          await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
        });
        u.server.penangan = (p) async {
          final jalur = p.url.path;
          if (jalur.endsWith('/data-awal')) {
            return JsonUji(DataAwalUji());
          }
          if (jalur.endsWith('/katalog')) {
            return JsonUji(KatalogUji());
          }
          if (jalur.endsWith('/shift/terbuka')) {
            return JsonUji({
              'Shift': shiftLamaDitutup
                  ? <Object?>[]
                  : [
                      {
                        'Uuid': uuidShiftLama,
                        'Status': 'Terbuka',
                        'DibukaPada': '2026-10-06T02:00:00Z',
                        'NamaKasir': 'Rina Wulandari',
                        'KasAwal': '250000.00',
                      },
                    ],
            });
          }
          if (jalur.endsWith('/tutup-paksa')) {
            tutupPaksa.add(jsonDecode(p.body) as Map<String, Object?>);
            shiftLamaDitutup = true;
            return JsonUji({'Uuid': uuidShiftLama, 'Status': 'Tertutup'});
          }
          if (jalur.endsWith('/sinkron/kirim')) {
            if (!serverSinkronSiap) {
              throw http.ClientException('offline');
            }
            final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
                .cast<Map<String, Object?>>();
            return JsonUji({
              'Hasil': [
                for (final i in item)
                  if (!shiftLamaDitutup && i['Jenis'] == 'Shift.Buka')
                    {
                      'Uuid': i['Uuid'],
                      'Jenis': i['Jenis'],
                      'Status': 'Ditolak',
                      'Galat': {
                        'Kode': 'ShiftSudahTerbuka',
                        'Pesan':
                            'Perangkat ini masih punya shift terbuka. Tutup shift itu dulu sebelum membuka shift baru.',
                      },
                    }
                  else
                    {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
              ],
              'WaktuServer': u.jam.toUtc().toIso8601String(),
              'PerangkatDicabut': false,
              'PerluTinjauan': <Object?>[],
            });
          }
          throw http.ClientException('offline');
        };
        await PasangAplikasi(tester, u, ukuran: ukuran);
        await Tunggu(tester, const Duration(milliseconds: 600));
        await PilihKasir(tester, 'Rina Wulandari');
        await tester.pump();
        await KetikPin(tester, KasusPin(0)['Pin']! as String);
        await Tunggu(tester);
        expect(find.byType(RuangKerja), findsOneWidget);

        await tester.tap(find.text('Sinkron'));
        await Tunggu(tester);
        expect(find.text('Status sinkron'), findsOneWidget);
        serverSinkronSiap = true;
        await tester.tap(find.widgetWithText(FilledButton, 'Kirim sekarang'));
        await Tunggu(tester, const Duration(milliseconds: 900));
        // Hasil kirim yang ditolak server tampil sebagai dialog yang harus diakui.
        expect(find.byKey(const ValueKey('DialogHasilGagal')), findsOneWidget);
        expect(find.text('1 data ditolak server'), findsOneWidget);
        await tester.tap(find.widgetWithText(FilledButton, 'Mengerti'));
        await Tunggu(tester);

        final daftar = find.byType(Scrollable).last;
        await tester.scrollUntilVisible(find.byKey(const ValueKey('BannerShiftLama')), 200, scrollable: daftar);
        expect(find.byKey(const ValueKey('BannerShiftLama')), findsOneWidget);
        expect(find.textContaining('masih punya shift terbuka'), findsWidgets);

        await tester.tap(find.byKey(const ValueKey('TutupShiftLama')));
        await Tunggu(tester);
        expect(find.text('Tutup shift lama di server'), findsOneWidget);
        expect(find.textContaining('Rina Wulandari | dibuka'), findsOneWidget);

        // Alasan wajib.
        await tester.tap(find.byKey(const ValueKey('LanjutTutupShiftLama')));
        await tester.pump();
        expect(find.text('Tulis alasan minimal 5 huruf.'), findsOneWidget);
        await tester.enterText(find.byKey(const ValueKey('AlasanShiftLama')), 'Sisa uji coba');
        await tester.tap(find.byKey(const ValueKey('LanjutTutupShiftLama')));
        await Tunggu(tester);

        // PIN supervisor (Budi punya shift.selisih.setujui; Rina tidak).
        expect(find.text('Persetujuan tutup shift lama'), findsOneWidget);
        expect(find.widgetWithText(OutlinedButton, 'Rina Wulandari'), findsNothing);
        await PilihPenyetuju(tester, 'Budi Santoso');
        await KetikPin(tester, KasusPin(1)['Pin']! as String);
        await Tunggu(tester, const Duration(seconds: 1));

        expect(tutupPaksa, hasLength(1));
        expect(tutupPaksa.single['UuidPenyetuju'], uuidBudi);
        expect(tutupPaksa.single['Alasan'], 'Sisa uji coba');
        expect(
          find.byKey(const ValueKey('BannerShiftLama')),
          findsNothing,
          reason: 'Buka shift tertahan terkirim ulang.',
        );
        final tersisa = await tester.runAsync(() => u.db.select(u.db.outbox).get());
        expect(tersisa, isEmpty);
        expect(tester.takeException(), isNull);
        await Lepas(tester, u);
      },
    );
  }
}
