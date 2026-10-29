import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pemilik/Aplikasi/Penyedia.dart';
import 'package:pemilik/Data/KlienPemilik.dart';
import 'package:pemilik/Tampilan/LayarNotifikasi.dart';
import 'package:sistem_desain/SistemDesain.dart';

void main() {
  testWidgets('menampilkan pusat notifikasi dan penanda belum dibaca', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          penyediaNotifikasi.overrideWith(
            (ref) async => DaftarNotifikasiPemilik(
              belumDibaca: 1,
              notifikasi: [
                NotifikasiPemilik(
                  uuid: '01KABCDEF0123456789ABCDEFG',
                  jenis: 'StokKritis',
                  judul: 'Stok menipis',
                  isi: '3 produk di bawah batas minimum.',
                  data: const {'Tautan': 'notifikasi'},
                  dibuatPada: DateTime(2026, 9, 29, 8),
                ),
              ],
            ),
          ),
        ],
        child: MaterialApp(
          theme: BuatTema(),
          home: const Scaffold(body: LayarNotifikasi()),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Stok menipis'), findsOneWidget);
    expect(find.textContaining('3 produk di bawah batas minimum.'), findsOneWidget);
    expect(find.text('Tandai semua dibaca'), findsOneWidget);
    expect(find.byIcon(Icons.notifications_active), findsOneWidget);
  });

  testWidgets('D-231: dikelompokkan per hari dan bisa disaring ke yang belum dibaca', (tester) async {
    NotifikasiPemilik Notif(String uuid, String judul, DateTime waktu, {bool baca = false}) => NotifikasiPemilik(
      uuid: uuid,
      jenis: 'StokKritis',
      judul: judul,
      isi: 'Isi $judul',
      data: const {},
      dibuatPada: waktu,
      dibacaPada: baca ? waktu : null,
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          penyediaJam.overrideWithValue(() => DateTime(2026, 9, 29, 15)),
          penyediaNotifikasi.overrideWith(
            (ref) async => DaftarNotifikasiPemilik(
              belumDibaca: 1,
              notifikasi: [
                Notif('1', 'Stok menipis', DateTime(2026, 9, 29, 8)),
                Notif('2', 'Kasir offline', DateTime(2026, 9, 28, 21), baca: true),
              ],
            ),
          ),
        ],
        child: MaterialApp(
          theme: BuatTemaModern(),
          home: const Scaffold(body: LayarNotifikasi()),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Hari ini'), findsOneWidget);
    expect(find.text('Kemarin'), findsOneWidget);
    expect(find.text('Stok menipis'), findsOneWidget);
    expect(find.text('Kasir offline'), findsOneWidget);

    await tester.tap(find.text('Belum dibaca (1)'));
    await tester.pumpAndSettle();
    expect(find.text('Stok menipis'), findsOneWidget);
    expect(find.text('Kasir offline'), findsNothing);
    expect(find.text('Kemarin'), findsNothing);
  });

  testWidgets('D-231: semua sudah dibaca menampilkan ilustrasi lonceng', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          penyediaNotifikasi.overrideWith((ref) async => const DaftarNotifikasiPemilik(belumDibaca: 0, notifikasi: [])),
        ],
        child: MaterialApp(
          theme: BuatTemaModern(),
          home: const Scaffold(body: LayarNotifikasi()),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('IlustrasiLonceng')), findsOneWidget);
    expect(find.text('Belum ada notifikasi.'), findsOneWidget);
  });
}
