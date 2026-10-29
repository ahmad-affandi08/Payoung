import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Tampilan/Komponen/KomponenModern.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// D-235: semua keadaan kosong layar penuh Aplikasi Owner satu bentuk (ilustrasi, judul tebal, kalimat penjelas) dan
/// berada di tengah sisa layar, bukan menempel di bawah judul.
void main() {
  Widget Bungkus(Widget anak) => MaterialApp(
    theme: BuatTemaModern(),
    home: Scaffold(body: anak),
  );

  testWidgets('KosongModern menampilkan ilustrasi, judul, dan keterangan rata tengah', (tester) async {
    await tester.pumpWidget(
      Bungkus(
        const Center(
          child: KosongModern(
            ilustrasi: IlustrasiOwner.PapanSetuju,
            judul: 'Tidak ada permintaan persetujuan.',
            keterangan: 'Permintaan dari kasir muncul di sini.',
          ),
        ),
      ),
    );

    expect(find.byKey(const ValueKey('IlustrasiPapanSetuju')), findsOneWidget);
    expect(find.text('Tidak ada permintaan persetujuan.'), findsOneWidget);
    expect(find.text('Permintaan dari kasir muncul di sini.'), findsOneWidget);
  });

  testWidgets('DaftarDenganKosong menaruh keadaan kosong di tengah sisa layar di bawah kepala', (tester) async {
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      Bungkus(
        const DaftarDenganKosong(
          kepala: [KepalaHalaman(judul: 'Persetujuan')],
          kosong: KosongModern(ilustrasi: IlustrasiOwner.Lonceng, judul: 'Belum ada notifikasi.'),
        ),
      ),
    );

    final layar = tester.view.physicalSize / tester.view.devicePixelRatio;
    final kepala = tester.getBottomLeft(find.text('Persetujuan')).dy;
    final tengahIsi = tester.getCenter(find.byKey(const ValueKey('IlustrasiLonceng'))).dy;
    final tengahSisa = (kepala + layar.height) / 2;

    expect(tengahIsi, greaterThan(kepala));
    // Ilustrasi + teks berpusat di sisa layar: titik tengah ilustrasi tidak jauh dari titik tengah sisa (dalam 90 dp).
    expect((tengahIsi - tengahSisa).abs(), lessThan(90));
    expect(tester.getCenter(find.text('Belum ada notifikasi.')).dx, closeTo(layar.width / 2, 1));
  });
}
