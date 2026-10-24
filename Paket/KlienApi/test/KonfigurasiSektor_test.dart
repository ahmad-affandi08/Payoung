import 'package:klien_api/KlienApi.dart';
import 'package:test/test.dart';

/// D-183: `GET /api/pemilik/v1/konfigurasi-aplikasi` membawa `HanyaPerusahaan` (usaha tanpa kasir) untuk menyaring tab
/// Aplikasi Owner. Server lama tanpa kunci itu = false (semua tab tampil).
void main() {
  test('HanyaPerusahaan dibaca dari konfigurasi; tanpa kunci = false', () {
    final tanpaKasir = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Sektor': ['BIZ-JSA'],
      'HanyaPerusahaan': true,
    });
    expect(tanpaKasir.hanyaPerusahaan, isTrue);

    final serverLama = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
    });
    expect(serverLama.hanyaPerusahaan, isFalse);
  });

  test('Sektor dibaca dan menyaring fitur: usaha jasa profesional tanpa stok, kosong = semua tampil', () {
    KonfigurasiAplikasi Muat(List<String>? sektor) => KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Sektor': ?sektor,
    });

    expect(Muat(['BIZ-JSA']).sektor, ['BIZ-JSA']);
    expect(Muat(['BIZ-JSA']).mengelolaStok, isFalse);
    expect(Muat(['BIZ-JSA', 'RTL-GEN']).mengelolaStok, isTrue);
    expect(Muat(['SVC-WRK']).CekSesuaiSektor(['SVC']), isTrue);
    expect(Muat(['SVC-GEN']).mengelolaStok, isFalse);
    expect(Muat(null).mengelolaStok, isTrue);
    expect(Muat([]).CekSesuaiSektor(['FNB']), isTrue);
  });
}
