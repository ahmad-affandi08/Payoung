import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../GalatKasir.dart';
import '../Sesi/StafLokal.dart';
import 'Keranjang.dart';

/// Reservasi meja FnB + uang muka (DP) di aplikasi kasir (D-145):
/// - **Ambil** (online): antrian reservasi meja outlet ini pada satu tanggal, beserta metode sistem "Uang muka (DP)".
/// - **Hadir** (online): tamu datang, idempoten; reservasi yang belum bermeja ditempatkan di meja pilihan kasir
///   (server memeriksa kapasitas dan bentrok).
/// - **PasangUangMuka**: menempelkan reservasi ke keranjang yang sedang berisi (pesanan meja atau belanja biasa) sebagai
///   [PraPesananKeranjang] ber-sumber `reservasiMeja`. Panel Bayar lalu mengisi baris Uang muka otomatis dan
///   `Penjualan.Buat` membawa `UuidReservasiMeja`; server memotong DP dan menyelesaikan reservasi di transaksi yang
///   sama. Pembayarannya tetap offline-first seperti penjualan biasa.
class LayananReservasiMeja {
  LayananReservasiMeja({required this.klien});

  final KlienPos klien;

  Future<AntrianReservasiMejaPos> Ambil({String? tanggal}) =>
      _Online(() => klien.AmbilReservasiMeja(tanggal: tanggal), 'Melihat reservasi meja');

  Future<HasilHadirReservasiMejaPos> Hadir(ReservasiMejaPos reservasi, {required StafLokal kasir, String? uuidMeja}) {
    if (!kasir.PunyaIzin(IzinKasir.penjualanBuat) && !kasir.PunyaIzin(IzinKasir.reservasiKelola)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak boleh menerima tamu reservasi meja.');
    }
    if (reservasi.uuidMeja == null && uuidMeja == null) {
      throw GalatKasir('MejaBelumDipilih', 'Pilih meja untuk reservasi ${reservasi.nomor} sebelum tamu duduk.');
    }
    return _Online(
      () => klien.HadirReservasiMeja(
        reservasi.uuid,
        uuidPengguna: kasir.uuid,
        uuidMeja: reservasi.uuidMeja == null ? uuidMeja : null,
      ),
      'Mencatat kedatangan tamu',
    );
  }

  /// Keranjang [draf] dengan [reservasi] terpasang sebagai sumber uang muka. [keranjangEfektif] dipakai untuk memeriksa
  /// isi (pada pesanan meja, baris tersimpan ada di pesanannya, bukan di draf). [uuidMetode]/[namaMetode] = metode sistem
  /// Uang muka (DP) dari [AntrianReservasiMejaPos]; boleh kosong hanya bila reservasi tidak memegang uang muka.
  Keranjang PasangUangMuka(
    Keranjang draf, {
    required Keranjang keranjangEfektif,
    required ReservasiMejaPos reservasi,
    required String? uuidMetode,
    String? namaMetode,
  }) {
    if (!reservasi.BisaDilayani) {
      throw GalatKasir(
        'ReservasiSelesai',
        'Reservasi ${reservasi.nomor} sudah ${reservasi.labelStatus.toLowerCase()}.',
      );
    }
    if (keranjangEfektif.CekKosong) {
      throw const GalatKasir('KeranjangKosong', 'Isi keranjang atau buka pesanan mejanya dulu, baru pakai uang muka.');
    }
    if (draf.praPesan != null || draf.tukar != null || draf.reservasi != null || draf.perintahKerja != null) {
      throw const GalatKasir(
        'KeranjangTerikat',
        'Keranjang ini sudah menagih dokumen lain (pre-order, pesanan online, tukar barang, reservasi layanan, atau servis).',
      );
    }
    final mejaPesanan = draf.pesananMeja?.uuidMeja;
    if (mejaPesanan != null && reservasi.uuidMeja != null && mejaPesanan != reservasi.uuidMeja) {
      throw GalatKasir(
        'MejaBerbeda',
        'Reservasi ${reservasi.nomor} untuk meja ${reservasi.namaMeja ?? ''}, bukan meja pesanan ini.',
      );
    }
    final sisa = Uang.Dari(reservasi.uangMuka.sisa);
    if (sisa.Bandingkan(Uang.Nol()) > 0 && (uuidMetode == null || uuidMetode.isEmpty)) {
      throw const GalatKasir(
        'DataAwalBelumLengkap',
        'Metode uang muka belum tersedia di server, jadi reservasi berdeposit belum bisa ditagih. Coba lagi nanti.',
      );
    }
    return draf.Salin(
      praPesan: () => PraPesananKeranjang(
        uuid: reservasi.uuid,
        nomor: reservasi.nomor,
        sisaUangMuka: sisa,
        uuidMetode: uuidMetode ?? '',
        namaMetode: namaMetode ?? 'Uang muka (DP)',
        sumber: SumberUangMuka.reservasiMeja,
      ),
    );
  }

  Future<T> _Online<T>(Future<T> Function() kerja, String tindakan) async {
    try {
      return await kerja();
    } on GalatJaringan {
      throw GalatKasir('PerluOnline', '$tindakan perlu koneksi internet. Coba lagi saat perangkat online.');
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
  }
}
