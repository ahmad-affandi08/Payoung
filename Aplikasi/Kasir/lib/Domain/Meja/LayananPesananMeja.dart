import 'package:drift/drift.dart' show Value;
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/PesananMeja.dart';
import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriPesananMeja.dart';
import '../GalatKasir.dart';
import '../Katalog/KatalogLokal.dart';
import '../Penjualan/Keranjang.dart';
import '../Penjualan/KonteksPenjualan.dart';
import '../Sesi/StafLokal.dart';
import 'KonteksPesananMeja.dart';

/// Pesanan terbuka di perangkat (Rincian F-07 mode meja & F-10b fase 1), berlaku offline. Aturan sama dengan server
/// agar kasir langsung tahu bila ditolak:
/// - buka pesanan butuh izin `penjualan.buat` atau `pesanan.meja.catat` (pelayan, v2.00); meja yang sudah punya
///   pesanan terbuka tidak bisa dibuka lagi (pakai gabung, v1.99); tanpa meja wajib label (nama pemesan/nomor antre);
/// - nomor `OB/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ4}` dibuat di perangkat (BR-07.1);
/// - baris append-only per ronde; "Kirim ke dapur" mengirim baris baru (+ baris tersimpan yang belum dikirim);
/// - BR-07.5: baris yang sudah dikirim ke dapur hanya batal dengan alasan (void item); kasir tanpa `penjualan.void`
///   butuh penyetuju; begitu juga membatalkan pesanan yang punya baris terkirim;
/// - setiap perubahan = baris lokal + outbox `PesananTerbuka.*` dalam satu transaksi SQLite (PRD §18.3 no. 3).
class LayananPesananMeja {
  LayananPesananMeja({
    required this.klien,
    required this.repositori,
    required this.repositoriMeja,
    PembuatUlid? ulid,
    DateTime Function()? jam,
  }) : _ulid = ulid ?? PembuatUlid(),
       _jam = jam ?? DateTime.now;

  static const String jenisBuka = 'PesananTerbuka.Buka';
  static const String jenisTambah = 'PesananTerbuka.Tambah';
  static const String jenisKirimDapur = 'PesananTerbuka.KirimDapur';
  static const String jenisBatalkanBaris = 'PesananTerbuka.BatalkanBaris';
  static const String jenisUbah = 'PesananTerbuka.Ubah';
  static const String jenisBatal = 'PesananTerbuka.Batal';
  static const String jenisPindahBaris = 'PesananTerbuka.PindahBaris';

  static const int panjangAlasanMinimal = 3;
  static const int panjangLabelMaksimal = 60;
  static const int tamuMaksimal = 999;

  final KlienPos klien;
  final RepositoriKasir repositori;
  final RepositoriPesananMeja repositoriMeja;
  final PembuatUlid _ulid;
  final DateTime Function() _jam;

  // Buka & ubah --------------------------------------------------------------------------------------------------------

  Future<PesananMeja> Buka({
    required StafLokal kasir,
    required KonteksPenjualan k,
    BarisMeja? meja,
    String? label,
    int jumlahTamu = 1,
    String? uuid,
  }) async {
    if (!kasir.CekBolehCatatPesanan()) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin membuat pesanan.');
    }
    final rapi = _RapikanLabel(label);
    if (meja == null && rapi == null) {
      throw const GalatKasir('LabelDiperlukan', 'Isi nama pemesan atau nomor antre untuk pesanan tanpa meja.');
    }
    _ValidasiTamu(jumlahTamu);
    if (meja != null && await repositoriMeja.CariPesananDiMeja(meja.Uuid) != null) {
      throw GalatKasir('MejaTerisi', 'Meja ${meja.Nama} sudah punya pesanan terbuka. Buka pesanannya dari denah.');
    }
    final kodeOutlet = k.kodeOutlet ?? '';
    final kodePerangkat = k.kodePerangkat ?? '';
    if (kodeOutlet.isEmpty || kodePerangkat.isEmpty) {
      throw const GalatKasir(
        'DataAwalBelumLengkap',
        'Kode outlet atau perangkat belum ada di perangkat ini. Sambungkan ke internet agar data terbaru terunduh.',
      );
    }

