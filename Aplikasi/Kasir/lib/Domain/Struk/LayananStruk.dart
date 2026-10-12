import 'dart:convert';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../GalatKasir.dart';
import '../Pelanggan/LayananDeposit.dart';
import '../Penjualan/BagianTagihan.dart';
import '../Penjualan/Keranjang.dart';
import '../Penjualan/KonteksPenjualan.dart';
import '../Penjualan/LayananPenjualan.dart';
import '../Penjualan/LayananPreOrder.dart';
import '../Shift/LayananTutupShift.dart';
import 'IdentitasStruk.dart';
import 'PenyusunDokumenKasir.dart';
import 'PenyusunStrukPenjualan.dart';
import 'ProfilPrinter.dart';

/// Membuat transport dari profil printer (diganti tiruan di test).
typedef PembuatTransport = TransportPrinter Function(ProfilPrinter profil);

/// Cetak struk penjualan (plus buka laci untuk tunai), cetak ulang, cetak uji (POS-11, POS-17, PRD v1.79), serta bukti
/// void, nota retur, dan laporan shift X/Z (PRD v1.84), serta pulsa buka laci manual (PRD v1.87; pencatatannya di
/// `LayananBukaLaci`, §19.2). Semua dari data lokal,
/// jadi tetap jalan saat offline (§18). Galat printer dilempar sebagai [GalatPrinter] berpesan untuk kasir; penjualan
/// tetap tersimpan walau struk gagal dicetak.
class LayananStruk {
  LayananStruk({required this.repositori, required this.penjualan, required this.pembuatTransport});

  final RepositoriKasir repositori;
  final RepositoriPenjualan penjualan;
  final PembuatTransport pembuatTransport;

  Future<ProfilPrinter?> AmbilProfil() => ProfilPrinter.Muat(repositori);

  /// Cetak struk [uuidPenjualan]. [bukaLaci] hanya dipakai bila profil mengizinkan dan ada pembayaran tunai.
  Future<void> CetakPenjualan(
    String uuidPenjualan, {
    bool cetakUlang = false,
    bool bukaLaci = false,
    String? namaPelanggan,
    String? labelPoin,
    ProfilPrinter? lewat,
  }) async {
    final profil = lewat ?? await _WajibProfil();
    final baris = await penjualan.CariPenjualan(uuidPenjualan);
    if (baris == null) {
      throw const GalatKasir('PenjualanTidakDitemukan', 'Transaksi ini tidak ada di perangkat.');
    }
    final pembayaran = await penjualan.AmbilPembayaran(uuidPenjualan);
    final data = DataStrukPenjualan(
      penjualan: baris,
      detail: await penjualan.AmbilDetail(uuidPenjualan),
      pembayaran: pembayaran,
      namaPelanggan: namaPelanggan,
      labelPoin: labelPoin,
      laundry: baris.Laundry == null ? null : LaundryKeranjang.DariJson(jsonDecode(baris.Laundry!)),
      awalanLacakLaundry: baris.Laundry == null ? null : (await KonteksPenjualan.MuatLaundry(repositori)).awalanLacak,
    );
    final laci = bukaLaci && profil.bukaLaciTunai && pembayaran.any((b) => b.Jenis == JenisMetodeBayar.tunai);
    final dokumen = PenyusunStrukPenjualan.Susun(
      await IdentitasStruk.Muat(repositori),
      data,
      cetakUlang: cetakUlang,
      bukaLaci: laci,
    );
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(dokumen);
  }

  /// Bagian bayar tiap tamu penjualan [uuidPenjualan] (K-14); null = penjualan tidak dibagi (atau hanya satu tamu).
  Future<BagianTagihan?> AmbilBagianTagihan(String uuidPenjualan) async {
    final baris = await penjualan.CariPenjualan(uuidPenjualan);
    final json = baris?.BagianTagihan;
    if (json == null) {
      return null;
    }
    final bagian = BagianTagihan.DariJson((jsonDecode(json) as Map).cast<String, Object?>());
    return bagian.tamu.length >= 2 ? bagian : null;
  }

