import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Aplikasi/KunciAplikasi.dart';
import 'package:pemilik/Data/KunciPerangkat.dart';
import 'package:pemilik/Data/PenyimpanSesi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/PasangPemilik.dart';

/// OWN-01: kunci aplikasi Owner dengan PIN 6 angka + biometrik. Sidik PIN, kartu ajakan di Beranda, memasang PIN,
/// terkunci saat dibuka dingin, salah lima kali memutus sesi, biometrik, dan kunci otomatis setelah di latar.
class BiometrikTiruan implements PenyediaBiometrik {
  BiometrikTiruan({this.tersedia = true, this.berhasil = true});

  final bool tersedia;
  bool berhasil;
  int dipanggil = 0;

  @override
  Future<bool> Tersedia() async => tersedia;

  @override
  Future<bool> Autentikasi(String alasan) async {
    dipanggil++;
    return berhasil;
  }
}

void main() {
  late ServerTiruan server;
  late PenyimpanSesiMemori sesi;
  late DateTime sekarang;

  setUp(() {
    server = ServerTiruan();
    sekarang = DateTime(2026, 9, 26, 15);
    sesi = PenyimpanSesiMemori()
      ..isi[PenyimpanSesi.kunciToken] = '5|rahasia'
      ..isi[PenyimpanSesi.kunciTenant] = 'T1';
    server.penangan = (p) async {
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
    };
  });

  void PasangPinTersimpan(String pin) {
    final garam = SidikPin.BuatGaram();
    sesi.isi[KunciPenyimpan.garam] = garam;
    sesi.isi[KunciPenyimpan.sidik] = SidikPin.Hitung(pin, garam);
  }

  Future<void> Ketik(WidgetTester tester, String pin) async {
    for (final angka in pin.split('')) {
      await tester.tap(find.descendant(of: find.byType(PapanPin), matching: find.text(angka)));
      await tester.pump();
    }
    await tester.pumpAndSettle();
  }

  /// Kartu ajakan ada di paling bawah Beranda supaya tidak menggeser angka omzet.
  Future<void> LihatKartu(WidgetTester tester) async {
    await tester.scrollUntilVisible(
      find.text('Amankan aplikasi dengan PIN'),
      400,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
  }

  Future<void> Pasang(WidgetTester tester, {BiometrikTiruan? biometrik}) => PasangPemilik(
    tester,
    server: server,
    sesi: sesi,
    jam: () => sekarang,
    tambahan: [penyediaBiometrik.overrideWithValue(biometrik ?? BiometrikTiruan(tersedia: false))],
  );

  group('SidikPin', () {
    test('PIN 6 angka saja yang sah', () {
      expect(SidikPin.PinSah('123456'), isTrue);
      expect(SidikPin.PinSah('012345'), isTrue, reason: 'Nol di depan sah.');
      expect(SidikPin.PinSah('12345'), isFalse);
      expect(SidikPin.PinSah('1234567'), isFalse);
      expect(SidikPin.PinSah('12345a'), isFalse);
      expect(SidikPin.PinSah(''), isFalse);
    });

    test('sidik bergantung pada garam; cocok hanya untuk PIN yang sama', () {
      final garam = SidikPin.BuatGaram();
      final sidik = SidikPin.Hitung('246810', garam);

      expect(SidikPin.Hitung('246810', garam), sidik);
      expect(SidikPin.Hitung('246810', SidikPin.BuatGaram()), isNot(sidik));
      expect(sidik, isNot(contains('246810')));
      expect(SidikPin.Cocok('246810', garam, sidik), isTrue);
      expect(SidikPin.Cocok('246811', garam, sidik), isFalse);
    });
  });

  testWidgets('belum ada PIN: Beranda menawarkan kartu pasang PIN; "Nanti" menyembunyikannya dan diingat', (
    tester,
  ) async {
    await Pasang(tester);

    await LihatKartu(tester);
    expect(find.text('Amankan aplikasi dengan PIN'), findsOneWidget);
    await tester.tap(find.text('Nanti'));
    await tester.pumpAndSettle();

    expect(find.text('Amankan aplikasi dengan PIN'), findsNothing);
    expect(sesi.isi[KunciPenyimpan.tawaranDitolak], '1');
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'memasang PIN dari kartu: dua kali ketik sama, tersimpan sebagai sidik (bukan PIN), aplikasi tetap terbuka',
    (tester) async {
      await Pasang(tester);

      await LihatKartu(tester);
      await tester.tap(find.text('Pasang PIN'));
      await tester.pumpAndSettle();
      expect(find.text('PIN baru'), findsOneWidget);
      await Ketik(tester, '135790');
      expect(find.text('Ulangi PIN'), findsOneWidget);
      await Ketik(tester, '135790');

      expect(find.text('Amankan aplikasi dengan PIN'), findsNothing);
      expect(find.text('Aplikasi terkunci'), findsNothing);
      expect(sesi.isi[KunciPenyimpan.sidik], isNotEmpty);
      expect(sesi.isi.values, isNot(contains('135790')));
      expect(SidikPin.Cocok('135790', sesi.isi[KunciPenyimpan.garam]!, sesi.isi[KunciPenyimpan.sidik]!), isTrue);
    },
  );

  testWidgets('dua PIN tidak sama: ditolak dan tidak ada yang tersimpan', (tester) async {
    await Pasang(tester);

    await LihatKartu(tester);
    await tester.tap(find.text('Pasang PIN'));
    await tester.pumpAndSettle();
    await Ketik(tester, '135790');
    await Ketik(tester, '135791');

    expect(find.text('Kedua PIN tidak sama. Coba lagi.'), findsOneWidget);
    expect(sesi.isi[KunciPenyimpan.sidik], isNull);
  });

  testWidgets('dibuka dingin dengan PIN tersimpan: layar kunci; PIN benar membuka Beranda', (tester) async {
    PasangPinTersimpan('246810');
    await Pasang(tester);

    expect(find.text('Aplikasi terkunci'), findsOneWidget);
    expect(find.text('Omzet'), findsNothing, reason: 'Data tidak boleh terlihat saat terkunci.');

    await Ketik(tester, '246810');

    expect(find.text('Aplikasi terkunci'), findsNothing);
    expect(find.text('Omzet'), findsOneWidget);
  });

  testWidgets('PIN salah: pesan sisa percobaan; lima kali salah memutus sesi dan menghapus token', (tester) async {
    PasangPinTersimpan('246810');
    await Pasang(tester);

    await Ketik(tester, '000001');
    expect(find.textContaining('Sisa 4 percobaan'), findsOneWidget);
    expect(sesi.isi[PenyimpanSesi.kunciToken], '5|rahasia');

    for (var i = 0; i < 4; i++) {
      await Ketik(tester, '000001');
    }

    expect(find.text('Aplikasi terkunci'), findsNothing);
    expect(sesi.isi[PenyimpanSesi.kunciToken], isNull, reason: 'Sesi dihapus bersama PIN.');
    expect(sesi.isi[KunciPenyimpan.sidik], isNull);
  });

  testWidgets('percobaan salah dihitung lintas peluncuran: PIN benar mengatur ulang hitungan', (tester) async {
    PasangPinTersimpan('246810');
    sesi.isi[KunciPenyimpan.gagal] = '3';
    await Pasang(tester);

    await Ketik(tester, '000001');
    expect(find.textContaining('Sisa 1 percobaan'), findsOneWidget);

    await Ketik(tester, '246810');
    expect(sesi.isi[KunciPenyimpan.gagal], '0');
  });

  testWidgets('"Lupa PIN" keluar dari aplikasi setelah konfirmasi', (tester) async {
    PasangPinTersimpan('246810');
    await Pasang(tester);

    await tester.tap(find.text('Lupa PIN?'));
    await tester.pumpAndSettle();
    expect(find.text('Lupa PIN?'), findsWidgets);
    await tester.tap(find.widgetWithText(FilledButton, 'Keluar'));
    await tester.pumpAndSettle();

    expect(find.text('Aplikasi terkunci'), findsNothing);
    expect(sesi.isi[PenyimpanSesi.kunciToken], isNull);
  });

  testWidgets('biometrik aktif: terbuka tanpa PIN; biometrik gagal: PIN tetap bisa dipakai', (tester) async {
    PasangPinTersimpan('246810');
    sesi.isi[KunciPenyimpan.biometrik] = '1';
    final biometrik = BiometrikTiruan(berhasil: false);
    await Pasang(tester, biometrik: biometrik);

    expect(biometrik.dipanggil, 1, reason: 'Ditawarkan otomatis sekali saat layar kunci muncul.');
    expect(find.text('Aplikasi terkunci'), findsOneWidget);
    expect(find.text('Buka dengan sidik jari atau wajah'), findsOneWidget);

    biometrik.berhasil = true;
    await tester.tap(find.text('Buka dengan sidik jari atau wajah'));
    await tester.pumpAndSettle();

    expect(find.text('Aplikasi terkunci'), findsNothing);
    expect(find.text('Omzet'), findsOneWidget);
  });

  testWidgets('biometrik tidak tersedia di perangkat: pengaturan biometrik diabaikan walau tersimpan', (tester) async {
    PasangPinTersimpan('246810');
    sesi.isi[KunciPenyimpan.biometrik] = '1';
    await Pasang(tester, biometrik: BiometrikTiruan(tersedia: false));

    expect(find.text('Buka dengan sidik jari atau wajah'), findsNothing);
  });

  testWidgets('di latar kurang dari semenit tetap terbuka; lebih dari semenit terkunci lagi', (tester) async {
    PasangPinTersimpan('246810');
    await Pasang(tester);
    await Ketik(tester, '246810');
    expect(find.text('Omzet'), findsOneWidget);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    sekarang = sekarang.add(const Duration(seconds: 30));
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('Aplikasi terkunci'), findsNothing);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    sekarang = sekarang.add(const Duration(minutes: 2));
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('Aplikasi terkunci'), findsOneWidget);
  });

  testWidgets('menu akun › Kunci aplikasi: matikan PIN wajib PIN saat ini', (tester) async {
    PasangPinTersimpan('246810');
    await Pasang(tester);
    await Ketik(tester, '246810');

    await tester.tap(find.byTooltip('Akun'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kunci aplikasi'));
    await tester.pumpAndSettle();
    expect(find.text('PIN aktif'), findsOneWidget);

    await tester.tap(find.text('Matikan PIN'));
    await tester.pumpAndSettle();
    await Ketik(tester, '111111');
    expect(find.text('PIN salah. Kunci tidak dimatikan.'), findsOneWidget);
    expect(sesi.isi[KunciPenyimpan.sidik], isNotEmpty);

    await tester.pumpAndSettle(const Duration(seconds: 5));
    await tester.tap(find.text('Matikan PIN'));
    await tester.pumpAndSettle();
    await Ketik(tester, '246810');
    expect(find.text('Pasang PIN'), findsOneWidget);
    expect(sesi.isi[KunciPenyimpan.sidik], isEmpty);
  });

  testWidgets('layar kunci muat di 360 dan 800 lebar tanpa luapan', (tester) async {
    PasangPinTersimpan('246810');
    for (final ukuran in const [Size(360, 640), Size(800, 1000)]) {
      await PasangPemilik(
        tester,
        server: server,
        sesi: sesi,
        ukuran: ukuran,
        tambahan: [penyediaBiometrik.overrideWithValue(BiometrikTiruan())],
      );
      expect(find.text('Aplikasi terkunci'), findsOneWidget);
      expect(tester.takeException(), isNull, reason: 'Luapan pada ${ukuran.width}dp.');
    }
  });
}
