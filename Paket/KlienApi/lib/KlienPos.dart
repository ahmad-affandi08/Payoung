import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';

import 'Galat/GalatApi.dart';
import 'Model/ModelBantuan.dart';
import 'Model/ModelBengkel.dart';
import 'Model/ModelKatalog.dart';
import 'Model/ModelGudang.dart';
import 'Model/ModelKonfigurasi.dart';
import 'Model/ModelLaundry.dart';
import 'Model/ModelMeja.dart';
import 'Model/ModelPembayaranDigital.dart';
import 'Model/ModelPelanggan.dart';
import 'Model/ModelPersetujuan.dart';
import 'Model/ModelPos.dart';
import 'Model/ModelPreOrder.dart';
import 'Model/ModelPromo.dart';
import 'Model/ModelReservasi.dart';
import 'Model/ModelRetur.dart';
import 'Model/ModelSalesman.dart';
import 'Model/ModelStok.dart';
import 'Model/ModelTokoOnline.dart';
import 'Model/UraiJson.dart';

/// Klien `/api/pos/v1` (PRD §16.1, §16.3) dengan device token (`Authorization: Bearer`), `X-Versi-Aplikasi`, dan
/// `X-Outbox-Tertunda` (P-10 BR-P10.2: jumlah transaksi belum terkirim, bila [ambilJumlahOutbox] diberikan).
/// Galat server → `GalatApi`; server tak terjangkau, waktu habis, atau 5xx → `GalatJaringan` (aman dicoba lagi).
class KlienPos {
  KlienPos({
    required this.alamatDasar,
    required this.versiAplikasi,
    required this.ambilToken,
    http.Client? klien,
    this.batasWaktu = const Duration(seconds: 20),
    this.ambilJumlahOutbox,
    this.saatPerangkatDitolak,
  }) : _klien = klien ?? http.Client();

  final Uri alamatDasar;
  final String versiAplikasi;
  final FutureOr<String?> Function() ambilToken;
  final Duration batasWaktu;
  final FutureOr<int?> Function()? ambilJumlahOutbox;

  /// Dipanggil setiap kali server menolak **token perangkat ini** (`PerangkatDicabut` 403 atau
  /// `TokenPerangkatTidakValid` 401) pada permintaan bertoken.
  ///
  /// Tanpa ini, pencabutan hanya ketahuan di alur sinkron & data awal: pemanggil lain (katalog, promo, data meja,
  /// konfigurasi aplikasi) sengaja menelan `GalatApi` supaya galat server tidak mengganggu kasir, sehingga
  /// perangkat yang sudah dicabut tetap bisa dipakai berjualan sampai aplikasi ditutup. Aktivasi tidak ikut
  /// memicunya karena permintaannya tidak memakai token.
  final void Function(GalatApi galat)? saatPerangkatDitolak;
  final http.Client _klien;
  final PembuatUlid _ulid = PembuatUlid();

  Future<HasilAktivasi> AktifkanPerangkat({required String kode, required String platform, String? versiOs}) async {
    final json = await _Kirim('POST', 'perangkat/aktivasi', {
      'Kode': kode.trim().toUpperCase(),
      'Platform': platform,
      'VersiAplikasi': versiAplikasi,
      'VersiOs': versiOs,
    }, pakaiToken: false);
    return HasilAktivasi.DariJson(json);
  }

  /// Versi terbaru/minimal, catatan rilis, dan flag fitur (§14.6, P-10).
  Future<KonfigurasiAplikasi> AmbilKonfigurasiAplikasi() async =>
      KonfigurasiAplikasi.DariJson(await _Kirim('GET', 'konfigurasi-aplikasi', null));

  Future<DataAwal> AmbilDataAwal() async => DataAwal.DariJson(await _Kirim('GET', 'data-awal', null));

  Future<HasilMasukPin> MasukPin({required String uuidPengguna, required String pin}) async =>
      HasilMasukPin.DariJson(await _Kirim('POST', 'kasir/masuk-pin', {'UuidPengguna': uuidPengguna, 'Pin': pin}));

  /// Katalog lengkap (tanpa [kursor]) atau delta sejak [kursor] (F-03 D.3). Kursor rusak/kedaluwarsa → `GalatApi`
  /// ber-kode `KursorTidakValid` (pemanggil lalu mengulang tanpa kursor).
  Future<KatalogPos> AmbilKatalog({String? kursor}) async => KatalogPos.DariJson(
    await _Kirim(
      'GET',
      kursor == null || kursor.isEmpty ? 'katalog' : 'katalog?sejak=${Uri.encodeQueryComponent(kursor)}',
      null,
    ),
  );

  /// Gambar kecil produk dari URL katalog. Host pada URL server sengaja tidak dipercaya: jalur POS dipasang kembali
  /// ke [alamatDasar] perangkat agar instalasi dengan `APP_URL` internal tetap bisa diakses tablet/kasir.
  Future<Uint8List?> AmbilGambarProduk(String url) async {
    final alamat = Uri.tryParse(url);
    const penanda = '/api/pos/v1/';
    final posisi = alamat?.path.indexOf(penanda) ?? -1;
    if (alamat == null || posisi < 0) {
      throw const GalatJaringan('Alamat gambar produk tidak valid. Perbarui katalog lalu coba lagi.');
    }
    final jalur = alamat.path.substring(posisi + penanda.length) + (alamat.hasQuery ? '?${alamat.query}' : '');
    final respons = await _KirimMentah('GET', jalur, null, terima: 'image/*');
    if (respons.statusCode == 404) {
      return null;
    }
    if (respons.statusCode >= 400) {
      throw _Galat(respons.statusCode, _UraiJson(respons.body), bertoken: true);
    }
    return respons.bodyBytes;
  }