  /// Struk per tamu (K-14): [tamuKe] mulai 1; null = semua tamu, satu struk per tamu berurutan. Dari data lokal,
  /// jadi tetap jalan offline dan setelah transaksi tersinkron. Tanpa laci (uangnya sudah tercatat di struk utama).
  Future<void> CetakStrukTamu(String uuidPenjualan, {int? tamuKe, bool cetakUlang = false}) async {
    final profil = await _WajibProfil();
    final baris = await penjualan.CariPenjualan(uuidPenjualan);
    if (baris == null) {
      throw const GalatKasir('PenjualanTidakDitemukan', 'Transaksi ini tidak ada di perangkat.');
    }
    final bagian = await AmbilBagianTagihan(uuidPenjualan);
    if (bagian == null) {
      throw const GalatKasir('TagihanTidakDibagi', 'Transaksi ini tidak dibagi per tamu.');
    }
    if (tamuKe != null && (tamuKe < 1 || tamuKe > bagian.tamu.length)) {
      throw const GalatKasir('TamuTidakAda', 'Nomor tamu tidak ada di transaksi ini.');
    }
    final identitas = await IdentitasStruk.Muat(repositori);
    for (final ke in tamuKe == null ? [for (var i = 1; i <= bagian.tamu.length; i++) i] : [tamuKe]) {
      await PrinterStruk(
        pembuatTransport(profil),
        profil.lebar,
        umpanAkhir: profil.umpanAkhir,
      ).Cetak(PenyusunDokumenKasir.SusunStrukTamu(identitas, baris, bagian, ke, cetakUlang: cetakUlang));
    }
  }

