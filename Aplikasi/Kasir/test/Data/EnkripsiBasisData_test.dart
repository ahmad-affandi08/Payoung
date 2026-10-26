import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/BasisData/EnkripsiBasisData.dart';
import 'package:kasir/Data/PenyimpanRahasia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:path/path.dart' as jalur;
import 'package:sqlite3/sqlite3.dart';

/// K-7 (§17.2.6): basis data lokal kasir terenkripsi dengan kunci di secure storage; berkas lama yang polos dienkripsi
/// di tempat tanpa kehilangan data; kunci tidak ikut terhapus saat perangkat dicabut.
void main() {
  late Directory folder;
  late File berkas;

  setUp(() {
    folder = Directory.systemTemp.createTempSync('KasirEnkripsi');
    berkas = File(jalur.join(folder.path, 'Kasir.sqlite'));
  });

  tearDown(() => folder.deleteSync(recursive: true));

  Future<String?> BacaPengaturan(String kunci) async {
    final db = BasisDataKasir(EnkripsiBasisData.Buka(berkas, kunci));
    try {
      return await RepositoriKasir(db).AmbilPengaturan('NamaOutlet');
    } finally {
      await db.close();
    }
  }

  test('pustaka SQLite yang termuat mendukung enkripsi (sqlite3mc)', () {
    final db = sqlite3.openInMemory();
    addTearDown(db.close);
    expect(() => EnkripsiBasisData.PastikanPustakaEnkripsi(db), returnsNormally);
  });

  test('basis data baru terenkripsi: header bukan SQLite polos, kunci benar terbaca, kunci salah ditolak', () async {
    final kunci = EnkripsiBasisData.BuatKunci();
    final db = BasisDataKasir(EnkripsiBasisData.Buka(berkas, kunci));
    await RepositoriKasir(db).SimpanPengaturan('NamaOutlet', 'Kopi Senja Solo Baru');
    await db.close();

    expect(EnkripsiBasisData.CekPolos(berkas), isFalse);
    expect(String.fromCharCodes(berkas.readAsBytesSync()).contains('Kopi Senja Solo Baru'), isFalse);
    expect(await BacaPengaturan(kunci), 'Kopi Senja Solo Baru');
    await expectLater(BacaPengaturan(EnkripsiBasisData.BuatKunci()), throwsA(anything));
  });

  test('berkas lama polos dienkripsi di tempat. Outbox & pengaturan tetap utuh', () async {
    final polos = BasisDataKasir(NativeDatabase(berkas));
    final repositori = RepositoriKasir(polos);
    await repositori.SimpanPengaturan('NamaOutlet', 'Toko Sembako Berkah Jaya');
    await polos
        .into(polos.outbox)
        .insert(
          OutboxCompanion.insert(
            Uuid: '01JABCDEF0000000000000000',
            Jenis: 'Penjualan.Buat',
            Data: '{"Nomor":"INV/SLO/261002/K01-0001"}',
            Status: 'Tertunda',
            DibuatPada: DateTime.utc(2026, 10, 2, 3),
            BerikutnyaPada: DateTime.utc(2026, 10, 2, 3),
          ),
        );
    await polos.close();
    expect(EnkripsiBasisData.CekPolos(berkas), isTrue);

    final kunci = EnkripsiBasisData.BuatKunci();
    final db = BasisDataKasir(EnkripsiBasisData.Buka(berkas, kunci));
    final outbox = await db.select(db.outbox).get();
    expect(outbox.single.Data, '{"Nomor":"INV/SLO/261002/K01-0001"}');
    expect(await RepositoriKasir(db).AmbilPengaturan('NamaOutlet'), 'Toko Sembako Berkah Jaya');
    await db.close();
    expect(EnkripsiBasisData.CekPolos(berkas), isFalse);
  });

  test('kunci dibuat sekali (256 bit acak) dan tidak ikut terhapus saat perangkat dicabut', () async {
    final rahasia = PenyimpanRahasiaMemori();
    final kunci = await EnkripsiBasisData.AmbilAtauBuatKunci(rahasia);
    expect(kunci, matches(RegExp(r'^[0-9a-f]{64}$')));
    expect(await EnkripsiBasisData.AmbilAtauBuatKunci(rahasia), kunci);

    await rahasia.Tulis(PenyimpanRahasia.kunciToken, 'token-perangkat');
    await rahasia.HapusSemua();
    expect(await rahasia.Baca(PenyimpanRahasia.kunciToken), isNull);
    expect(await rahasia.Baca(PenyimpanRahasia.kunciBasisData), kunci);
  });
}