  /// Gambar QRIS statis metode pembayaran (F-07b) sebagai bait gambar (PNG/JPEG) untuk ditampilkan di layar Bayar.
  Future<Uint8List> AmbilGambarQris(String uuidMetode) async {
    final respons = await _KirimMentah(
      'GET',
      'metode-pembayaran/${Uri.encodeComponent(uuidMetode)}/gambar-qris',
      null,
      terima: 'image/*',
    );
    if (respons.statusCode >= 400) {
      throw _Galat(respons.statusCode, _UraiJson(respons.body), bertoken: true);
    }
    return respons.bodyBytes;
  }

  /// Logo usaha untuk kepala struk (PRD v1.79). Tanpa logo/logo dimatikan → null (404).
  Future<Uint8List?> AmbilLogoStruk() async {
    final respons = await _KirimMentah('GET', 'logo-struk', null, terima: 'image/*');
    if (respons.statusCode == 404) {
      return null;
    }
    if (respons.statusCode >= 400) {
      throw _Galat(respons.statusCode, _UraiJson(respons.body), bertoken: true);
    }
    return respons.bodyBytes;
  }

  /// Struk asal untuk retur (F-09 fase 1): penjualan outlet perangkat dengan [nomor] persis, beserta jumlah & nilai yang
  /// masih bisa diretur. Tidak ada → `GalatApi` ber-kode `PenjualanTidakDitemukan` (404); offline → `GalatJaringan`.
  Future<HasilCariPenjualan> CariPenjualan(String nomor) async => HasilCariPenjualan.DariJson(
    await _Kirim('GET', 'penjualan/cari?nomor=${Uri.encodeQueryComponent(nomor.trim())}', null),
  );

  /// Shift perangkat ini yang masih terbuka di server (terlama dulu). Offline → `GalatJaringan`.
  Future<List<ShiftTerbukaServerPos>> AmbilShiftTerbukaServer() async {
    final json = await _Kirim('GET', 'shift/terbuka', null);
    return [for (final s in UraiJson.AmbilDaftarPeta(json['Shift'])) ShiftTerbukaServerPos.DariJson(s)];
  }

  /// Shift terbuka milik [uuidKasir] di perangkat lain pada outlet ini (BR-06.1). Kosong = aman membuka shift di
  /// sini. Offline → `GalatJaringan`.
  Future<List<ShiftKasirPerangkatLainPos>> CariShiftKasirDiPerangkatLain(String uuidKasir) async {
    final json = await _Kirim('GET', 'shift/kasir-terbuka?UuidPengguna=${Uri.encodeQueryComponent(uuidKasir)}', null);
    return [for (final s in UraiJson.AmbilDaftarPeta(json['Shift'])) ShiftKasirPerangkatLainPos.DariJson(s)];
  }

  /// Tutup paksa shift lama perangkat ini oleh supervisor ([uuidPenyetuju], PIN sudah diperiksa di perangkat) dengan
  /// [alasan] tertulis. Server menolak penyetuju tanpa izin `shift.selisih.setujui` (`PenyetujuTidakBerwenang`) dan shift
  /// yang sudah tertutup (`ShiftTidakAktif`).
  Future<void> TutupPaksaShift({
    required String uuidShift,
    required String uuidPenyetuju,
    required String alasan,
  }) async {
    await _Kirim('POST', 'shift/$uuidShift/tutup-paksa', {'UuidPenyetuju': uuidPenyetuju, 'Alasan': alasan});
  }