  /// Bukti void [uuidPenjualan] (cetak struk bagian 3b). [bukaLaci] untuk refund tunai dari laci.
  Future<void> CetakVoid(String uuidPenjualan, {bool cetakUlang = false, bool bukaLaci = false}) async {
    final profil = await _WajibProfil();
    final jual = await penjualan.CariPenjualan(uuidPenjualan);
    final dokumen = await penjualan.CariVoid(uuidPenjualan);
    if (jual == null || dokumen == null) {
      throw const GalatKasir('VoidTidakDitemukan', 'Void transaksi ini tidak ada di perangkat.');
    }
    final laci = bukaLaci && profil.bukaLaciTunai && !Uang.Dari(dokumen.RefundTunai).BernilaiNol();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunVoid(
        await IdentitasStruk.Muat(repositori),
        jual,
        dokumen,
        cetakUlang: cetakUlang,
        bukaLaci: laci,
      ),
    );
  }

  /// Nota retur [uuidRetur] (cetak struk bagian 3b). [bukaLaci] untuk refund tunai dari laci.
  Future<void> CetakRetur(String uuidRetur, {bool cetakUlang = false, bool bukaLaci = false}) async {
    final profil = await _WajibProfil();
    final retur = await penjualan.CariRetur(uuidRetur);
    if (retur == null) {
      throw const GalatKasir('ReturTidakDitemukan', 'Retur ini tidak ada di perangkat.');
    }
    final laci = bukaLaci && profil.bukaLaciTunai && !Uang.Dari(retur.RefundTunai).BernilaiNol();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunRetur(
        await IdentitasStruk.Muat(repositori),
        retur,
        await penjualan.AmbilDetailRetur(uuidRetur),
        await penjualan.AmbilPembayaranRetur(uuidRetur),
        cetakUlang: cetakUlang,
        bukaLaci: laci,
      ),
    );
  }

  /// Laporan shift X/Z (cetak struk bagian 3b).
  /// Bukti uang muka pre-order (cetak struk bagian 4a) dari data di memori setelah pre-order dibuat. Laci dibuka
  /// bila [bukaLaci] (cetak otomatis pertama), profil mengizinkan, dan uang muka tunai.
  Future<void> CetakPreOrder(PreOrderTersimpan preOrder, {bool cetakUlang = false, bool bukaLaci = false}) async {
    final profil = await _WajibProfil();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunPreOrder(
        await IdentitasStruk.Muat(repositori),
        preOrder,
        cetakUlang: cetakUlang,
        bukaLaci: bukaLaci && profil.bukaLaciTunai && preOrder.uangMukaTunai,
      ),
    );
  }

  /// Nota/label laundry (§9.9) dari daftar cucian; QR lacak memakai awalan dari data awal.
  /// Tagihan sementara pesanan meja (§9.1, v3.53); tanpa laci & tanpa mencatat apa pun.
  Future<void> CetakTagihanSementara({
    required String judul,
    required String nomor,
    required Keranjang keranjang,
    required HitunganKeranjang hitungan,
    required DateTime waktu,
    String? namaKasir,
    int jumlahTamu = 0,
  }) async {
    final profil = await _WajibProfil();
    final dokumen = PenyusunDokumenKasir.SusunTagihanSementara(
      await IdentitasStruk.Muat(repositori),
      judul: judul,
      nomor: nomor,
      keranjang: keranjang,
      hitungan: hitungan,
      waktu: waktu,
      namaKasir: namaKasir,
      jumlahTamu: jumlahTamu,
    );
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(dokumen);
  }

  Future<void> CetakNotaLaundry(TiketLaundryPos tiket) async {
    final profil = await _WajibProfil();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunNotaLaundry(
        await IdentitasStruk.Muat(repositori),
        tiket,
        awalanLacak: (await KonteksPenjualan.MuatLaundry(repositori)).awalanLacak,
      ),
    );
  }

  /// Bukti isi deposit (F-16d bagian 1) dari data di memori setelah isi deposit dibuat. Laci dibuka bila [bukaLaci]
  /// (cetak otomatis pertama), profil mengizinkan, dan dibayar tunai.
  Future<void> CetakIsiDeposit(IsiDepositTersimpan isi, {bool cetakUlang = false, bool bukaLaci = false}) async {
    final profil = await _WajibProfil();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunIsiDeposit(
        await IdentitasStruk.Muat(repositori),
        isi,
        cetakUlang: cetakUlang,
        bukaLaci: bukaLaci && profil.bukaLaciTunai && isi.tunai,
      ),
    );
  }

  Future<void> CetakLaporanShift(LaporanShift laporan, {bool tampilkanKasSeharusnya = true}) async {
    final profil = await _WajibProfil();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).Cetak(
      PenyusunDokumenKasir.SusunLaporanShift(
        await IdentitasStruk.Muat(repositori),
        laporan,
        tampilkanKasSeharusnya: tampilkanKasSeharusnya,
      ),
    );
  }

  /// Printer diatur dan cetak otomatis aktif.
  Future<bool> CekCetakOtomatis() async => (await AmbilProfil())?.cetakOtomatis ?? false;

  /// Dipanggil setelah pembayaran tersimpan: cetak (plus buka laci bila tunai) bila [CekCetakOtomatis]. true = dicetak.
  Future<bool> CetakSetelahBayar(String uuidPenjualan, {String? namaPelanggan, String? labelPoin}) async {
    if (!await CekCetakOtomatis()) {
      return false;
    }
    await CetakPenjualan(uuidPenjualan, bukaLaci: true, namaPelanggan: namaPelanggan, labelPoin: labelPoin);
    return true;
  }

  /// Cetak [dokumen] ke printer [profil] (null = printer struk perangkat ini); dipakai tiket dapur (bagian 4c).
  Future<void> CetakDokumenKe(ProfilPrinter? profil, DokumenStruk dokumen) async {
    final tujuan = profil ?? await _WajibProfil();
    await PrinterStruk(pembuatTransport(tujuan), tujuan.lebar, umpanAkhir: tujuan.umpanAkhir).Cetak(dokumen);
  }

  /// Kirim pulsa laci (ESC p) lewat printer tanpa mencetak. Dipanggil `LayananBukaLaci` yang mencatat log-nya.
  Future<void> BukaLaci() async {
    final profil = await _WajibProfil();
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).BukaLaci();
  }

  Future<void> CetakUji(ProfilPrinter profil) async {
    final identitas = await IdentitasStruk.Muat(repositori);
    await PrinterStruk(pembuatTransport(profil), profil.lebar, umpanAkhir: profil.umpanAkhir).CetakUji(
      namaUsaha: identitas.pengaturan.namaDicetak ?? identitas.namaUsaha,
      keterangan: 'Printer ${profil.alamat}:${profil.port}',
    );
  }

  Future<ProfilPrinter> _WajibProfil() async =>
      await AmbilProfil() ??
      (throw const GalatPrinter('Printer belum diatur. Buka Pengaturan, lalu atur printer struk.'));
}
