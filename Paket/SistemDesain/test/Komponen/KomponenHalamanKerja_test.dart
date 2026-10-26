import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// D-40: komponen halaman kerja non-Jual (angka utama, keadaan kosong, batang perbandingan, grafik per jam).
void main() {
  Future<void> Pasang(WidgetTester tester, Widget anak, {double lebar = 360}) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = Size(lebar, 600);
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: BuatTema(),
        home: Scaffold(body: SingleChildScrollView(child: anak)),
      ),
    );
  }

  testWidgets('SorotanAngka: label, angka besar, keterangan. Nada mewarnai angka saja', (tester) async {
    await Pasang(
      tester,
      const SorotanAngka(
        label: 'Belum terkirim ke server',
        nilai: Text('4 data belum terkirim.'),
        keterangan: 'Offline',
        nada: NadaStatus.Peringatan,
      ),
    );
    expect(find.text('Belum terkirim ke server'), findsOneWidget);
    expect(find.text('Offline'), findsOneWidget);
    final gaya = DefaultTextStyle.of(tester.element(find.text('4 data belum terkirim.'))).style;
    expect(gaya.color, TokenWarna.bawaan.peringatan);
    expect(gaya.fontSize, SkalaTipografi.nyaman.tampilan.ukuran);
    expect(tester.takeException(), isNull);
  });

  testWidgets('KeadaanKosong: ilustrasi + judul + keterangan + aksi, tidak meluap di 360dp', (tester) async {
    var ditekan = 0;
    await Pasang(
      tester,
      KeadaanKosong(
        ikon: Icons.receipt_long_outlined,
        judul: 'Struk pertama hari ini akan muncul di sini',
        keterangan: 'Setiap transaksi yang selesai di kasir ini tampil lengkap.',
        aksi: FilledButton(onPressed: () => ditekan++, child: const Text('Mulai jual')),
      ),
    );
    // Bentuk penuh menampilkan ilustrasi merek (D-68, D-85); ikon hanya cadangan bila aset gagal dimuat.
    expect(find.byKey(const ValueKey('IlustrasiKosong')), findsOneWidget);
    await tester.tap(find.text('Mulai jual'));
    expect(ditekan, 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('BatangProporsi & GrafikBatang: angka tertulis, rasio dipotong 0–1, batang nol tetap tergambar', (
    tester,
  ) async {
    await Pasang(
      tester,
      const Column(
        children: [
          BatangProporsi(label: 'Tunai', nilai: Text('Rp 49.500'), rasio: 1.4, keterangan: '3×'),
          GrafikBatang(
            batang: [
              BatangGrafik(label: '08', rasio: 0, keterangan: 'Jam 08.00: Rp 0'),
              BatangGrafik(label: '09', rasio: 1, keterangan: 'Jam 09.00: Rp 49.500'),
            ],
          ),
        ],
      ),
    );
    expect(find.text('Rp 49.500'), findsOneWidget);
    expect(tester.widget<FractionallySizedBox>(find.byType(FractionallySizedBox)).widthFactor, 1);
    expect(find.bySemanticsLabel('Jam 08.00: Rp 0'), findsOneWidget);
    expect(find.byTooltip('Jam 09.00: Rp 49.500'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
