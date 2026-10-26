import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Perangkat/LayananUjiPerangkat.dart';
import 'package:kasir/Domain/Sinkron/LayananSinkron.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';

import '../../Pendukung/LingkunganUji.dart';

class InfoTiruan implements SumberInfoPerangkat {
  @override
  Future<InfoPerangkat> Ambil() async =>
      const InfoPerangkat(produsen: 'SUNMI', model: 'V2s', sistem: 'Android 11', adaptor: 'Sunmi');
}

/// PRD §17.2.5a (v1.96): Wizard Uji Perangkat menyimpan profil hardware lokal lalu melaporkannya; offline = tertunda dan
/// ikut terkirim saat sinkron berikutnya.
void main() {
  late LingkunganUji u;
  late LayananUjiPerangkat layanan;
  const hasil = HasilUjiPerangkat(
    cetak: HasilUji.Lolos,
    potong: HasilUji.Dilewati,
    laci: HasilUji.Lolos,
    pemindai: HasilUji.Gagal,
  );

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    layanan = LayananUjiPerangkat(repositori: u.repositori, klien: u.klien, info: InfoTiruan(), jam: () => u.jam);
    await const ProfilPrinter(
      jenis: JenisTransport.SdkVendor,
      alamat: 'Sunmi',
      nama: 'Printer bawaan Sunmi V2s',
    ).Simpan(u.repositori);
  });
  tearDown(() => u.Tutup());

  test('profil: merek/model, printer & lebar, hasil uji, waktu uji (UTC)', () async {
    final profil = await layanan.SusunProfil(hasil);
    expect(profil['Produsen'], 'SUNMI');
    expect(profil['Adaptor'], 'Sunmi');
    expect(profil['Printer'], {'Jenis': 'SdkVendor', 'Nama': 'Printer bawaan Sunmi V2s', 'Lebar': '58 mm'});
    expect(profil['Uji'], {'Cetak': 'Lolos', 'Potong': 'Dilewati', 'Laci': 'Lolos', 'Pemindai': 'Gagal'});
    expect(profil['DiujiPada'], '2026-09-24T01:00:00.000Z');
    expect(hasil.lengkap, isTrue);
    expect(const HasilUjiPerangkat(cetak: HasilUji.Lolos).lengkap, isFalse);
  });

  test('offline: tersimpan & tertunda. Sinkron berikutnya mengirimnya sekali', () async {
    u.server.penangan = (_) async => throw http.ClientException('tidak ada jaringan');
    expect(await layanan.Simpan(hasil), isFalse);
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.profilHardwareTertunda), '1');
    expect((await layanan.AmbilTerakhir())?['Model'], 'V2s');

    u.server.permintaan.clear();
    u.server.penangan = (_) async => JsonUji({'Tersimpan': true});
    final sinkron = LayananSinkron(
      klien: u.klien,
      repositori: u.repositori,
      perangkat: u.perangkat,
      ujiPerangkat: layanan,
      jam: () => u.jam,
    );
    await sinkron.KirimTertunda();
    final kirim = u.server.permintaan.where((p) => p.url.path.endsWith('/perangkat/profil-hardware')).toList();
    expect(kirim, hasLength(1));
    expect((jsonDecode(kirim.single.body) as Map)['Uji'], containsPair('Pemindai', 'Gagal'));
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.profilHardwareTertunda), '');

    await sinkron.KirimTertunda();
    expect(u.server.permintaan.where((p) => p.url.path.endsWith('/perangkat/profil-hardware')), hasLength(1));
  });
}
