import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

void main() {
  Future<void> Pasang(WidgetTester tester, Widget anak, {double lebar = 360}) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = Size(lebar, 200);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: BuatTema(),
        home: Scaffold(
          body: Align(alignment: Alignment.bottomCenter, child: anak),
        ),
      ),
    );
  }

  testWidgets('BilahStatus: status selalu berteks, warna ikon dari token nada, bisa diketuk', (tester) async {
    var diketuk = 0;
    await Pasang(
      tester,
      BilahStatus(
        saatDiketuk: () => diketuk++,
        item: const [
          ItemBilahStatus(ikon: Icons.wifi_off, teks: 'Offline', nada: NadaStatus.Peringatan),
          ItemBilahStatus(ikon: Icons.cloud_upload_outlined, teks: '12 belum terkirim', nada: NadaStatus.Peringatan),
          ItemBilahStatus(ikon: Icons.print_disabled_outlined, teks: 'Printer belum diatur'),
          ItemBilahStatus(ikon: Icons.schedule, teks: 'Shift 08.00'),
        ],
      ),
    );

    expect(tester.takeException(), isNull, reason: 'Tidak meluap di lebar 360dp. Teks panjang dipotong.');
    expect(find.text('Offline'), findsOneWidget);
    final ikon = tester.widget<Icon>(find.byIcon(Icons.wifi_off));
    expect(ikon.color, TokenWarna.bawaan.peringatan);
    expect(tester.getSize(find.byType(BilahStatus)).height, greaterThanOrEqualTo(TokenJarak.targetSentuh));

    await tester.tap(find.byType(BilahStatus));
    expect(diketuk, 1);
  });

  testWidgets('PanelTugas: judul, tombol Tutup & Esc menutup. Samping selebar 400, lembar selebar layar', (
    tester,
  ) async {
    var ditutup = 0;
    await Pasang(
      tester,
      PanelTugas(judul: 'Kas masuk', saatTutup: () => ditutup++, anak: const TextField(autofocus: true)),
      lebar: 1280,
    );
    expect(find.text('Kas masuk'), findsOneWidget);
    expect(tester.getSize(find.byType(PanelTugas)).width, PanelTugas.lebarSamping);

    await tester.tap(find.byTooltip('Tutup'));
    expect(ditutup, 1);

    await tester.pump();
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    expect(ditutup, 2);

    await Pasang(
      tester,
      PanelTugas(
        judul: 'Setoran',
        saatTutup: () {},
        tataLetak: TataLetakPanel.Lembar,
        anak: const SizedBox(height: 100),
      ),
    );
    expect(tester.getSize(find.byType(PanelTugas)).width, 360);
  });
}