  /// Penjualan outlet yang masih bisa diretur untuk dipilih di layar retur: [kata] kosong = yang terbaru, selain itu
  /// nomor yang memuat kata itu (misal empat angka terakhir). Offline → `GalatJaringan`.
  Future<List<KandidatReturPos>> CariKandidatRetur(String kata) async {
    final json = await _Kirim('GET', 'penjualan/kandidat?kata=${Uri.encodeQueryComponent(kata.trim())}', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Penjualan'])) KandidatReturPos.DariJson(p)];
  }

  /// Cari pelanggan aktif tenant (F-16a): nama atau nomor HP, minimal 3 karakter (kurang = daftar kosong tanpa
  /// permintaan). Offline → `GalatJaringan`.
  Future<List<PelangganPos>> CariPelanggan(String kata) async {
    final rapi = kata.trim();
    if (rapi.length < 3) {
      return const [];
    }
    final json = await _Kirim('GET', 'pelanggan?kata=${Uri.encodeQueryComponent(rapi)}', null);
    // F-16c bagian 3: tanggal bisnis yang menjadi acuan hitungan harian `PemakaianPromo` (server lama: tidak ada).
    final tanggalBisnis = UraiJson.AmbilTeksAtauNull(json['TanggalBisnis']);
    return UraiJson.AmbilDaftarPeta(json['Pelanggan'])
        .map((p) => PelangganPos.DariJson(p, tanggalBisnis: tanggalBisnis))
        .toList();
  }

  /// Saldo poin terkini & aturan tukar sebelum kasir menukar poin (F-16b). Pelanggan tidak ada/diarsipkan → `GalatApi`
  /// ber-kode `PelangganTidakDitemukan` (404); offline → `GalatJaringan`.
  Future<SaldoPoinPos> AmbilSaldoPoin(String uuidPelanggan) async =>
      SaldoPoinPos.DariJson(await _Kirim('GET', 'pelanggan/${Uri.encodeComponent(uuidPelanggan)}/poin', null));

  /// Saldo deposit terkini sebelum kasir membayar dengan deposit (F-16d bagian 1, wajib online). Pelanggan tidak
  /// ada/diarsipkan → `GalatApi` `PelangganTidakDitemukan`.
  Future<SaldoDepositPos> AmbilSaldoDeposit(String uuidPelanggan) async =>
      SaldoDepositPos.DariJson(await _Kirim('GET', 'pelanggan/${Uri.encodeComponent(uuidPelanggan)}/deposit', null));

  /// Paket sesi aktif pelanggan (F-16d bagian 2, wajib online sebelum mencatat pemakaian sesi).
  Future<SaldoSesiPos> AmbilSaldoSesi(String uuidPelanggan) async =>
      SaldoSesiPos.DariJson(await _Kirim('GET', 'pelanggan/${Uri.encodeComponent(uuidPelanggan)}/sesi', null));

  /// Promo aktif tenant + mode resolusi konflik (F-16c); disimpan perangkat agar promo tetap berlaku saat offline.
  /// `?voucher=1`: aplikasi ini mengenal syarat `WajibVoucher` (F-16c bagian 2), jadi promo voucher ikut dikirim.
  /// `&lanjutan=1`: aplikasi ini juga mengenal syarat bagian 3 (metode bayar, ulang tahun, transaksi pertama, batas per
  /// pelanggan).
  Future<DataPromoPos> AmbilPromo() async =>
      DataPromoPos.DariJson(await _Kirim('GET', 'promo?voucher=1&lanjutan=1', null));

  /// Cari pre-order yang siap diambil di outlet perangkat (F-12 bagian 2): nomor atau nama/nomor HP pelanggan, minimal
  /// 3 karakter. Offline → `GalatJaringan`.
  Future<HasilCariPesananPenjualan> CariPesananPenjualan(String kata) async => HasilCariPesananPenjualan.DariJson(
    await _Kirim('GET', 'pesanan-penjualan?kata=${Uri.encodeQueryComponent(kata.trim())}', null),
  );

  /// Periksa & pesan kode voucher untuk penjualan [uuidPenjualan] yang sedang dibuat (F-16c bagian 2, wajib online).
  /// Ditolak → `GalatApi` ber-kode `VoucherTidakDitemukan` (404), `VoucherHabis` (409), `VoucherNonaktif`,
  /// `VoucherKedaluwarsa`, atau `PromoTidakBerlaku` (422); offline → `GalatJaringan`.
  Future<VoucherPos> PesanVoucher(String kode, String uuidPenjualan) async =>
      VoucherPos.DariJson(await _Kirim('POST', 'voucher/pesan', {'Kode': kode, 'UuidPenjualan': uuidPenjualan}));

  /// Lepas pesanan voucher (kasir menghapus voucher atau membatalkan transaksi). Idempoten.
  Future<void> LepasVoucher(String kode, String uuidPenjualan) async {
    await _Kirim('POST', 'voucher/lepas', {'Kode': kode, 'UuidPenjualan': uuidPenjualan});
  }

  /// Data meja outlet perangkat (F-07 mode meja fase 1).
  Future<DataMejaPos> AmbilMeja() async => DataMejaPos.DariJson(await _Kirim('GET', 'meja', null));

  /// Snapshot pesanan terbuka outlet. [etag] sama dengan server → `null` (tidak berubah, 304).
  Future<SnapshotPesananTerbuka?> AmbilPesananTerbuka({String? etag}) async {
    final respons = await _KirimMentah('GET', 'pesanan-terbuka', null, header: {'If-None-Match': ?etag});
    if (respons.statusCode == 304) {
      return null;
    }
    if (respons.statusCode >= 400) {
      throw _Galat(respons.statusCode, _UraiJson(respons.body), bertoken: true);
    }
    return SnapshotPesananTerbuka.DariJson(_UraiJson(respons.body), respons.headers['etag']);
  }

  /// Kunci bayar online (berlaku sebentar, diperpanjang saat layar Bayar terbuka). Perangkat lain sedang membayar →
  /// `GalatApi` ber-kode `PesananSedangDibayar` (409).
  Future<DateTime?> KunciBayar(String uuidPesanan) async {
    final json = await _Kirim('POST', 'pesanan-terbuka/${Uri.encodeComponent(uuidPesanan)}/kunci-bayar', null);
    return DateTime.tryParse(UraiJson.AmbilTeks(json['KunciBayarSampai']));
  }

  Future<void> LepasKunciBayar(String uuidPesanan) async {
    await _Kirim('DELETE', 'pesanan-terbuka/${Uri.encodeComponent(uuidPesanan)}/kunci-bayar', null);
  }

  /// Bengkel (§9.10): perintah kerja outlet perangkat. [semuaAktif] false = hanya yang siap ditagih (disetujui
  /// pelanggan & belum ditagih), true = semua yang masih berjalan. Hanya baris yang disetujui yang dikirim.
  Future<List<PerintahKerjaPos>> AmbilPerintahKerja({bool semuaAktif = false}) async {
    final json = await _Kirim('GET', 'perintah-kerja?status=${semuaAktif ? 'aktif' : 'siap-tagih'}', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['PerintahKerja'])) PerintahKerjaPos.DariJson(p)];
  }

  /// Bengkel (§9.10): satu perintah kerja outlet perangkat (outlet/tenant lain = `GalatApi` 404).
  Future<PerintahKerjaPos> AmbilSatuPerintahKerja(String uuid) async => PerintahKerjaPos.DariJson(
    UraiJson.AmbilPeta((await _Kirim('GET', 'perintah-kerja/${Uri.encodeComponent(uuid)}', null))['PerintahKerja']),
  );

  /// F-07 mode service bagian 2: reservasi outlet perangkat pada [tanggal] (`YYYY-MM-DD`, bawaan hari ini), urut jam.
  Future<List<ReservasiPos>> AmbilReservasi({String? tanggal}) async {
    final json = await _Kirim(
      'GET',
      tanggal == null ? 'reservasi' : 'reservasi?tanggal=${Uri.encodeQueryComponent(tanggal)}',
      null,
    );
    return [for (final r in UraiJson.AmbilDaftarPeta(json['Reservasi'])) ReservasiPos.DariJson(r)];
  }

  /// Tandai pelanggan reservasi sudah datang (idempoten). Reservasi selesai/dibatalkan → `GalatApi` 422.
  Future<ReservasiPos> HadirReservasi(String uuid, {required String uuidPengguna}) async => ReservasiPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'reservasi/${Uri.encodeComponent(uuid)}/hadir', {
        'UuidPengguna': uuidPengguna,
      }))['Reservasi'],
    ),
  );

  /// K-20: kalender booking [tanggal] (`YYYY-MM-DD`, bawaan hari ini): layanan, staf berjadwal, reservasi.
  Future<KalenderReservasiPos> AmbilKalenderReservasi({String? tanggal}) async => KalenderReservasiPos.DariJson(
    await _Kirim(
      'GET',
      tanggal == null ? 'reservasi/kalender' : 'reservasi/kalender?tanggal=${Uri.encodeQueryComponent(tanggal)}',
      null,
    ),
  );

  /// K-20: jam mulai kosong untuk [uuidLayanan] pada [tanggal]; [uuidStaf] null = staf mana saja.
  Future<List<SlotReservasiPos>> AmbilSlotReservasi({
    required String uuidLayanan,
    required String tanggal,
    String? uuidStaf,
  }) async {
    final kueri = [
      'UuidLayanan=${Uri.encodeQueryComponent(uuidLayanan)}',
      'Tanggal=${Uri.encodeQueryComponent(tanggal)}',
      if (uuidStaf != null) 'UuidStaf=${Uri.encodeQueryComponent(uuidStaf)}',
    ].join('&');
    final json = await _Kirim('GET', 'reservasi/slot?$kueri', null);
    return [for (final s in UraiJson.AmbilDaftarPeta(json['Slot'])) SlotReservasiPos.DariJson(s)];
  }

  /// K-20: buat booking dari kasir. Slot sudah terisi → `GalatApi` 409 `SlotTidakTersedia`.
  Future<ReservasiPos> BuatReservasi({
    required String uuidPengguna,
    required String uuidLayanan,
    required String tanggal,
    required String jam,
    String? uuidStaf,
    required String namaPelanggan,
    required String noHp,
    String? catatan,
  }) async => ReservasiPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'reservasi', {
        'UuidPengguna': uuidPengguna,
        'UuidLayanan': uuidLayanan,
        'Tanggal': tanggal,
        'Jam': jam,
        'UuidStaf': uuidStaf,
        'NamaPelanggan': namaPelanggan,
        'NoHp': noHp,
        'Catatan': catatan,
      }))['Reservasi'],
    ),
  );

  /// Laundry (§9.9): cucian aktif outlet perangkat; [kata] kosong = siap diambil, selain itu cari nomor/nama/HP.
  Future<List<TiketLaundryPos>> CariLaundry({String kata = ''}) async {
    final rapi = kata.trim();
    final json = await _Kirim('GET', rapi.isEmpty ? 'laundry' : 'laundry?kata=${Uri.encodeQueryComponent(rapi)}', null);
    return [for (final t in UraiJson.AmbilDaftarPeta(json['Tiket'])) TiketLaundryPos.DariJson(t)];
  }

  /// Ubah status proses cucian atau tandai diambil (idempoten). Mundur/tidak sah → `GalatApi` 409.
  Future<TiketLaundryPos> UbahStatusLaundry(
    String uuid, {
    required String status,
    required String uuidPengguna,
  }) async => TiketLaundryPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'laundry/${Uri.encodeComponent(uuid)}/status', {
        'Status': status,
        'UuidPengguna': uuidPengguna,
      }))['Tiket'],
    ),
  );

  /// X4: minta persetujuan jarak jauh (idempoten per [uuid]). [izin] null = khusus pemilik. [rincian] ditampilkan apa
  /// adanya di Aplikasi Owner. Fitur paket belum aktif → `GalatApi` `FiturTidakTersedia` (403).
  Future<PermintaanPersetujuanPos> AjukanPersetujuanJarakJauh({
    required String uuid,
    required String? izin,
    required String uuidPengguna,
    required String judul,
    required List<({String label, String nilai})> rincian,
    String? nilai,
  }) async => PermintaanPersetujuanPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'persetujuan/jarak-jauh', {
        'Uuid': uuid,
        'Izin': izin,
        'UuidPengguna': uuidPengguna,
        'Judul': judul,
        'Rincian': [
          for (final r in rincian) {'Label': r.label, 'Nilai': r.nilai},
        ],
        'Nilai': ?nilai,
      }, kunciIdempotensi: 'persetujuan-$uuid'))['Persetujuan'],
    ),
  );

  Future<PermintaanPersetujuanPos> AmbilPersetujuanJarakJauh(String uuid) async => PermintaanPersetujuanPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('GET', 'persetujuan/jarak-jauh/${Uri.encodeComponent(uuid)}', null))['Persetujuan'],
    ),
  );

  Future<PermintaanPersetujuanPos> BatalkanPersetujuanJarakJauh(String uuid) async => PermintaanPersetujuanPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'persetujuan/jarak-jauh/${Uri.encodeComponent(uuid)}/batal', null))['Persetujuan'],
    ),
  );

  /// POS-25 modul Gudang: PO siap diterima di lokasi outlet perangkat ([kata] = cari nomor PO).
  Future<List<PesananGudangPos>> AmbilPesananGudang({String kata = ''}) async {
    final rapi = kata.trim();
    final json = await _Kirim(
      'GET',
      rapi.isEmpty ? 'gudang/pesanan-pembelian' : 'gudang/pesanan-pembelian?kata=${Uri.encodeQueryComponent(rapi)}',
      null,
    );
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Pesanan'])) PesananGudangPos.DariJson(p)];
  }

  /// Posting penerimaan barang (GRN) dari PO. [kunciIdempotensi] tetap per draf, sehingga kirim ulang setelah koneksi
  /// putus diputar ulang server (tidak menggandakan GRN). [baris] = `{Urutan, Jumlah, NomorBatch?, TanggalKedaluwarsa?,
  /// NomorSeri?}`.
  Future<HasilPenerimaanGudang> TerimaBarangGudang({
    required String uuidPesanan,
    required String uuidPengguna,
    required String kunciIdempotensi,
    required List<Map<String, Object?>> baris,
    String? nomorSuratJalan,
    String? catatan,
  }) async => HasilPenerimaanGudang.DariJson(
    await _Kirim('POST', 'gudang/penerimaan', {
      'UuidPesananPembelian': uuidPesanan,
      'UuidPengguna': uuidPengguna,
      'NomorSuratJalan': ?nomorSuratJalan,
      'Catatan': ?catatan,
      'Baris': baris,
    }, kunciIdempotensi: kunciIdempotensi),
  );

  /// Transfer stok yang sedang menuju lokasi outlet perangkat.
  Future<List<TransferGudangPos>> AmbilTransferMasuk() async {
    final json = await _Kirim('GET', 'gudang/transfer', null);
    return [for (final t in UraiJson.AmbilDaftarPeta(json['Transfer'])) TransferGudangPos.DariJson(t)];
  }

  /// Terima transfer (boleh sebagian); [baris] = `{Urutan, Jumlah}` satuan dasar. Hasil: transfer terbaru.
  Future<TransferGudangPos> TerimaTransfer(
    String uuid, {
    required String uuidPengguna,
    required String kunciIdempotensi,
    required List<Map<String, Object?>> baris,
  }) async => TransferGudangPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'gudang/transfer/${Uri.encodeComponent(uuid)}/terima', {
        'UuidPengguna': uuidPengguna,
        'Baris': baris,
      }, kunciIdempotensi: kunciIdempotensi))['Transfer'],
    ),
  );

  /// Stok opname yang sedang berlangsung di lokasi outlet perangkat.
  Future<List<OpnameGudangPos>> AmbilOpname() async {
    final json = await _Kirim('GET', 'gudang/opname', null);
    return [for (final o in UraiJson.AmbilDaftarPeta(json['Opname'])) OpnameGudangPos.DariJson(o)];
  }

  /// Simpan lembar hitung; [hitung] = `{Urutan, JumlahFisik}` (baris lama) atau `{UuidProduk, JumlahFisik}` (produk
  /// baru hasil pindai). Hasil: opname terbaru.
  Future<OpnameGudangPos> SimpanHitungOpname(
    String uuid, {
    required String uuidPengguna,
    required String kunciIdempotensi,
    required List<Map<String, Object?>> hitung,
  }) async => OpnameGudangPos.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'gudang/opname/${Uri.encodeComponent(uuid)}/hitung', {
        'UuidPengguna': uuidPengguna,
        'Hitung': hitung,
      }, kunciIdempotensi: kunciIdempotensi))['Opname'],
    ),
  );

  /// Modul Salesman: satu halaman pelanggan aktif (50 per halaman, urut nama) beserta posisi kredit. [kata] kosong =
  /// semua (unduh awal cache offline halaman demi halaman). Pelaku = [uuidPengguna] di header `X-Id-Kasir`; tanpa izin
  /// `salesman.kunjungan` → `GalatApi` 403 `TanpaIzin`; offline → `GalatJaringan`.
  Future<HalamanPelangganSalesman> AmbilPelangganSalesman({
    required String uuidPengguna,
    String kata = '',
    int halaman = 1,
  }) async {
    final kueri = ['halaman=$halaman', if (kata.trim().isNotEmpty) 'kata=${Uri.encodeQueryComponent(kata.trim())}'];
    return HalamanPelangganSalesman.DariJson(
      await _Kirim('GET', 'salesman/pelanggan?${kueri.join('&')}', null, header: {'X-Id-Kasir': uuidPengguna}),
    );
  }

  /// Modul Salesman: piutang terbuka satu pelanggan, jatuh tempo terdekat dulu (wajib online).
  Future<List<PiutangSalesmanPos>> AmbilPiutangSalesman(String uuidPelanggan, {required String uuidPengguna}) async {
    final json = await _Kirim(
      'GET',
      'salesman/pelanggan/${Uri.encodeComponent(uuidPelanggan)}/piutang',
      null,
      header: {'X-Id-Kasir': uuidPengguna},
    );
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Piutang'])) PiutangSalesmanPos.DariJson(p)];
  }

  /// Modul Salesman: stok tersedia per produk di lokasi Toko outlet perangkat (petunjuk, tanpa nilai/HPP).
  Future<StokSalesmanPos> AmbilStokSalesman({required String uuidPengguna}) async =>
      StokSalesmanPos.DariJson(await _Kirim('GET', 'salesman/stok', null, header: {'X-Id-Kasir': uuidPengguna}));

  /// Modul Salesman: kunjungan pelaku sendiri pada [tanggal] (`YYYY-MM-DD`; bawaan hari ini zona waktu outlet).
  Future<DaftarKunjunganSalesman> AmbilKunjunganSalesman({required String uuidPengguna, String? tanggal}) async =>
      DaftarKunjunganSalesman.DariJson(
        await _Kirim(
          'GET',
          tanggal == null ? 'salesman/kunjungan' : 'salesman/kunjungan?tanggal=${Uri.encodeQueryComponent(tanggal)}',
          null,
          header: {'X-Id-Kasir': uuidPengguna},
        ),
      );

  /// F-17 self-order (v2.02): pesanan QR meja outlet perangkat yang menunggu konfirmasi, terlama dulu.
  Future<List<PesananSendiriPos>> AmbilPesanSendiri() async {
    final json = await _Kirim('GET', 'pesan-sendiri', null);
    return [for (final p in UraiJson.AmbilDaftarPeta(json['Pesanan'])) PesananSendiriPos.DariJson(p)];
  }

  /// Terima pesanan QR; baris lalu dicatat perangkat ke pesanan terbuka [uuidPesananTerbuka]. Sudah diproses perangkat
  /// lain → `GalatApi` ber-kode `SudahDiproses` (409); lewat 30 menit → `Kedaluwarsa`.
  Future<void> TerimaPesanSendiri(String uuid, {required String uuidPengguna, required String uuidPesananTerbuka}) =>
      _Kirim('POST', 'pesan-sendiri/${Uri.encodeComponent(uuid)}/terima', {
        'UuidPengguna': uuidPengguna,
        'UuidPesananTerbuka': uuidPesananTerbuka,
      });

  Future<void> TolakPesanSendiri(String uuid, {required String uuidPengguna, required String alasan}) => _Kirim(
    'POST',
    'pesan-sendiri/${Uri.encodeComponent(uuid)}/tolak',
    {'UuidPengguna': uuidPengguna, 'Alasan': alasan},
  );

  /// F-17 toko online: pesanan aktif outlet perangkat yang belum ditagihkan, beserta metode sistem "Uang muka (DP)"
  /// untuk pesanan yang sudah dibayar di muka (QRIS web). Perlu online.
  Future<HasilPesananOnline> AmbilPesananOnline() async =>
      HasilPesananOnline.DariJson(await _Kirim('GET', 'pesanan-online', null));

  /// Tautkan pesanan online [uuid] ke penjualan yang sudah lunas di server. Idempoten; pesanan yang sudah ditautkan ke
  /// penjualan lain → `GalatApi` ber-kode `SudahDitautkan` (409), pesanan ambil sendiri yang belum `Siap` →
  /// `PesananBelumSiap` (409).
  /// BR-17.3: ringkasan pesanan online outlet untuk polling 10 detik; [sejak] = `WaktuServer` polling sebelumnya.
  Future<RingkasPesananOnlinePos> AmbilRingkasPesananOnline({String? sejak}) async => RingkasPesananOnlinePos.DariJson(
    await _Kirim(
      'GET',
      sejak == null ? 'pesanan-online/ringkas' : 'pesanan-online/ringkas?sejak=${Uri.encodeQueryComponent(sejak)}',
      null,
    ),
  );

  /// BR-17.3: konfirmasi/tolak/proses/siap dari kasir; idempoten menurut status akhir. Mengembalikan status terkini.
  Future<String> UbahStatusPesananOnline(
    String uuid, {
    required String status,
    required String uuidPengguna,
    String? alasan,
  }) async {
    final json = await _Kirim('POST', 'pesanan-online/${Uri.encodeComponent(uuid)}/status', {
      'UuidPengguna': uuidPengguna,
      'Status': status,
      'Alasan': ?alasan,
    });
    return UraiJson.AmbilTeks(json['Status']);
  }

  Future<void> TautkanPesananOnline(String uuid, {required String uuidPenjualan}) =>
      _Kirim('POST', 'pesanan-online/${Uri.encodeComponent(uuid)}/tautkan', {'UuidPenjualan': uuidPenjualan});

  /// F-08 QRIS dinamis (v2.05): buat tagihan lewat gerbang aktif platform; idempoten per [uuid]. Gerbang belum aktif
  /// → `GalatApi` `GerbangBelumAktif` (409); gerbang menolak → `GerbangGagal` (502 dipetakan ke `GalatJaringan`).
  Future<TagihanQrisPos> BuatQris({
    required String uuid,
    required String uuidMetode,
    required String jumlah,
    String? keterangan,
  }) async => TagihanQrisPos.DariJson(
    await _Kirim('POST', 'qris', {'Uuid': uuid, 'UuidMetode': uuidMetode, 'Jumlah': jumlah, 'Keterangan': ?keterangan}),
  );

  Future<StatusQrisPos> AmbilStatusQris(String uuid) async =>
      StatusQrisPos.DariJson(await _Kirim('GET', 'qris/${Uri.encodeComponent(uuid)}', null));

  /// Batalkan tagihan yang belum dibayar; sudah lunas → `GalatApi` `SudahLunas` (409).
  Future<void> BatalkanQris(String uuid) => _Kirim('POST', 'qris/${Uri.encodeComponent(uuid)}/batal', null);

  /// v2.05: kirim struk digital penjualan yang sudah tersinkron lewat WhatsApp atau email (antre di server).
  Future<PesanKeluarPos> KirimStruk({
    required String uuidPenjualan,
    required String uuid,
    required String kanal,
    required String tujuan,
  }) async => PesanKeluarPos.DariJson(
    await _Kirim('POST', 'penjualan/${Uri.encodeComponent(uuidPenjualan)}/kirim-struk', {
      'Uuid': uuid,
      'Kanal': kanal,
      'Tujuan': tujuan,
    }),
  );

  Future<PesanKeluarPos> AmbilPesanKeluar(String uuid) async =>
      PesanKeluarPos.DariJson(await _Kirim('GET', 'pesan-keluar/${Uri.encodeComponent(uuid)}', null));

  /// Tiket dapur aktif outlet untuk KDS; [stasiun] kosong/null = semua stasiun.
  Future<DaftarTiketDapur> AmbilTiketDapur({List<String> stasiun = const []}) async {
    final kueri = stasiun.map((s) => 'stasiun[]=${Uri.encodeQueryComponent(s)}').join('&');
    return DaftarTiketDapur.DariJson(await _Kirim('GET', kueri.isEmpty ? 'dapur/tiket' : 'dapur/tiket?$kueri', null));
  }

  /// Ubah status tiket satu langkah (Antre → Dimasak → Siap → Disajikan, atau mundur satu langkah).
  Future<String> UbahStatusTiket(String uuidTiket, String status) async {
    final json = await _Kirim('POST', 'dapur/tiket/${Uri.encodeComponent(uuidTiket)}/status', {'Status': status});
    return UraiJson.AmbilTeks(json['Status'], status);
  }

  /// F-17 BR-17.2 ("86"): Uuid produk yang ditandai habis di outlet perangkat. Offline → `GalatJaringan`.
  Future<Set<String>> AmbilProdukHabis() async =>
      UraiJson.AmbilDaftarTeks((await _Kirim('GET', 'produk-habis', null))['Produk']).toSet();

  /// BR-05.2: sisa stok lokasi Toko outlet perangkat (satuan dasar) untuk produk berstok yang tidak boleh minus.
  /// Produk di luar daftar tidak dibatasi. Offline → `GalatJaringan`.
  Future<StokTersediaPos> AmbilStokTersedia() async =>
      StokTersediaPos.DariJson(await _Kirim('GET', 'stok-tersedia', null));

  /// K-19: batch bersisa produk di lokasi stok Toko outlet perangkat, urut FEFO. Offline → `GalatJaringan`.
  Future<BatchProdukPos> AmbilBatchProduk(String uuidProduk) async =>
      BatchProdukPos.DariJson(await _Kirim('GET', 'produk/${Uri.encodeComponent(uuidProduk)}/batch', null));

  /// Tandai produk habis ([habis] true) atau tersedia lagi di outlet perangkat (idempoten). Mengembalikan keadaan
  /// terbaru menurut server. Pelaku tanpa izin → `GalatApi` 403/422.
  Future<bool> UbahKetersediaanProduk(String uuidProduk, {required bool habis, required String uuidPengguna}) async {
    final json = await _Kirim('POST', 'produk/${Uri.encodeComponent(uuidProduk)}/habis', {
      'UuidPengguna': uuidPengguna,
      'Habis': habis,
    });
    return UraiJson.AmbilBenar(json['Habis'], habis);
  }

  /// K-24: ringkasan akhir hari outlet perangkat (semua perangkat) pada [tanggal] (`YYYY-MM-DD`, bawaan hari ini).
  Future<RingkasanHarianPos> AmbilRingkasanHarian({String? tanggal}) async => RingkasanHarianPos.DariJson(
    await _Kirim(
      'GET',
      tanggal == null ? 'ringkasan-harian' : 'ringkasan-harian?tanggal=${Uri.encodeQueryComponent(tanggal)}',
      null,
    ),
  );

  /// K-21: kirim log galat aplikasi (maks. 50 entri `{Waktu, Tingkat, Sumber, Pesan, Jejak?}`, sudah disaring dari
  /// data pribadi). Mengembalikan jumlah yang diterima server. Offline → `GalatJaringan`.
  Future<int> LaporGalat(List<Map<String, Object?>> galat) async =>
      UraiJson.AmbilBulat((await _Kirim('POST', 'perangkat/galat', {'Galat': galat}))['Diterima']);

  /// PGL-16 bagian 2: unggah log lokal yang diminta dukungan (maks. 500 baris teks, sudah disaring dari data pribadi).
  /// Mengembalikan jumlah baris yang diterima. Tidak ada permintaan yang berlaku → `GalatApi` 409 `LogTidakDiminta`.
  Future<int> UnggahLog(List<String> baris) async =>
      UraiJson.AmbilBulat((await _Kirim('POST', 'perangkat/log', {'Baris': baris}))['Diterima']);

  /// P-09: tiket bantuan staf [uuidPengguna] (hanya tiket yang dibuatnya sendiri). Perlu internet.
  Future<List<TiketBantuan>> DaftarTiketBantuan(String uuidPengguna) async => TiketBantuan.DaftarDariJson(
    (await _Kirim('GET', 'bantuan/tiket?UuidPengguna=${Uri.encodeQueryComponent(uuidPengguna)}', null))['Tiket'],
  );

  /// P-09: buat tiket bantuan. Konteks perangkat (outlet, versi, outbox tertunda) dilengkapi server; [layarAsal] dan
  /// [logSingkat] (baris log lokal yang sudah disaring dari data pribadi) dikirim apa adanya lalu disaring ulang server.
  Future<TiketBantuan> BuatTiketBantuan({
    required String uuidPengguna,
    required String kategori,
    required String judul,
    required String isi,
    String? prioritas,
    String? layarAsal,
    List<String> logSingkat = const [],
  }) async => TiketBantuan.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim('POST', 'bantuan/tiket', {
        'UuidPengguna': uuidPengguna,
        'Kategori': kategori,
        'Judul': judul,
        'Isi': isi,
        'Prioritas': ?prioritas,
        'LayarAsal': ?layarAsal,
        if (logSingkat.isNotEmpty) 'LogSingkat': logSingkat,
      }))['Tiket'],
    ),
  );

  /// P-09: percakapan satu tiket milik staf [uuidPengguna].
  Future<TiketBantuan> AmbilTiketBantuan(String uuidTiket, String uuidPengguna) async => TiketBantuan.DariJson(
    UraiJson.AmbilPeta(
      (await _Kirim(
        'GET',
        'bantuan/tiket/${Uri.encodeComponent(uuidTiket)}?UuidPengguna=${Uri.encodeQueryComponent(uuidPengguna)}',
        null,
      ))['Tiket'],
    ),
  );

  /// P-09: balas tiket sendiri. Mengembalikan percakapan terbaru.
  Future<TiketBantuan> BalasTiketBantuan(String uuidTiket, String uuidPengguna, String isi) async =>
      TiketBantuan.DariJson(
        UraiJson.AmbilPeta(
          (await _Kirim('POST', 'bantuan/tiket/${Uri.encodeComponent(uuidTiket)}/balas', {
            'UuidPengguna': uuidPengguna,
            'Isi': isi,
          }))['Tiket'],
        ),
      );

  /// Laporkan profil hardware & hasil Wizard Uji Perangkat (PRD v1.96) untuk dukungan teknis. Offline → `GalatJaringan`.
  Future<void> KirimProfilHardware(Map<String, Object?> profil) async {
    await _Kirim('POST', 'perangkat/profil-hardware', profil);
  }

  /// Kirim batch outbox (maks. 50) dan kembalikan hasil per item dalam urutan yang sama.
  Future<JawabanSinkron> KirimSinkron(List<ItemOutbox> item) async {
    final json = await _Kirim('POST', 'sinkron/kirim', {'Item': item.map((i) => i.toJson()).toList()});
    final hasil = json['Hasil'];
    return JawabanSinkron(
      hasil: hasil is List<Object?>
          ? hasil.whereType<Map<String, Object?>>().map(HasilItemSinkron.DariJson).toList()
          : const <HasilItemSinkron>[],
      perangkatDicabut: json['PerangkatDicabut'] == true,
      waktuServer: DateTime.tryParse(UraiJson.AmbilTeks(json['WaktuServer'])),
      perluTinjauan: UraiJson.AmbilDaftarTeks(json['PerluTinjauan']),
    );
  }

  Future<Map<String, Object?>> _Kirim(
    String metode,
    String jalur,
    Map<String, Object?>? isi, {
    bool pakaiToken = true,
    String? kunciIdempotensi,
    Map<String, String> header = const {},
  }) async {
    final respons = await _KirimMentah(
      metode,
      jalur,
      isi,
      pakaiToken: pakaiToken,
      header: {...header, 'Idempotency-Key': ?kunciIdempotensi},
    );
    final json = _UraiJson(respons.body);

    if (respons.statusCode >= 400) {
      throw _Galat(respons.statusCode, json, bertoken: pakaiToken);
    }

    return json;
  }

  /// Kirim permintaan dan kembalikan respons apa adanya; gagal jaringan & 5xx → `GalatJaringan`.
  Future<http.Response> _KirimMentah(
    String metode,
    String jalur,
    Map<String, Object?>? isi, {
    bool pakaiToken = true,
    String terima = 'application/json',
    Map<String, String> header = const {},
  }) async {
    final alamat = alamatDasar.resolve('api/pos/v1/$jalur');
    final permintaan = http.Request(metode, alamat)
      ..headers.addAll({'Accept': terima, 'X-Versi-Aplikasi': versiAplikasi, ...header});
    // Audit F-12 (aturan emas #13): setiap mutasi berperangkat membawa `Idempotency-Key` unik per permintaan (kirim
    // ulang di lapisan HTTP diputar ulang server). Item outbox tetap idempoten lewat Uuid-nya, sehingga "Coba lagi"
    // kasir memakai kunci baru dan benar-benar diproses ulang.
    if (pakaiToken && metode != 'GET' && !permintaan.headers.containsKey('Idempotency-Key')) {
      permintaan.headers['Idempotency-Key'] = 'pos-${_ulid.Buat()}';
    }

    if (pakaiToken) {
      final token = await ambilToken();
      if (token != null && token.isNotEmpty) {
        permintaan.headers['Authorization'] = 'Bearer $token';
      }
      final outbox = await ambilJumlahOutbox?.call();
      if (outbox != null) {
        permintaan.headers['X-Outbox-Tertunda'] = '$outbox';
      }
    }

    if (isi != null) {
      permintaan.headers['Content-Type'] = 'application/json';
      permintaan.body = jsonEncode(isi);
    }

    final http.Response respons;
    try {
      // Batas waktu mencakup pengunduhan badan juga, bukan hanya sampai header diterima.
      respons = await _klien.send(permintaan).then(http.Response.fromStream).timeout(batasWaktu);
    } on TimeoutException {
      throw const GalatJaringan('Server tidak menjawab. Periksa koneksi internet.');
    } on SocketException {
      throw const GalatJaringan('Tidak ada koneksi ke server.');
    } on http.ClientException catch (galat) {
      throw GalatJaringan(galat.message);
    }

    if (respons.statusCode >= 500) {
      throw GalatJaringan('Server sedang bermasalah (${respons.statusCode}). Data akan dikirim ulang otomatis.');
    }

    return respons;
  }

  static Map<String, Object?> _UraiJson(String isi) {
    if (isi.isEmpty || !isi.trimLeft().startsWith('{')) {
      return const <String, Object?>{};
    }
    try {
      final hasil = jsonDecode(isi);
      return hasil is Map<String, Object?> ? hasil : const <String, Object?>{};
    } on FormatException {
      return const <String, Object?>{};
    }
  }

  /// Bungkus [_BuatGalat] yang sekaligus melaporkan penolakan token perangkat ke [saatPerangkatDitolak].
  GalatApi _Galat(int status, Map<String, Object?> json, {required bool bertoken}) {
    final galat = _BuatGalat(status, json);
    if (bertoken && galat.CekPerangkatDitolak()) {
      saatPerangkatDitolak?.call(galat);
    }
    return galat;
  }

  static GalatApi _BuatGalat(int status, Map<String, Object?> json) {
    final galat = json['Galat'];
    if (galat is Map<String, Object?>) {
      final detail = galat['Detail'];
      return GalatApi(
        kode: galat['Kode'] is String ? galat['Kode']! as String : 'GalatServer',
        pesan: galat['Pesan'] is String ? galat['Pesan']! as String : 'Permintaan ditolak server.',
        statusHttp: status,
        bidang: galat['Bidang'] is String ? galat['Bidang']! as String : null,
        detail: detail is Map<String, Object?> ? detail : const <String, Object?>{},
      );
    }

    final kesalahan = json['errors'];
    if (kesalahan is Map<String, Object?> && kesalahan.isNotEmpty) {
      final pertama = kesalahan.entries.first;
      final pesan = pertama.value is List<Object?> && (pertama.value! as List<Object?>).isNotEmpty
          ? '${(pertama.value! as List<Object?>).first}'
          : 'Data tidak valid.';
      return GalatApi(kode: 'DataTidakValid', pesan: pesan, statusHttp: status, bidang: pertama.key);
    }

    return GalatApi(
      kode: status == 401 ? 'TokenPerangkatTidakValid' : 'GalatServer',
      pesan: json['message'] is String ? json['message']! as String : 'Permintaan ditolak server ($status).',
      statusHttp: status,
    );
  }
}
