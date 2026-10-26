import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/Printer/KanalBluetoothKlasik.dart';
import 'package:kasir/Data/Printer/TransportBle.dart';
import 'package:kasir/Domain/Struk/PemindaiPrinter.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';

/// Radio BLE tiruan: mencatat urutan panggilan & potongan yang ditulis.
class KlienBleTiruan implements KlienBle {
  KlienBleTiruan(this.karakteristik, {this.mtu = 185, this.gagalSambung = false, this.gagalTulisKe});

  final List<KarakteristikBle> karakteristik;
  final int mtu;
  final bool gagalSambung;
  final int? gagalTulisKe;
  final List<String> urutan = [];
  final List<(KarakteristikBle, Uint8List, bool)> tulisan = [];

  @override
  Future<String?> Siapkan() async => null;

  @override
  Future<List<PrinterDitemukan>> Pindai(Duration lama) async => const [];

  @override
  Future<void> Sambungkan(String idPerangkat) async {
    urutan.add('sambung');
    if (gagalSambung) {
      throw Exception('timeout');
    }
  }

  @override
  Future<List<KarakteristikBle>> AmbilKarakteristik(String idPerangkat) async => karakteristik;

  @override
  Future<int> MintaMtu(String idPerangkat, int mtu) async => this.mtu;

  @override
  Future<void> Tulis(String idPerangkat, KarakteristikBle k, Uint8List data, {required bool tanpaRespons}) async {
    if (gagalTulisKe != null && tulisan.length == gagalTulisKe) {
      throw Exception('GATT 133');
    }
    tulisan.add((k, Uint8List.fromList(data), tanpaRespons));
  }

  @override
  Future<void> Putuskan(String idPerangkat) async => urutan.add('putus');
}