    final sekarang = _jam().toUtc();
    final t = k.HitungTanggalBisnis(sekarang);
    final yymmdd = '${t.substring(2, 4)}${t.substring(5, 7)}${t.substring(8, 10)}';
    final uuidPesanan = uuid ?? _ulid.Buat();
    return repositoriMeja.SimpanPesananBaru(
      kodePerangkat: kodePerangkat,
      tanggal: yymmdd,
      sekarang: sekarang,
      susun: (urut) {
        final nomor = 'OB/$kodeOutlet/$yymmdd/$kodePerangkat-${urut.toString().padLeft(4, '0')}';
        return (
          pesanan: PesananTerbukaCompanion.insert(
            Uuid: uuidPesanan,
            Nomor: nomor,
            UuidMeja: Value(meja?.Uuid),
            NamaMeja: Value(meja?.Nama),
            Label: Value(rapi),
            JumlahTamu: Value(jumlahTamu),
            DibukaOleh: Value(kasir.nama),
            DibukaPada: sekarang,
            Status: StatusPesananMeja.terbuka,
            Baris: '[]',
            DiubahPada: sekarang,
          ),
          outbox: ItemOutbox(
            jenis: jenisBuka,
            uuid: uuidPesanan,
            data: {
              'Nomor': nomor,
              'UuidMeja': meja?.Uuid,
              'Label': rapi,
              'JumlahTamu': jumlahTamu,
              'UuidPengguna': kasir.uuid,
              'DibukaPada': sekarang.toIso8601String(),
            },
          ),
        );
      },
    );
  }

  /// Pindah meja / ubah label & jumlah tamu (header, last-writer-wins di server menurut `DiubahPada`).
  Future<PesananMeja> Ubah({
    required String uuidPesanan,
    required StafLokal kasir,
    required BarisMeja? meja,
    String? label,
    required int jumlahTamu,
  }) async {
    final rapi = _RapikanLabel(label);
    if (meja == null && rapi == null) {
      throw const GalatKasir('LabelDiperlukan', 'Isi nama pemesan atau nomor antre untuk pesanan tanpa meja.');
    }
    _ValidasiTamu(jumlahTamu);
    if (meja != null) {
      final lain = await repositoriMeja.CariPesananDiMeja(meja.Uuid);
      if (lain != null && lain.uuid != uuidPesanan) {
        throw GalatKasir('MejaTerisi', 'Meja ${meja.Nama} sudah punya pesanan terbuka.');
      }
    }
    final sekarang = _jam().toUtc();
    return _Simpan(
      uuidPesanan,
      (_) => PesananTerbukaCompanion(
        UuidMeja: Value(meja?.Uuid),
        NamaMeja: Value(meja?.Nama),
        Label: Value(rapi),
        JumlahTamu: Value(jumlahTamu),
        DiubahPada: Value(sekarang),
      ),
      [
        ItemOutbox(
          jenis: jenisUbah,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'UuidMeja': meja?.Uuid,
            'Label': rapi,
            'JumlahTamu': jumlahTamu,
            'UuidPengguna': kasir.uuid,
            'DiubahPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
  }

  /// K-12 (§9.1): tandai tamu minta tagihan ([minta] true) atau hapus tandanya. Waktu pertama dipertahankan supaya
  /// urutan meja yang menunggu bill tidak bergeser.
  Future<PesananMeja> AturMintaBill({
    required String uuidPesanan,
    required StafLokal kasir,
    required bool minta,
  }) async {
    final pesanan = await _CariTerbuka(uuidPesanan);
    final sekarang = _jam().toUtc();
    return _Simpan(
      uuidPesanan,
      (_) => PesananTerbukaCompanion(
        MintaBillPada: Value(minta ? (pesanan.mintaBillPada ?? sekarang) : null),
        DiubahPada: Value(sekarang),
      ),
      [
        ItemOutbox(
          jenis: jenisUbah,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'MintaBill': minta,
            'UuidPengguna': kasir.uuid,
            'DiubahPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
  }

  /// K-12: meja yang tagihannya sudah dibayar ditandai bersih dan siap dipakai lagi (outbox `Meja.Bersih`).
  Future<void> TandaiMejaBersih({required String uuidMeja, required StafLokal kasir}) async {
    final sekarang = _jam().toUtc();
    await repositoriMeja.TandaiBersih(
      uuidMeja,
      ItemOutbox(
        jenis: RepositoriPesananMeja.jenisMejaBersih,
        uuid: _ulid.Buat(),
        data: {'UuidMeja': uuidMeja, 'UuidPengguna': kasir.uuid, 'DibersihkanPada': sekarang.toIso8601String()},
      ),
      sekarang,
    );
  }

  // Baris ----------------------------------------------------------------------------------------------------------------

  /// Simpan baris baru [draf] ke pesanan sebagai satu ronde. [kirimDapur] = kirim ke dapur sekarang, sekaligus baris
  /// tersimpan yang belum dikirim (K-13: kecuali baris kursus lain yang sengaja ditahan). [kursus] = kursus baris baru
  /// ([KursusPesanan]). Diskon diberikan saat bayar, bukan di baris pesanan.
  Future<PesananMeja> SimpanBaris({
    required String uuidPesanan,
    required List<ItemKeranjang> draf,
    required StafLokal kasir,
    required bool kirimDapur,
    String? kursus,
  }) async {
    if (!kasir.CekBolehCatatPesanan()) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin menambah pesanan.');
    }
    if (draf.any((b) => b.diskon != null)) {
      throw const GalatKasir(
        'DiskonSaatBayar',
        'Diskon item pesanan meja diberikan saat bayar. Hapus diskon itemnya dulu.',
      );
    }
    final pesanan = await _CariTerbuka(uuidPesanan);
    final belumDikirim = kirimDapur
        ? pesanan.AmbilBarisAktif()
              .where((b) => !b.dikirimKeDapur && (b.kursus == null || b.kursus == kursus))
              .map((b) => b.uuid)
              .toList()
        : const <String>[];
    if (draf.isEmpty && belumDikirim.isEmpty) {
      throw GalatKasir(
        'TidakAdaItemBaru',
        kirimDapur ? 'Semua item sudah dikirim ke dapur.' : 'Tambahkan item baru dulu.',
      );
    }
    final sekarang = _jam().toUtc();
    final ronde = pesanan.AmbilRondeBerikutnya();
    final baru = [
      for (final b in draf)
        BarisPesananMeja(
          uuid: b.uuid,
          uuidProduk: b.uuidProduk,
          uuidProdukSatuan: b.uuidProdukSatuan,
          namaProduk: b.nama,
          jumlah: b.jumlah.KeString(),
          hargaSatuan: b.hargaSatuan.KeString(),
          hargaPilihan: b.AmbilHargaPilihan().KeString(),
          pilihan: [for (final p in b.pilihan) p.KeJson()],
          catatan: b.catatan,
          ronde: ronde,
          dikirimKeDapur: kirimDapur,
          kursus: kursus,
        ),
    ];
    final outbox = [
      if (draf.isNotEmpty)
        ItemOutbox(
          jenis: jenisTambah,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'Ronde': ronde,
            'KirimDapur': kirimDapur,
            'UuidPengguna': kasir.uuid,
            'DikirimPada': sekarang.toIso8601String(),
            'Baris': [
              for (final b in baru)
                {
                  'Uuid': b.uuid,
                  'UuidProduk': b.uuidProduk,
                  'UuidProdukSatuan': b.uuidProdukSatuan,
                  'Jumlah': b.jumlah,
                  'HargaSatuan': b.hargaSatuan,
                  'HargaPilihan': b.hargaPilihan,
                  'Pilihan': b.pilihan,
                  'Catatan': b.catatan,
                  if (b.kursus != null) 'Kursus': b.kursus,
                },
            ],
          },
        ),
      if (belumDikirim.isNotEmpty)
        ItemOutbox(
          jenis: jenisKirimDapur,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'Ronde': ronde,
            'UuidBaris': belumDikirim,
            'UuidPengguna': kasir.uuid,
            'DikirimPada': sekarang.toIso8601String(),
          },
        ),
    ];
    return _Simpan(
      uuidPesanan,
      (p) => PesananTerbukaCompanion(
        Baris: Value(
          RepositoriPesananMeja.SusunJsonBaris([
            for (final b in p.baris) belumDikirim.contains(b.uuid) ? b.Salin(dikirimKeDapur: true) : b,
            ...baru,
          ]),
        ),
        DiubahPada: Value(sekarang),
      ),
      outbox,
      sekarang,
    );
  }

  /// K-13 kursus yang masih ditahan (baris aktif berkursus yang belum dikirim), urut saji, dengan jumlah barisnya.
  static List<({String kursus, int jumlah})> AmbilKursusDitahan(Iterable<BarisPesananMeja> baris) => [
    for (final k in KursusPesanan.semua)
      if (baris.where((b) => !b.dibatalkan && !b.dikirimKeDapur && b.kursus == k).length case final n when n > 0)
        (kursus: k, jumlah: n),
  ];

  /// K-13 "kirim" (fire) kursus yang ditahan: semua baris aktif kursus [kursus] yang belum dikirim dikirim ke dapur
  /// sebagai ronde baru (outbox `PesananTerbuka.KirimDapur`). Mengembalikan pesanan & Uuid baris yang dikirim (untuk
  /// cetak tiket dapur).
  Future<({PesananMeja pesanan, List<String> uuidBaris})> KirimKursus({
    required String uuidPesanan,
    required String kursus,
    required StafLokal kasir,
  }) async {
    if (!kasir.CekBolehCatatPesanan()) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin mengirim pesanan.');
    }
    final pesanan = await _CariTerbuka(uuidPesanan);
    final kirim = pesanan.AmbilBarisAktif()
        .where((b) => !b.dikirimKeDapur && b.kursus == kursus)
        .map((b) => b.uuid)
        .toList();
    if (kirim.isEmpty) {
      throw GalatKasir('TidakAdaItemBaru', 'Tidak ada item $kursus yang ditahan.');
    }
    final sekarang = _jam().toUtc();
    final ronde = pesanan.AmbilRondeBerikutnya();
    final hasil = await _Simpan(
      uuidPesanan,
      (p) => PesananTerbukaCompanion(
        Baris: Value(
          RepositoriPesananMeja.SusunJsonBaris([
            for (final b in p.baris) kirim.contains(b.uuid) ? b.Salin(dikirimKeDapur: true, ronde: ronde) : b,
          ]),
        ),
        DiubahPada: Value(sekarang),
      ),
      [
        ItemOutbox(
          jenis: jenisKirimDapur,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'Ronde': ronde,
            'UuidBaris': kirim,
            'UuidPengguna': kasir.uuid,
            'DikirimPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
    return (pesanan: hasil, uuidBaris: kirim);
  }

  /// Baris terpilih yang sudah dikirim ke dapur → butuh alasan & penyetuju (BR-07.5).
  static bool CekAdaTerkirim(PesananMeja pesanan, Iterable<String> uuidBaris) =>
      pesanan.baris.any((b) => uuidBaris.contains(b.uuid) && !b.dibatalkan && b.dikirimKeDapur);

  /// Penyetuju efektif: penyetuju yang lolos PIN, atau kasir sendiri bila ia ber-izin `penjualan.void`.
  static StafLokal? AmbilPenyetujuEfektif(StafLokal kasir, StafLokal? penyetuju) =>
      penyetuju ?? (kasir.PunyaIzin(IzinKasir.penjualanVoid) ? kasir : null);

  Future<PesananMeja> BatalkanBaris({
    required String uuidPesanan,
    required List<String> uuidBaris,
    required StafLokal kasir,
    String? alasan,
    StafLokal? penyetuju,
    bool catatTerbuang = false,
  }) async {
    final pesanan = await _CariTerbuka(uuidPesanan);
    final dipilih = pesanan.AmbilBarisAktif().where((b) => uuidBaris.contains(b.uuid)).map((b) => b.uuid).toList();
    if (dipilih.isEmpty) {
      throw const GalatKasir('BarisTidakDitemukan', 'Item ini sudah dibatalkan.');
    }
    final rapi = alasan?.trim() ?? '';
    StafLokal? setuju;
    if (CekAdaTerkirim(pesanan, dipilih)) {
      if (rapi.runes.length < panjangAlasanMinimal) {
        throw const GalatKasir('AlasanWajib', 'Tulis alasan pembatalan item yang sudah dikirim ke dapur.');
      }
      setuju = AmbilPenyetujuEfektif(kasir, penyetuju);
      if (setuju == null) {
        throw const GalatKasir('PersetujuanDiperlukan', 'Pembatalan item yang sudah dikirim ke dapur wajib disetujui.');
      }
    }
    final sekarang = _jam().toUtc();
    return _Simpan(
      uuidPesanan,
      (p) => PesananTerbukaCompanion(
        Baris: Value(
          RepositoriPesananMeja.SusunJsonBaris([
            for (final b in p.baris) dipilih.contains(b.uuid) ? b.Salin(dibatalkan: true) : b,
          ]),
        ),
        DiubahPada: Value(sekarang),
      ),
      [
        ItemOutbox(
          jenis: jenisBatalkanBaris,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'UuidBaris': dipilih,
            'Alasan': rapi.isEmpty ? null : rapi,
            'UuidPengguna': kasir.uuid,
            'UuidPenyetuju': setuju == null || setuju.uuid == kasir.uuid ? null : setuju.uuid,
            // BR-07.5 opsi waste (v5.78): item yang sudah dibuat lalu dibatalkan mengurangi bahan sebagai bahan terbuang.
            // Hanya berarti untuk item yang sudah dikirim ke dapur, jadi tidak dikirim untuk item yang masih ditahan.
            if (catatTerbuang && CekAdaTerkirim(pesanan, dipilih)) 'CatatTerbuang': true,
            'DibatalkanPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
  }

  /// Batalkan seluruh pesanan (tamu batal pesan). Pesanan dengan baris terkirim butuh `penjualan.void`/penyetuju.
  Future<void> Batal({
    required String uuidPesanan,
    required String alasan,
    required StafLokal kasir,
    StafLokal? penyetuju,
  }) async {
    final pesanan = await _CariTerbuka(uuidPesanan);
    final rapi = alasan.trim();
    if (rapi.runes.length < panjangAlasanMinimal) {
      throw const GalatKasir('AlasanWajib', 'Tulis alasan pembatalan pesanan minimal 3 huruf.');
    }
    StafLokal? setuju;
    if (CekAdaTerkirim(pesanan, pesanan.baris.map((b) => b.uuid))) {
      setuju = AmbilPenyetujuEfektif(kasir, penyetuju);
      if (setuju == null) {
        throw const GalatKasir(
          'PersetujuanDiperlukan',
          'Pesanan yang sudah dikirim ke dapur wajib disetujui untuk dibatalkan.',
        );
      }
    }
    final sekarang = _jam().toUtc();
    await _Simpan(
      uuidPesanan,
      (_) => PesananTerbukaCompanion(Status: const Value(StatusPesananMeja.dibatalkan), DiubahPada: Value(sekarang)),
      [
        ItemOutbox(
          jenis: jenisBatal,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidPesanan,
            'Alasan': rapi,
            'UuidPengguna': kasir.uuid,
            'UuidPenyetuju': setuju == null || setuju.uuid == kasir.uuid ? null : setuju.uuid,
            'DibatalkanPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
  }

  // Pisah & gabung (v1.99) ------------------------------------------------------------------------------------------------

  /// Pindahkan baris aktif [uuidBaris] dari [uuidAsal] ke [uuidTujuan] (tanpa mengubah harga, ronde, status dapur).
  /// [tutupAsal] (gabung): asal yang tidak lagi punya baris aktif ditutup berstatus `Digabung`.
  Future<({PesananMeja asal, PesananMeja tujuan})> PindahBaris({
    required String uuidAsal,
    required String uuidTujuan,
    required List<String> uuidBaris,
    required StafLokal kasir,
    bool tutupAsal = false,
  }) async {
    if (!kasir.CekBolehCatatPesanan()) {
      throw GalatKasir('TanpaIzin', '${kasir.nama} tidak punya izin mengubah pesanan.');
    }
    if (uuidAsal == uuidTujuan) {
      throw const GalatKasir('TujuanSama', 'Pilih pesanan tujuan yang lain.');
    }
    final asal = await _CariTerbuka(uuidAsal);
    await _CariTerbuka(uuidTujuan);
    final dipilih = asal.AmbilBarisAktif().where((b) => uuidBaris.contains(b.uuid)).map((b) => b.uuid).toSet();
    if (dipilih.isEmpty) {
      throw const GalatKasir('BarisTidakDipilih', 'Pilih minimal satu item yang dipindah.');
    }
    final sekarang = _jam().toUtc();
    final hasil = await repositoriMeja.UbahDuaPesanan(
      uuidAsal,
      uuidTujuan,
      (a, t) {
        final pindah = a.baris.where((b) => dipilih.contains(b.uuid)).toList();
        final sisa = a.baris.where((b) => !dipilih.contains(b.uuid)).toList();
        final tutup = tutupAsal && !sisa.any((b) => !b.dibatalkan);
        return (
          asal: PesananTerbukaCompanion(
            Baris: Value(RepositoriPesananMeja.SusunJsonBaris(sisa)),
            Status: tutup ? const Value(StatusPesananMeja.digabung) : const Value.absent(),
            DiubahPada: Value(sekarang),
          ),
          tujuan: PesananTerbukaCompanion(
            Baris: Value(RepositoriPesananMeja.SusunJsonBaris([...t.baris, ...pindah])),
            DiubahPada: Value(sekarang),
          ),
        );
      },
      [
        ItemOutbox(
          jenis: jenisPindahBaris,
          uuid: _ulid.Buat(),
          data: {
            'UuidPesanan': uuidAsal,
            'UuidTujuan': uuidTujuan,
            'UuidBaris': dipilih.toList(),
            'TutupAsal': tutupAsal,
            'UuidPengguna': kasir.uuid,
            'DipindahPada': sekarang.toIso8601String(),
          },
        ),
      ],
      sekarang,
    );
    if (hasil == null) {
      throw const GalatKasir('PesananSudahDitutup', 'Salah satu pesanan sudah dibayar atau dibatalkan.');
    }
    return hasil;
  }

  /// Pisah tagihan: item terpilih dipindah ke pesanan baru tanpa meja (label wajib) yang dibayar terpisah. Minimal satu
  /// item tetap di pesanan asal.
  Future<({PesananMeja asal, PesananMeja baru})> Pisah({
    required String uuidAsal,
    required List<String> uuidBaris,
    required String label,
    required StafLokal kasir,
    required KonteksPenjualan k,
    int jumlahTamu = 1,
  }) async {
    final asal = await _CariTerbuka(uuidAsal);
    final aktif = asal.AmbilBarisAktif().map((b) => b.uuid).toSet();
    final dipilih = uuidBaris.where(aktif.contains).toSet();
    if (dipilih.isEmpty) {
      throw const GalatKasir('BarisTidakDipilih', 'Pilih item yang dibayar terpisah.');
    }
    if (dipilih.length == aktif.length) {
      throw const GalatKasir(
        'SemuaDipilih',
        'Sisakan minimal satu item di tagihan ini, atau bayar langsung tanpa pisah.',
      );
    }
    final baru = await Buka(kasir: kasir, k: k, label: label, jumlahTamu: jumlahTamu);
    final hasil = await PindahBaris(
      uuidAsal: uuidAsal,
      uuidTujuan: baru.uuid,
      uuidBaris: dipilih.toList(),
      kasir: kasir,
    );
    return (asal: hasil.asal, baru: hasil.tujuan);
  }

  /// Gabung: semua item aktif [uuidAsal] pindah ke [uuidTujuan], lalu asal ditutup (`Digabung`).
  Future<PesananMeja> Gabung({required String uuidAsal, required String uuidTujuan, required StafLokal kasir}) async {
    final asal = await _CariTerbuka(uuidAsal);
    final aktif = asal.AmbilBarisAktif().map((b) => b.uuid).toList();
    if (aktif.isEmpty) {
      throw const GalatKasir('PesananKosong', 'Pesanan ini belum punya item. Batalkan saja pesanannya.');
    }
    final hasil = await PindahBaris(
      uuidAsal: uuidAsal,
      uuidTujuan: uuidTujuan,
      uuidBaris: aktif,
      kasir: kasir,
      tutupAsal: true,
    );
    return hasil.tujuan;
  }

  // Keranjang bayar ----------------------------------------------------------------------------------------------------

  /// Keranjang yang dibayar/ditampilkan: baris aktif pesanan tersimpan (harga snapshot saat dipesan; pajak & satuan dari
  /// katalog) diikuti baris draf. Keranjang biasa dikembalikan apa adanya.
  static Keranjang SusunKeranjangEfektif(Keranjang draf, PesananMeja? pesanan, KatalogLokal katalog) {
    if (draf.pesananMeja == null || pesanan == null) {
      return draf;
    }
    final tersimpan = <ItemKeranjang>[];
    for (final b in pesanan.AmbilBarisAktif()) {
      final uuidProduk = b.uuidProduk;
      if (uuidProduk == null) {
        continue;
      }
      final produk = katalog.CariProduk(uuidProduk);
      final satuan = produk?.satuan.where((s) => s.uuid == b.uuidProdukSatuan).firstOrNull;
      tersimpan.add(
        ItemKeranjang(
          uuid: b.uuid,
          uuidProduk: uuidProduk,
          nama: b.namaProduk,
          uuidProdukSatuan: b.uuidProdukSatuan,
          namaSatuan: satuan?.nama,
          bolehDesimal: satuan?.bolehDesimal ?? false,
          jumlah: Kuantitas.Dari(b.jumlah),
          hargaSatuan: Uang.Dari(b.hargaSatuan),
          pilihan: [for (final p in b.pilihan) PilihanTerpilih.DariJson(p)],
          catatan: b.catatan,
          hargaTermasukPajak: produk?.hargaTermasukPajak,
          pajak: produk?.pajak ?? const [],
        ),
      );
    }
    return draf.Salin(baris: [...tersimpan, ...draf.baris], pesananMeja: () => KonteksPesananMeja.DariPesanan(pesanan));
  }

  // Server ---------------------------------------------------------------------------------------------------------------

  /// Unduh area, meja, dan status mode meja outlet. `false` = offline (data lokal terakhir tetap dipakai).
  Future<bool> PerbaruiDataMeja() async {
    try {
      await repositoriMeja.SimpanDataMeja(await klien.AmbilMeja());
      return true;
    } on GalatJaringan {
      return false;
    }
  }

  /// Tarik snapshot pesanan terbuka outlet (ETag: tidak berubah → tidak ada yang ditulis). `false` = offline.
  Future<bool> Tarik() async {
    try {
      final etag = await repositori.AmbilPengaturan(KunciPengaturan.etagPesananTerbuka);
      final snapshot = await klien.AmbilPesananTerbuka(etag: etag == null || etag.isEmpty ? null : etag);
      if (snapshot == null) {
        return true;
      }
      await repositoriMeja.TerapkanSnapshot(snapshot, _jam().toUtc());
      // Selama ada perubahan tertunda, ETag tidak disimpan: bila item itu ditolak server, snapshot berikutnya tetap
      // diunduh utuh dan salinan lokal kembali sama dengan server.
      final tertunda = await repositoriMeja.AmbilUuidPesananTertunda();
      final bersihTertunda = await repositoriMeja.AmbilUuidMejaBersihTertunda();
      await repositori.SimpanPengaturan(
        KunciPengaturan.etagPesananTerbuka,
        tertunda.isEmpty && bersihTertunda.isEmpty ? snapshot.etag ?? '' : '',
      );
      return true;
    } on GalatJaringan {
      return false;
    }
  }

  /// Kunci bayar online (2 menit) agar perangkat lain tidak membayar pesanan yang sama. Offline atau pesanan belum
  /// sampai server → lanjut (bayar ganda offline ditangani server dengan `PerluTinjauan`).
  Future<void> KunciBayar(String uuidPesanan) async {
    try {
      await klien.KunciBayar(uuidPesanan);
    } on GalatApi catch (galat) {
      if (galat.kode == 'PesananSedangDibayar' || galat.kode == 'PesananSudahDitutup') {
        throw GalatKasir(galat.kode, galat.pesan);
      }
    } on GalatJaringan {
      return;
    }
  }

  Future<void> LepasKunciBayar(String uuidPesanan) async {
    try {
      await klien.LepasKunciBayar(uuidPesanan);
    } on GalatApi {
      return;
    } on GalatJaringan {
      return;
    }
  }

  // Bantuan ------------------------------------------------------------------------------------------------------------

  Future<PesananMeja> _CariTerbuka(String uuid) async {
    final pesanan = await repositoriMeja.CariPesanan(uuid);
    if (pesanan == null || pesanan.status != StatusPesananMeja.terbuka) {
      throw const GalatKasir('PesananSudahDitutup', 'Pesanan ini sudah dibayar atau dibatalkan.');
    }
    return pesanan;
  }

  Future<PesananMeja> _Simpan(
    String uuid,
    PesananTerbukaCompanion Function(PesananMeja pesanan) ubah,
    List<ItemOutbox> outbox,
    DateTime sekarang,
  ) async {
    final hasil = await repositoriMeja.UbahPesanan(uuid, ubah, outbox, sekarang);
    if (hasil == null) {
      throw const GalatKasir('PesananSudahDitutup', 'Pesanan ini sudah dibayar atau dibatalkan.');
    }
    return hasil;
  }

  static String? _RapikanLabel(String? label) {
    final rapi = label?.trim();
    if (rapi == null || rapi.isEmpty) {
      return null;
    }
    if (rapi.runes.length > panjangLabelMaksimal) {
      throw const GalatKasir('LabelTerlaluPanjang', 'Nama pesanan paling panjang 60 karakter.');
    }
    return rapi;
  }

  static void _ValidasiTamu(int jumlahTamu) {
    if (jumlahTamu < 1 || jumlahTamu > tamuMaksimal) {
      throw const GalatKasir('JumlahTamuTidakValid', 'Jumlah tamu 1 sampai 999.');
    }
  }
}
