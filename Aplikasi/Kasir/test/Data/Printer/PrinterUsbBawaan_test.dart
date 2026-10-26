import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/Printer/KanalBluetoothKlasik.dart';
import 'package:kasir/Data/Printer/KanalUsbPrinter.dart';
import 'package:kasir/Data/Printer/PemindaiPrinterPlatform.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';

/// PRD v1.96: printer USB (Android USB host lewat kanal Kotlin) dan printer bawaan POS all-in-one: Sunmi lewat printer
/// Bluetooth virtual "InnerPrinter", iMin & merek lain lewat USB internal.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const kanal = MethodChannel(KanalUsbPrinter.namaKanal);
  final panggilan = <MethodCall>[];

  void PasangKanal({required String produsen, List<Map<String, String>> usb = const []}) {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(kanal, (call) async {
      panggilan.add(call);
      return switch (call.method) {
        'InfoPerangkat' => {'Produsen': produsen, 'Model': 'D4-503', 'VersiAndroid': '11'},
        'Daftar' => usb,
        'Kirim' => null,
        _ => throw PlatformException(code: 'TidakDikenal'),
      };
    });
  }

  setUp(panggilan.clear);
  tearDown(
    () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(kanal, null),
  );

  test('Sunmi: printer bawaan = InnerPrinter Bluetooth (alamat tetap), tanpa perlu kabel', () async {
    PasangKanal(produsen: 'SUNMI');
    const pemindai = PemindaiPrinterPlatform();
    final hasil = await pemindai.CariPrinterBawaan();
    expect(hasil.single.alamat, PemindaiPrinterPlatform.alamatSunmi);
    expect(hasil.single.jenis, JenisTransport.SdkVendor);
    expect(hasil.single.nama, startsWith('Printer bawaan Sunmi'));

    final transport = pemindai.BuatTransport(
      const ProfilPrinter(jenis: JenisTransport.SdkVendor, alamat: PemindaiPrinterPlatform.alamatSunmi),
    );
    expect(transport, isA<TransportBluetoothKlasikAndroid>());
    expect((transport as TransportBluetoothKlasikAndroid).alamat, PemindaiPrinterPlatform.alamatInnerPrinterSunmi);
  });

  test('iMin: printer bawaan = perangkat USB internal. Cetak dikirim lewat kanal USB ke VID:PID', () async {
    PasangKanal(
      produsen: 'iMin',
      usb: [
        {'Nama': 'Printer USB 0519:2013', 'Alamat': '0519:2013'},
      ],
    );
    const pemindai = PemindaiPrinterPlatform();
    final hasil = await pemindai.CariPrinterBawaan();
    expect(hasil.single.alamat, 'usb:0519:2013');
    expect(hasil.single.nama, 'Printer bawaan iMin D4-503 (Printer USB 0519:2013)');

    final transport = pemindai.BuatTransport(
      ProfilPrinter(jenis: JenisTransport.SdkVendor, alamat: hasil.single.alamat),
    );
    await transport.Kirim([0x1B, 0x40]);
    final kirim = panggilan.lastWhere((c) => c.method == 'Kirim');
    expect((kirim.arguments as Map)['Alamat'], '0519:2013');
    expect((kirim.arguments as Map)['Data'], Uint8List.fromList([0x1B, 0x40]));
  });

  test('merek tak dikenal tanpa printer USB: daftar kosong (kasir memilih sambungan lain + uji perangkat)', () async {
    PasangKanal(produsen: 'Xiaomi');
    expect(await const PemindaiPrinterPlatform().CariPrinterBawaan(), isEmpty);
  });

  test('printer USB: daftar & galat kanal menjadi GalatPrinter berpesan', () async {
    PasangKanal(
      produsen: 'Samsung',
      usb: [
        {'Nama': 'EPPOS EP-58', 'Alamat': '0416:5011'},
      ],
    );
    final daftar = await const KanalUsbPrinter().Daftar();
    expect(daftar.single.jenis, JenisTransport.Usb);
    expect(daftar.single.nama, 'EPPOS EP-58');

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(
      kanal,
      (_) async => throw PlatformException(code: 'PrinterTidakDitemukan', message: 'Printer USB tidak tersambung.'),
    );
    await expectLater(
      const TransportUsbAndroid('0416:5011').Kirim([1]),
      throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', 'Printer USB tidak tersambung.')),
    );
  });
}