KarakteristikBle K(String layanan, String uuid, {bool tulis = false, bool tanpaRespons = false}) =>
    KarakteristikBle(layanan: layanan, uuid: uuid, tulis: tulis, tulisTanpaRespons: tanpaRespons);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('TransportBle (PRD v1.80)', () {
    test('memilih karakteristik tulis layanan printer yang dikenal (18F0) walau ada karakteristik tulis lain', () {
      final dipilih = TransportBle.PilihKarakteristik([
        K('0000180a-0000-1000-8000-00805f9b34fb', '00002a29-0000-1000-8000-00805f9b34fb'),
        K('0000fee7-0000-1000-8000-00805f9b34fb', '0000fec7-0000-1000-8000-00805f9b34fb', tulis: true),
        K('000018f0-0000-1000-8000-00805f9b34fb', '00002af1-0000-1000-8000-00805f9b34fb', tanpaRespons: true),
      ]);
      expect(dipilih?.uuid, '00002af1-0000-1000-8000-00805f9b34fb');
      expect(TransportBle.PilihKarakteristik([K('180a', '2a29')]), isNull);
      expect(TransportBle.SamakanUuid('18F0'), '000018f0-0000-1000-8000-00805f9b34fb');
    });

    test('data dipotong sebesar MTU−3 (maks 180), tulis dengan respons diutamakan, lalu diputus', () async {
      final klien = KlienBleTiruan([
        K(
          '49535343-fe7d-4ae5-8fa9-9fafd205e455',
          '49535343-8841-43f4-a8d4-ecbe34729bb3',
          tulis: true,
          tanpaRespons: true,
        ),
      ], mtu: 100);
      final data = List<int>.generate(250, (i) => i % 256);
      await TransportBle('AA:BB', klien).Kirim(data);
      expect(klien.tulisan.map((t) => t.$2.length), [97, 97, 56]);
      expect(klien.tulisan.every((t) => !t.$3), isTrue);
      expect(klien.tulisan.expand((t) => t.$2).toList(), data);
      expect(klien.urutan, ['sambung', 'putus']);
      expect(TransportBle.HitungUkuranPotongan(23), 20);
      expect(TransportBle.HitungUkuranPotongan(517), 180);
    });

    test('hanya tulis tanpa respons → tiap potongan diberi jeda. Galat tulis → GalatPrinter & tetap diputus', () async {
      final tanpaRespons = KlienBleTiruan([K('18f0', '2af1', tanpaRespons: true)], mtu: 23);
      await TransportBle('X', tanpaRespons, jedaTanpaRespons: Duration.zero).Kirim(List.filled(45, 1));
      expect(tanpaRespons.tulisan.map((t) => (t.$2.length, t.$3)), [(20, true), (20, true), (5, true)]);

      final gagal = KlienBleTiruan([K('18f0', '2af1', tulis: true)], gagalTulisKe: 1);
      await expectLater(
        TransportBle('X', gagal).Kirim(List.filled(400, 1)),
        throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', contains('gagal terkirim'))),
      );
      expect(gagal.urutan.last, 'putus');

      await expectLater(
        TransportBle('X', KlienBleTiruan(const [], gagalSambung: true)).Kirim([1]),
        throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', contains('tidak tersambung'))),
      );
      await expectLater(
        TransportBle('X', KlienBleTiruan([K('180a', '2a29')])).Kirim([1]),
        throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', contains('bukan printer'))),
      );
    });
  });

  group('KanalBluetoothKlasik (Android, PRD v1.80)', () {
    const kanal = MethodChannel(KanalBluetoothKlasik.namaKanal);
    final panggilan = <MethodCall>[];
    Map<String, Object?> status = {'Didukung': true, 'Aktif': true, 'Izin': true};
    Object? Function(MethodCall)? tangani;

    setUp(() {
      panggilan.clear();
      status = {'Didukung': true, 'Aktif': true, 'Izin': true};
      tangani = null;
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(kanal, (call) async {
        panggilan.add(call);
        final khusus = tangani?.call(call);
        if (khusus != null) {
          return khusus;
        }
        return switch (call.method) {
          'Status' => status,
          'MintaIzin' => false,
          'DaftarTerpasang' => [
            {'Nama': 'RPP02N', 'Alamat': '66:22:11:AA:BB:CC'},
            {'Nama': null, 'Alamat': '00:11:22:33:44:55'},
          ],
          _ => null,
        };
      });
    });
    tearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(kanal, null),
    );

    test('Siapkan: tanpa Bluetooth / izin ditolak / Bluetooth mati → pesan jelas. Siap → null', () async {
      const k = KanalBluetoothKlasik();
      expect(await k.Siapkan(), isNull);
      status = {'Didukung': false};
      expect(await k.Siapkan(), contains('tidak punya Bluetooth'));
      status = {'Didukung': true, 'Aktif': true, 'Izin': false};
      expect(await k.Siapkan(), contains('Izin Perangkat di sekitar ditolak'));
      expect(panggilan.map((c) => c.method), contains('MintaIzin'));
      status = {'Didukung': true, 'Aktif': false, 'Izin': true};
      expect(await k.Siapkan(), contains('Bluetooth mati'));
    });

    test('daftar printer terpasang & kirim byte ke alamat MAC. Galat platform → GalatPrinter berpesan', () async {
      const k = KanalBluetoothKlasik();
      final daftar = await k.DaftarTerpasang();
      expect(daftar.map((p) => (p.nama, p.alamat, p.jenis)), [
        ('RPP02N', '66:22:11:AA:BB:CC', JenisTransport.BluetoothKlasik),
        ('00:11:22:33:44:55', '00:11:22:33:44:55', JenisTransport.BluetoothKlasik),
      ]);

      await const TransportBluetoothKlasikAndroid('66:22:11:AA:BB:CC').Kirim([0x1B, 0x40]);
      final kirim = panggilan.last;
      expect(kirim.method, 'Kirim');
      expect((kirim.arguments as Map<Object?, Object?>)['Alamat'], '66:22:11:AA:BB:CC');
      expect((kirim.arguments as Map<Object?, Object?>)['Data'], [0x1B, 0x40]);

      tangani = (call) => call.method == 'Kirim'
          ? throw PlatformException(code: 'GagalTersambung', message: 'Printer Bluetooth tidak tersambung.')
          : null;
      await expectLater(
        const TransportBluetoothKlasikAndroid('66:22:11:AA:BB:CC').Kirim([1]),
        throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', 'Printer Bluetooth tidak tersambung.')),
      );
    });
  });

  test('ProfilPrinter Bluetooth: jenis & nama tersimpan, label untuk kasir', () {
    const profil = ProfilPrinter(jenis: JenisTransport.BluetoothKlasik, alamat: '66:22:11:AA:BB:CC', nama: 'RPP02N');
    final kembali = ProfilPrinter.DariJson(profil.KeJson())!;
    expect(
      (kembali.jenis, kembali.alamat, kembali.nama),
      (JenisTransport.BluetoothKlasik, '66:22:11:AA:BB:CC', 'RPP02N'),
    );
    expect(kembali.label, 'Bluetooth RPP02N | 58 mm');
    expect(const ProfilPrinter(alamat: '192.168.1.50').label, 'LAN/Wi-Fi 192.168.1.50:9100 | 58 mm');
    expect(ProfilPrinter.DariJson({'Jenis': 'Merpati', 'Alamat': 'x', 'Port': 1}), isNull);
  });
}
