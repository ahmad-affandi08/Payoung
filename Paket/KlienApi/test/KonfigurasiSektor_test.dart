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
}
