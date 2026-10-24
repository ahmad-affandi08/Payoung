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

  test('Sektor dibaca dan memfilter fitur: usaha jasa profesional tanpa stok, kosong = semua tampil', () {
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

  test('Kuota transaksi dibaca dari Kuota.Transaksi; server lama tanpa kunci = null', () {
    final dengan = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Kuota': {
        'Transaksi': {
          'Periode': '2026-12',
          'Terpakai': 420,
          'Batas': 500,
          'Sisa': 80,
          'Persen': 84,
          'Status': 'HampirHabis',
        },
      },
    });
    expect(dengan.kuotaTransaksi?.periode, '2026-12');
    expect(dengan.kuotaTransaksi?.terpakai, 420);
    expect(dengan.kuotaTransaksi?.batas, 500);
    expect(dengan.kuotaTransaksi?.status, StatusKuotaTransaksi.HampirHabis);

    final tanpaBatas = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Kuota': {
        'Transaksi': {'Periode': '2026-12', 'Terpakai': 9, 'Batas': null, 'Status': 'TanpaBatas'},
      },
    });
    expect(tanpaBatas.kuotaTransaksi?.batas, isNull);

    final tidakDikenal = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Kuota': {
        'Transaksi': {'Periode': '2026-12', 'Terpakai': 1, 'Batas': 5, 'Status': 'StatusBaruDariServer'},
      },
    });
    expect(tidakDikenal.kuotaTransaksi?.status, StatusKuotaTransaksi.Normal);

    final serverLama = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
    });
    expect(serverLama.kuotaTransaksi, isNull);
  });

  test('Kuota harian dibaca dari Kuota.TransaksiHarian beserta jangka dan saat hari berakhir', () {
    final konfigurasi = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Kuota': {
        'Transaksi': {'Jangka': 'Bulan', 'Periode': '2026-12', 'Terpakai': 10, 'Batas': 500, 'Status': 'Normal'},
        'TransaksiHarian': {
          'Jangka': 'Hari',
          'Periode': '2026-12-13',
          'Terpakai': 40,
          'Batas': 50,
          'Status': 'HampirHabis',
          'BerakhirPada': '2026-12-13T16:59:59Z',
        },
      },
    });
    final harian = konfigurasi.kuotaTransaksiHarian!;
    expect(harian.jangka, JangkaKuotaTransaksi.Hari);
    expect(harian.periode, '2026-12-13');
    expect(harian.batas, 50);
    expect(harian.berakhirPada, DateTime.utc(2026, 12, 13, 16, 59, 59));
    expect(konfigurasi.kuotaTransaksi?.jangka, JangkaKuotaTransaksi.Bulan);
    expect(konfigurasi.kuotaTransaksi?.berakhirPada, isNull);

    // Server tanpa Jangka: bawaan mengikuti kuncinya.
    final tanpaJangka = KonfigurasiAplikasi.DariJson({
      'Aplikasi': <String, Object?>{},
      'FlagFitur': <String, Object?>{},
      'Kuota': {
        'TransaksiHarian': {'Periode': '2026-12-13', 'Terpakai': 1, 'Batas': 5, 'Status': 'Normal'},
      },
    });
    expect(tanpaJangka.kuotaTransaksiHarian?.jangka, JangkaKuotaTransaksi.Hari);
    expect(tanpaJangka.kuotaTransaksiHarian?.berakhirPada, isNull);
    expect(tanpaJangka.kuotaTransaksi, isNull);
  });
}
