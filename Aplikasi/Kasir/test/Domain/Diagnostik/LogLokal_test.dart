import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/Diagnostik/LogLokal.dart';

import '../../Pendukung/LingkunganUji.dart';

/// K-21: log lokal tersaring dari data pribadi, diputar saat besar, galat tertunda dikirim sekali ke server.
void main() {
  late Directory folder;
  late DateTime jam;

  setUp(() {
    folder = Directory.systemTemp.createTempSync('log-kasir-');
    jam = DateTime.utc(2026, 10, 2, 3);
  });
  tearDown(() => folder.deleteSync(recursive: true));

  test('penyaring menyamarkan HP, email, NIK, kartu, token; angka pendek tetap', () {
    expect(
      LogLokal.SaringPii('WA 0812-3456-7890 ke rina@contoh.id NIK 3372011234567890 Bearer abc.def kode 500 baris 42'),
      'WA [disamarkan] ke [disamarkan] NIK [disamarkan] Bearer [disamarkan] kode 500 baris 42',
    );
  });

  test('catat, baca terbaru dulu, putar berkas saat melewati batas', () async {
    final log = LogLokal(folder: folder, jam: () => jam, ukuranMaksimal: 400);
    for (var i = 0; i < 6; i++) {
      jam = jam.add(const Duration(minutes: 1));
      await log.Catat(TingkatLog.galat, 'Uji', 'Galat ke-$i saat mencetak struk 0812 3456 7890');
    }
    final terbaru = await log.AmbilTerbaru(batas: 3);
    expect(terbaru.map((e) => e.pesan), [
      'Galat ke-5 saat mencetak struk [disamarkan]',
      'Galat ke-4 saat mencetak struk [disamarkan]',
      'Galat ke-3 saat mencetak struk [disamarkan]',
    ]);
    expect(File('${folder.path}/log/kasir.1.log').existsSync(), isTrue, reason: 'Berkas diputar saat > 400 bait.');
  });

  test('kirim tertunda: hanya galat/peringatan, sekali saja; offline = dicoba lagi', () async {
    final u = LingkunganUji.Buat();
    addTearDown(u.Tutup);
    await u.SiapkanAktif();
    final log = LogLokal(folder: folder, jam: () => jam);
    await log.Catat(TingkatLog.info, 'Sinkron', 'Mulai sinkron');
    jam = jam.add(const Duration(seconds: 1));
    await log.Catat(TingkatLog.galat, 'Flutter', StateError('Keranjang kosong'), jejak: StackTrace.fromString('#0 a'));
    jam = jam.add(const Duration(seconds: 1));
    await log.Catat(TingkatLog.peringatan, 'Printer', 'Printer tidak menjawab');

    u.server.penangan = (_) async => throw http.ClientException('offline');
    expect(await log.KirimTertunda(u.klien), 0);
    expect(await log.AmbilBelumTerkirim(), hasLength(2));

    final dikirim = <Map<String, Object?>>[];
    u.server.penangan = (p) async {
      dikirim.add(jsonDecode(p.body) as Map<String, Object?>);
      return JsonUji({'Diterima': 2});
    };
    expect(await log.KirimTertunda(u.klien), 2);
    final galat = (dikirim.single['Galat']! as List<Object?>).cast<Map<String, Object?>>();
    expect(galat.map((g) => g['Sumber']), ['Flutter', 'Printer']);
    expect(galat.first['Pesan'], 'Bad state: Keranjang kosong');
    expect(await log.KirimTertunda(u.klien), 0, reason: 'Sudah terkirim tidak dikirim ulang.');
    expect(dikirim, hasLength(1));
  });

  test(
    'PGL-16 bagian 2: unggah atas permintaan mengirim semua tingkat, terlama dulu, tersaring dari data pribadi',
    () async {
      final u = LingkunganUji.Buat();
      addTearDown(u.Tutup);
      await u.SiapkanAktif();
      final log = LogLokal(folder: folder, jam: () => jam);
      await log.Catat(TingkatLog.info, 'Sinkron', 'Mulai sinkron untuk rina@contoh.id');
      jam = jam.add(const Duration(seconds: 1));
      await log.Catat(
        TingkatLog.galat,
        'Printer',
        'Printer 0812 3456 7890 tidak menjawab',
        jejak: StackTrace.fromString('#0 a\n#1 b'),
      );

      final dikirim = <Map<String, Object?>>[];
      u.server.penangan = (p) async {
        dikirim.add(jsonDecode(p.body) as Map<String, Object?>);
        return JsonUji({'Diterima': 2});
      };
      expect(await log.UnggahAtasPermintaan(u.klien), 2);

      final baris = (dikirim.single['Baris']! as List<Object?>).cast<String>();
      expect(baris, hasLength(2));
      expect(baris.first, contains('[Info] Sinkron: Mulai sinkron untuk [disamarkan]'));
      expect(baris.last, contains('[Galat] Printer: Printer [disamarkan] tidak menjawab | #0 a'));
      expect(baris.join(), isNot(contains('rina@contoh.id')));
      expect(baris.join(), isNot(contains('3456')));
    },
  );

  test('PGL-16 bagian 2: unggah offline atau permintaan sudah terpenuhi = 0 tanpa melempar; log kosong tetap mengirim satu baris', () async {
    final u = LingkunganUji.Buat();
    addTearDown(u.Tutup);
    await u.SiapkanAktif();
    final log = LogLokal(folder: folder, jam: () => jam);

    u.server.penangan = (_) async => throw http.ClientException('offline');
    expect(await log.UnggahAtasPermintaan(u.klien), 0);

    u.server.penangan = (_) async => JsonUji({
      'Galat': {
        'Kode': 'LogTidakDiminta',
        'Pesan': 'Tidak ada permintaan unggah log untuk perangkat ini.',
        'Detail': <String, Object?>{},
      },
    }, 409);
    expect(await log.UnggahAtasPermintaan(u.klien), 0);

    final dikirim = <Map<String, Object?>>[];
    u.server.penangan = (p) async {
      dikirim.add(jsonDecode(p.body) as Map<String, Object?>);
      return JsonUji({'Diterima': 1});
    };
    expect(await log.UnggahAtasPermintaan(u.klien), 1);
    expect((dikirim.single['Baris']! as List<Object?>).single, contains('log lokal masih kosong'));
  });
}
