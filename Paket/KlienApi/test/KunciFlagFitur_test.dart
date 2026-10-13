import 'package:klien_api/KlienApi.dart';
import 'package:test/test.dart';

/// P-10 PGL-18: kunci flag yang dihormati aplikasi harus berformat D-06 (huruf kecil, kebab-case, dipisah titik), unik,
/// dan terbaca lewat `KonfigurasiAplikasi.CekFlag` (kunci tanpa aturan = hidup, `Global` mati = kill switch).
void main() {
  const semua = [
    KunciFlagFitur.kasirHargaManual,
    KunciFlagFitur.kasirReturTanpaStruk,
    KunciFlagFitur.kasirPindaiKamera,
    KunciFlagFitur.pemilikInsight,
    KunciFlagFitur.pemilikKaryawan,
    KunciFlagFitur.pemilikPersetujuanDokumen,
  ];

  test('kunci berformat D-06 dan unik', () {
    final pola = RegExp(r'^[a-z]+(\.[a-z]+(-[a-z]+)*)+$');
    for (final kunci in semua) {
      expect(pola.hasMatch(kunci), isTrue, reason: kunci);
    }
    expect(semua.toSet(), hasLength(semua.length));
    expect(semua.where((k) => k.startsWith('kasir.')), hasLength(3));
    expect(semua.where((k) => k.startsWith('pemilik.')), hasLength(3));
  });

  test('CekFlag: kill switch mematikan, kunci tanpa aturan hidup, bentuk Owner dibaca sama dengan POS', () {
    final konfigurasi = KonfigurasiAplikasi.DariJson({
      'Aplikasi': {'Platform': 'Android', 'VersiSaatIni': '1.0.0', 'VersiTerbaru': '1.0.0', 'VersiMinimal': '1.0.0'},
      'FlagFitur': {KunciFlagFitur.pemilikInsight: false, KunciFlagFitur.kasirHargaManual: true},
    });

    expect(konfigurasi.CekFlag(KunciFlagFitur.pemilikInsight), isFalse);
    expect(konfigurasi.CekFlag(KunciFlagFitur.kasirHargaManual), isTrue);
    expect(konfigurasi.CekFlag(KunciFlagFitur.pemilikKaryawan), isTrue);
    expect(konfigurasi.wajibPembaruan, isFalse);
    expect(konfigurasi.adaPembaruan, isFalse);
  });
}
