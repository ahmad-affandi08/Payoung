import 'package:klien_api/KlienApi.dart';

import '../../Data/RepositoriKasir.dart';
import '../GalatKasir.dart';
import '../Sesi/StafLokal.dart';
import 'LayananTiketDapur.dart';

/// D-310 (F-10): kasir mengatur ke stasiun dapur/bar mana tiap kategori dan menu dikirim. Perlu online dan izin
/// `dapur.rute.atur` (bawaan Kasir). Berlaku untuk seluruh usaha di server; salinan lokal ([RuteDapur]) langsung
/// diperbarui supaya tiket berikutnya di perangkat ini sudah memakai aturan baru tanpa menunggu data meja.
class LayananRuteDapur {
  LayananRuteDapur({required this.klien, required this.repositori});

  final KlienPos klien;
  final RepositoriKasir repositori;

  /// Pelaku boleh mengatur stasiun (tombol/bagian pengaturan hanya ditampilkan bila true).
  static bool CekBoleh(StafLokal kasir) => kasir.PunyaIzin(IzinKasir.dapurRuteAtur);

  /// [uuidStasiun] null = ikut kategori induk atau stasiun bawaan. Mengembalikan rute terbaru.
  Future<RuteDapur> AturKategori(String uuidKategori, {required String? uuidStasiun, required StafLokal kasir}) =>
      _Atur(
        kasir,
        () => klien.AturStasiunDapurKategori(uuidKategori, uuidStasiun: uuidStasiun, uuidPengguna: kasir.uuid),
        (rute, akhir) => rute.Salin(kategori: _Ganti(rute.kategori, uuidKategori, akhir)),
      );

  /// [uuidStasiun] null = menu ikut stasiun kategorinya.
  Future<RuteDapur> AturProduk(String uuidProduk, {required String? uuidStasiun, required StafLokal kasir}) => _Atur(
    kasir,
    () => klien.AturStasiunDapurProduk(uuidProduk, uuidStasiun: uuidStasiun, uuidPengguna: kasir.uuid),
    (rute, akhir) => rute.Salin(produk: _Ganti(rute.produk, uuidProduk, akhir)),
  );

  static Map<String, String> _Ganti(Map<String, String> peta, String kunci, String? nilai) {
    final hasil = Map<String, String>.of(peta)..remove(kunci);
    if (nilai != null) {
      hasil[kunci] = nilai;
    }

    return hasil;
  }

  Future<RuteDapur> _Atur(
    StafLokal kasir,
    Future<String?> Function() kirim,
    RuteDapur Function(RuteDapur rute, String? akhir) terapkan,
  ) async {
    if (!CekBoleh(kasir)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak boleh mengatur stasiun dapur.');
    }
    final String? akhir;
    try {
      akhir = await kirim();
    } on GalatJaringan {
      throw const GalatKasir(
        'PerluOnline',
        'Mengatur stasiun dapur perlu koneksi internet. Coba lagi saat perangkat online.',
      );
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
    final baru = terapkan(await RuteDapur.Muat(repositori), akhir);
    await baru.Simpan(repositori);

    return baru;
  }
}
