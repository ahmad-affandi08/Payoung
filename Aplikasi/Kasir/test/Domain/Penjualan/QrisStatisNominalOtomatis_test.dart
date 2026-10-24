import 'package:flutter_test/flutter_test.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Pendukung/LingkunganUji.dart';

/// D-189: teks QRIS statis toko ikut data-awal, tersimpan lokal, dan dibuat bernominal di perangkat.
void main() {
  // Vektor bersama: QRIS statis sah dan padanan dinamis Rp 25.000.
  const statis =
      '00020101021126700018COM.EXAMPLEPSP.WWW011893600000000000000102150000000000000010303UMI51440014ID.CO.QRIS.WWW0215ID10200000000010303UMI5204541153033605802ID5922TOKO SEMBAKO MAJU JAYA6009SURAKARTA61055713962070703A016304E416';
  const dinamisDiharapkan =
      '00020101021226700018COM.EXAMPLEPSP.WWW011893600000000000000102150000000000000010303UMI51440014ID.CO.QRIS.WWW0215ID10200000000010303UMI5204541153033605405250005802ID5922TOKO SEMBAKO MAJU JAYA6009SURAKARTA61055713962070703A0163041758';

  Map<String, Object?> DataDenganTeksQris(String? teks) {
    final data = DataAwalUji();
    data['MetodePembayaran'] = [
      for (final m in data['MetodePembayaran']! as List<Object?>)
        if ((m! as Map<String, Object?>)['Jenis'] == 'QrisStatis')
          {...(m as Map<String, Object?>), 'TeksQris': teks}
        else
          m,
    ];
    return data;
  }

  test('TeksQris data-awal tersimpan di metode QRIS statis; server lama (tanpa kunci) = null', () async {
    final u = LingkunganUji.Buat();
    addTearDown(u.db.close);

    await u.SiapkanAktif(dataAwal: DataDenganTeksQris('TEKS'));
    var metode = await (u.db.select(u.db.metodePembayaran)..where((t) => t.Jenis.equals('QrisStatis'))).getSingle();
    expect(metode.TeksQris, 'TEKS');

    await u.repositori.SimpanDataAwal(DataAwal.DariJson(DataAwalUji()), u.jam);
    metode = await (u.db.select(u.db.metodePembayaran)..where((t) => t.Jenis.equals('QrisStatis'))).getSingle();
    expect(
      metode.TeksQris,
      isNull,
      reason: 'Data-awal baru menggantikan: toko yang mematikan nominal otomatis kembali ke gambar.',
    );
  });

  test('QRIS bernominal dari teks tersimpan sama dengan vektor bersama (tag 54 terisi, CRC sah, jenis dinamis)', () {
    expect(Qris.BuatDinamis(statis, Uang.DariBulat(25000).KeString()), dinamisDiharapkan);
  });
}
