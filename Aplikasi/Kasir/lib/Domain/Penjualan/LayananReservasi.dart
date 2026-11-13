import 'package:klien_api/KlienApi.dart';

import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Sesi/StafLokal.dart';
import 'Keranjang.dart';
import 'KonteksPenjualan.dart';
import 'LayananPenjualan.dart';

/// Reservasi layanan di aplikasi kasir (F-07 mode service bagian 2, POS-04/SLS-07):
/// - **Ambil** (online): antrian reservasi outlet ini pada satu tanggal; offline → `PerluOnline`.
/// - **Hadir** (online): pelanggan datang (check-in), idempoten.
/// - **Muat ke keranjang**: layanan reservasi dengan staf yang dipilih (komisi F-18) dan pelanggannya; `Penjualan.Buat`
///   membawa `UuidReservasi` sehingga server menyelesaikan & menautkan reservasi di transaksi yang sama. Pembayaran
///   sendiri tetap offline-first seperti penjualan biasa.
/// - K-20 **Kalender & booking** (online): layanan, staf berjadwal & reservasi satu tanggal, jam kosong per layanan/staf,
///   dan buat booking baru dari kasir (server mengunci per staf & menghitung ulang slot; bentrok = `SlotTidakTersedia`).
class LayananReservasi {
  LayananReservasi({required this.klien, required this.penjualan});

  final KlienPos klien;
  final LayananPenjualan penjualan;

  Future<List<ReservasiPos>> Ambil({String? tanggal}) =>
      _Online(() => klien.AmbilReservasi(tanggal: tanggal), 'Melihat reservasi');

  Future<ReservasiPos> Hadir(ReservasiPos reservasi, {required StafLokal kasir}) {
    if (!kasir.PunyaIzin(IzinKasir.penjualanBuat) && !kasir.PunyaIzin(IzinKasir.reservasiKelola)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak boleh menerima pelanggan reservasi.');
    }
    return _Online(() => klien.HadirReservasi(reservasi.uuid, uuidPengguna: kasir.uuid), 'Mencatat kedatangan');
  }

  Future<KalenderReservasiPos> AmbilKalender({String? tanggal}) =>
      _Online(() => klien.AmbilKalenderReservasi(tanggal: tanggal), 'Melihat kalender booking');

  Future<List<SlotReservasiPos>> AmbilSlot({required String uuidLayanan, required String tanggal, String? uuidStaf}) =>
      _Online(
        () => klien.AmbilSlotReservasi(uuidLayanan: uuidLayanan, tanggal: tanggal, uuidStaf: uuidStaf),
        'Melihat jam kosong',
      );

  Future<ReservasiPos> Buat({
    required StafLokal kasir,
    required String uuidLayanan,
    required String tanggal,
    required String jam,
    String? uuidStaf,
    required String namaPelanggan,
    required String noHp,
    String? catatan,
  }) {
    if (!kasir.PunyaIzin(IzinKasir.penjualanBuat) && !kasir.PunyaIzin(IzinKasir.reservasiKelola)) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak boleh mencatat booking.');
    }
    final nama = namaPelanggan.trim();
    if (nama.isEmpty) {
      throw const GalatKasir('NamaWajib', 'Isi nama pelanggan.');
    }
    if (noHp.replaceAll(RegExp(r'\D'), '').length < 9) {
      throw const GalatKasir('NoHpTidakValid', 'Isi nomor HP pelanggan (minimal 9 angka).');
    }
    final catatanRapi = catatan?.trim();
    return _Online(
      () => klien.BuatReservasi(
        uuidPengguna: kasir.uuid,
        uuidLayanan: uuidLayanan,
        tanggal: tanggal,
        jam: jam,
        uuidStaf: uuidStaf,
        namaPelanggan: nama,
        noHp: noHp.trim(),
        catatan: catatanRapi == null || catatanRapi.isEmpty ? null : catatanRapi,
      ),
      'Mencatat booking',
    );
  }

  /// Rentang kosong (`HH:MM`–`HH:MM`, jam outlet) di jam kerja [staf] setelah dikurangi reservasinya yang masih memakai
  /// slot (bukan Batal/Tidak datang). Ringkasan untuk kalender; jam mulai pasti tetap dari [AmbilSlot].
  static List<({String mulai, String selesai})> HitungKosong(
    StafReservasiPos staf,
    List<ReservasiPos> reservasi,
    String zona,
  ) {
    int Menit(String jam) => int.parse(jam.substring(0, 2)) * 60 + int.parse(jam.substring(3, 5));
    String Jam(int menit) => '${(menit ~/ 60).toString().padLeft(2, '0')}:${(menit % 60).toString().padLeft(2, '0')}';
    int MenitOutlet(DateTime waktu) {
      final lokal = ZonaWaktuOutlet.KeWaktuOutlet(waktu, zona);
      return lokal.hour * 60 + lokal.minute;
    }

    final sibuk = [
      for (final r in reservasi)
        if (r.uuidStaf == staf.uuid && r.status != 'Batal' && r.status != 'TidakDatang')
          (MenitOutlet(r.mulaiPada), MenitOutlet(r.selesaiPada)),
    ]..sort((a, b) => a.$1.compareTo(b.$1));
    final hasil = <({String mulai, String selesai})>[];
    var kursor = Menit(staf.jamMulai);
    final akhir = Menit(staf.jamSelesai);
    for (final (mulai, selesai) in sibuk) {
      if (mulai > kursor) {
        hasil.add((mulai: Jam(kursor), selesai: Jam(mulai < akhir ? mulai : akhir)));
      }
      if (selesai > kursor) {
        kursor = selesai;
      }
    }
    if (kursor < akhir) {
      hasil.add((mulai: Jam(kursor), selesai: Jam(akhir)));
    }
    return hasil.where((r) => r.mulai != r.selesai).toList();
  }

  /// Keranjang berisi layanan reservasi (harga katalog saat ini) dengan staf pelaksana & pelanggannya.
  Keranjang MuatKeKeranjang(ReservasiPos reservasi, KatalogLokal katalog, KonteksPenjualan k) {
    if (!reservasi.BisaDilayani) {
      throw GalatKasir(
        'ReservasiSelesai',
        'Reservasi ${reservasi.nomor} sudah ${reservasi.labelStatus.toLowerCase()}.',
      );
    }
    final uuidProduk = reservasi.uuidProduk;
    final produk = uuidProduk == null ? null : katalog.CariProduk(uuidProduk);
    if (produk == null) {
      throw GalatKasir(
        'ProdukTidakDikenal',
        '"${reservasi.namaLayanan}" belum ada di katalog perangkat ini. Perbarui katalog, lalu coba lagi.',
      );
    }
    final data = reservasi.pelanggan;
    final pelanggan = data == null
        ? null
        : PelangganTerpilih(
            uuid: '${data['Uuid']}',
            nama: '${data['Nama']}',
            noHpSamar: '${data['NoHp'] ?? ''}',
            kodeTier: data['KodeTier'] as String?,
            namaTier: data['NamaTier'] as String?,
          );
    final item = penjualan.BuatBaris(katalog, k, produk, tierPelanggan: pelanggan?.kodeTier);
    final uuidStaf = reservasi.uuidStaf;
    return Keranjang(
      baris: [
        if (uuidStaf == null) item else item.Salin(staf: [uuidStaf]),
      ],
      pelanggan: pelanggan,
      reservasi: ReservasiKeranjang(uuid: reservasi.uuid, nomor: reservasi.nomor),
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
