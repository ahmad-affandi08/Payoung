import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:klien_api/KlienApi.dart';

import 'BasisData/BasisDataKasir.dart';

/// Status baris outbox lokal (PRD §18 no. 4).
abstract final class StatusOutbox {
  static const String tertunda = 'Tertunda';
  static const String perluTindakan = 'PerluTindakan';
}

/// Kunci tabel `Pengaturan` lokal.
abstract final class KunciPengaturan {
  static const String uuidPerangkat = 'UuidPerangkat';
  static const String kodePerangkat = 'KodePerangkat';

  /// K26: awalan huruf nomor antrian perangkat (kosong = nomor polos).
  static const String awalanAntrian = 'AwalanAntrian';
  static const String namaPerangkat = 'NamaPerangkat';
  static const String namaOutlet = 'NamaOutlet';
  static const String namaUsaha = 'NamaUsaha';
  static const String batasKasKeluar = 'BatasKasKeluar';
  static const String shiftBersama = 'ShiftBersama';
  static const String parameterPin = 'ParameterPin';
  static const String batasSalahPin = 'BatasSalahPin';
  static const String menitKunciPin = 'MenitKunciPin';
  static const String dataAwalPada = 'DataAwalPada';

  /// F-17 BR-17.2: JSON larik Uuid produk yang ditandai habis di outlet perangkat (salinan terakhir dari server), supaya
  /// tanda "Habis" tidak hilang saat aplikasi dimulai ulang dalam keadaan offline.
  static const String produkHabis = 'ProdukHabis';

  // F-07b/F-07c: identitas outlet untuk nomor penjualan, aturan diskon & pembulatan, profil pajak, katalog.
  static const String uuidOutlet = 'UuidOutlet';

  /// F-16c: JSON `DataPromoPos` (promo aktif + mode resolusi) dari `GET /api/pos/v1/promo`.
  static const String promo = 'Promo';

  /// F-17: `1` bila toko online melayani outlet perangkat ini (menu Pesanan toko online di kasir).
  static const String tokoOnlineAktif = 'TokoOnlineAktif';

  /// D-48: JSON larik kode sektor outlet (template + jenis usaha tambahan) untuk menyaring fitur khusus sektor.
  static const String kodeSektor = 'KodeSektor';

  /// v3.51: jenis pesanan outlet (JSON larik nama kanal) & bawaannya.
  static const String jenisPesanan = 'JenisPesanan';
  static const String jenisPesananBawaan = 'JenisPesananBawaan';

  /// K-8: mode kasir bawaan outlet dari template sektor (`Retail`/`Cepat`/`Meja`/`Layanan`/`Grosir`, kosong = belum).
  static const String modeKasirBawaan = 'ModeKasirBawaan';
  static const String kodeOutlet = 'KodeOutlet';
  static const String jamTutupBuku = 'JamTutupBuku';

  /// `Outlet.ZonaWaktu` (IANA) untuk tanggal bisnis & `YYMMDD` nomor penjualan (PRD v1.46 (d)).
  static const String zonaWaktu = 'ZonaWaktu';
  static const String batasDiskonManual = 'BatasDiskonManual';
  static const String batasDiskonPenyetuju = 'BatasDiskonPenyetuju';
  static const String pembulatanTunai = 'PembulatanTunai';
  static const String profilPajak = 'ProfilPajak';
  static const String kursorKatalog = 'KursorKatalog';
  static const String katalogDiperbaruiPada = 'KatalogDiperbaruiPada';

  // F-11: tutup shift buta ('1'/'0') & toleransi selisih kas (desimal).
  static const String tutupShiftButa = 'TutupShiftButa';
  static const String toleransiSelisihKas = 'ToleransiSelisihKas';

  /// F-09: batas hari retur sejak tanggal bisnis penjualan (bilangan bulat).
  static const String batasHariRetur = 'BatasHariRetur';

  /// K28: batas Σ retur tanpa struk outlet per hari (desimal).
  static const String batasReturTanpaStrukHarian = 'BatasReturTanpaStrukHarian';

  /// F-12 BR-12.1: batas hari lewat jatuh tempo piutang pelanggan sebelum penjualan tempo butuh penyetuju.
  static const String batasHariLewatJatuhTempo = 'BatasHariLewatJatuhTempo';

  /// Cetak struk bagian 4: buka laci manual wajib PIN supervisor ('1'/'0').
  static const String bukaLaciPerluPin = 'BukaLaciPerluPin';

  /// X4: fitur persetujuan jarak jauh aktif ('1'/'0').
  static const String persetujuanJarakJauh = 'PersetujuanJarakJauh';

  /// v3.55: barcode timbangan (JSON `BarcodeTimbanganPos`).
  static const String barcodeTimbangan = 'BarcodeTimbangan';

  /// F-16d bagian 1: pengaturan deposit pelanggan (JSON `{Berlaku, MinimalIsi, MaksimalIsi}`).
  static const String deposit = 'Deposit';

  /// Laundry (§9.9): pengaturan laundry (JSON `{Aktif, JamReguler, JamExpress, Parfum, AwalanLacak}`).
  static const String laundry = 'Laundry';

  /// F-18: daftar staf pelayan (JSON `[{Uuid, Nama, Jabatan}]`).
  static const String karyawan = 'Karyawan';

  /// Uuid shift yang baru ditutup dan laporan Z-nya belum ditutup kasir (bertahan bila aplikasi dimulai ulang).
  static const String laporanZTertunda = 'LaporanZTertunda';

  /// F-07 mode meja: jenis perangkat dari aktivasi (`Kasir`/`Pelayan`/`Kds`/`Gudang`), mode meja outlet ('1'/'0'), dan
  /// `ETag` snapshot pesanan terbuka terakhir.
  static const String jenisPerangkat = 'JenisPerangkat';
  static const String modeMejaAktif = 'ModeMejaAktif';

  /// D-144: JSON `{UuidProduk, Meja: {UuidMeja: "150000.00"}}` minimum charge per meja untuk kerja offline.
  static const String minimumChargeMeja = 'MinimumChargeMeja';
  static const String etagPesananTerbuka = 'EtagPesananTerbuka';

  /// F-10b: stasiun dapur yang ditampilkan perangkat KDS (Uuid dipisah koma; kosong = semua).
  static const String stasiunKds = 'StasiunKds';

  /// K-2 lanjutan: tampilan perangkat KDS (`Tiket` = layar dapur, `Antrian` = layar panggil antrian).
  static const String tampilanKds = 'TampilanKds';

  // Pengaturan lokal perangkat (D-16, §17.2.7). Tidak ikut diganti data awal dan tidak dihapus saat perangkat dicabut.
  static const String ukuranTampilan = 'UkuranTampilan';
  static const String posisiKeranjang = 'PosisiKeranjang';
  static const String menitKunciOtomatis = 'MenitKunciOtomatis';
  static const String tampilanKatalog = 'TampilanKatalog';

  /// K-17: waktu putaran sinkron terakhir yang dijawab server (ISO UTC).
  static const String sinkronTerakhir = 'SinkronTerakhir';

  /// K-17: selisih jam perangkat − jam server dalam detik (positif = jam perangkat lebih cepat).
  static const String selisihJamDetik = 'SelisihJamDetik';

  /// K-17: JSON daftar Uuid penjualan yang ditandai tinjauan back-office (50 terbaru).
  static const String penjualanPerluTinjauan = 'PenjualanPerluTinjauan';

  /// K-16: kategori katalog terakhir dipilih di layar Jual (Uuid kategori, `Terlaris`, atau kosong = Semua).
  static const String kategoriTerakhirJual = 'KategoriTerakhirJual';

  /// K-15: bunyi & getar saat memindai ('0' = mati; bawaan hidup).
  static const String umpanBalikPindai = 'UmpanBalikPindai';

  /// Kamera pemindai barcode di layar Jual: 'Depan' atau 'Belakang' (bawaan).
  static const String kameraPindai = 'KameraPindai';

  // Cetak struk (PRD v1.79): pengaturan & identitas struk dari data awal (JSON `StrukPos`), alamat & telepon outlet,
  // logo 1 bit (JSON `GambarMonokrom`), dan profil printer perangkat ini (JSON `ProfilPrinter`, lokal saja).
  static const String struk = 'Struk';
  static const String alamatOutlet = 'AlamatOutlet';
  static const String teleponOutlet = 'TeleponOutlet';
  static const String logoStruk = 'LogoStruk';

  /// D-16: logo merek outlet untuk bilah atas Ruang Kerja, apa adanya (base64 PNG/JPEG/WebP) — bukan diubah 1 bit
  /// seperti [logoStruk]. Server tidak mengirim logo = nilai kosong.
  static const String logoOutlet = 'LogoOutlet';
  static const String profilPrinter = 'ProfilPrinter';

  /// Cetak struk bagian 4c: stasiun dapur aktif, stasiun bawaan, dan peta kategori → stasiun dari data meja (JSON).
  static const String ruteDapur = 'RuteDapur';

  /// Cetak struk bagian 4c: printer tiket dapur per stasiun di perangkat ini (JSON; tidak ikut data awal).
  static const String printerDapur = 'PrinterDapur';

  /// v1.96: profil hardware & hasil Wizard Uji Perangkat terakhir (JSON) dan tanda belum terkirim ke server ("1").
  static const String profilHardware = 'ProfilHardware';
  static const String profilHardwareTertunda = 'ProfilHardwareTertunda';

  /// v2.01: layar pelanggan perangkat ini (JSON `{Mode, PortVfd}`; tidak ikut data awal).
  static const String layarPelanggan = 'LayarPelanggan';

  /// Modul Salesman bagian 2: waktu unduhan lengkap cache pelanggan salesman terakhir (ISO UTC) dan snapshot stok kantor
  /// (JSON `{DiambilPada, Stok: {UuidProduk: Jumlah}}`).
  static const String pelangganSalesmanDiperbaruiPada = 'PelangganSalesmanDiperbaruiPada';
  static const String stokSalesman = 'StokSalesman';

  /// BR-05.2: salinan sisa stok lokasi Toko dari `GET /api/pos/v1/stok-tersedia` (JSON `{DiambilPada, WaktuServer,
  /// Produk: {UuidProduk: Jumlah}, BelumTercakup: [UuidPenjualan]}`). Satu blob, tanpa tabel baru.
  static const String stokTersedia = 'StokTersedia';
}

/// Status shift lokal (sama dengan server).
abstract final class StatusShiftLokal {
  static const String terbuka = 'Terbuka';
  static const String tertutup = 'Tertutup';
}

/// Akses basis data lokal kasir. Setiap perubahan dokumen menulis dokumen + entri outbox dalam satu transaksi
/// SQLite (PRD §18 no. 3), sehingga tidak ada data tersimpan tanpa antrean kirim.
class RepositoriKasir {
  RepositoriKasir(this.db);

  final BasisDataKasir db;

  // Pengaturan -------------------------------------------------------------------------------------------------------

  Future<String?> AmbilPengaturan(String kunci) async =>
      (await (db.select(db.pengaturan)..where((p) => p.Kunci.equals(kunci))).getSingleOrNull())?.Nilai;

  Stream<String?> PantauPengaturan(String kunci) =>
      (db.select(db.pengaturan)..where((p) => p.Kunci.equals(kunci))).watchSingleOrNull().map((b) => b?.Nilai);

  Future<void> SimpanPengaturan(String kunci, String nilai) =>
      db.into(db.pengaturan).insertOnConflictUpdate(PengaturanCompanion.insert(Kunci: kunci, Nilai: nilai));

  Future<void> HapusPengaturan(String kunci) => (db.delete(db.pengaturan)..where((p) => p.Kunci.equals(kunci))).go();

  Future<ParameterPin?> AmbilParameterPin() async {
    final teks = await AmbilPengaturan(KunciPengaturan.parameterPin);
    return teks == null ? null : ParameterPin.DariJson(jsonDecode(teks) as Map<String, Object?>);
  }

  /// Ganti staf, kategori kas, pengaturan kasir, tarif pajak, dan metode pembayaran dengan data awal terbaru dari
  /// server. Kunci F-07b yang absen (server lama) sudah diberi nilai bawaan oleh `DataAwal`.
  Future<void> SimpanDataAwal(DataAwal data, DateTime sekarang) => db.transaction(() async {
    await db.delete(db.staf).go();
    await db.delete(db.kategoriKas).go();
    await db.delete(db.tarifPajak).go();
    await db.delete(db.metodePembayaran).go();
    await db.batch((b) {
      b.insertAll(db.tarifPajak, [
        for (final t in data.tarifPajak)
          TarifPajakCompanion.insert(
            KodeJenisPajak: t.kodeJenisPajak,
            Tarif: t.tarif,
            PengaliDppPembilang: t.pengaliDppPembilang,
            PengaliDppPenyebut: t.pengaliDppPenyebut,
            BerlakuMulai: t.berlakuMulai,
            BerlakuSampai: Value(t.berlakuSampai),
          ),
      ]);
      b.insertAll(db.metodePembayaran, [
        for (final m in data.metodePembayaran)
          MetodePembayaranCompanion.insert(
            Uuid: m.uuid,
            Jenis: m.jenis,
            Nama: m.nama,
            NomorRekening: Value(m.nomorRekening),
            NamaPemilikRekening: Value(m.namaPemilikRekening),
            AdaGambarQris: m.adaGambarQris,
            Urutan: m.urutan,
            Kanal: Value(m.kanal),
            TeksQris: Value(m.teksQris),
          ),
      ]);
      b.insertAll(db.staf, [
        for (final s in data.staf)
          StafCompanion.insert(
            Uuid: s.uuid,
            Nama: s.nama,
            Pemilik: s.pemilik,
            Izin: jsonEncode(s.izin),
            PinDiatur: s.pinDiatur,
            PinGaram: Value(s.pin?.garam),
            PinNonce: Value(s.pin?.nonce),
            PinSandi: Value(s.pin?.sandi),
          ),
      ]);
      b.insertAll(db.kategoriKas, [
        for (final k in data.kategoriKas) KategoriKasCompanion.insert(Uuid: k.uuid, Nama: k.nama, Jenis: k.jenis),
      ]);
    });
    await SimpanPengaturan(KunciPengaturan.batasKasKeluar, data.batasKasKeluar);
    await SimpanPengaturan(KunciPengaturan.shiftBersama, data.shiftBersama ? '1' : '0');
    await SimpanPengaturan(
      KunciPengaturan.parameterPin,
      jsonEncode({
        'Iterasi': data.parameterPin.iterasi,
        'MemoriKiB': data.parameterPin.memoriKiB,
        'Paralelisme': data.parameterPin.paralelisme,
        'Panjang': data.parameterPin.panjang,
      }),
    );
    await SimpanPengaturan(KunciPengaturan.batasSalahPin, '${data.batasSalahPin}');
    await SimpanPengaturan(KunciPengaturan.menitKunciPin, '${data.menitKunciPin}');
    await SimpanPengaturan(KunciPengaturan.batasDiskonManual, data.batasDiskonManual);
    await SimpanPengaturan(KunciPengaturan.batasDiskonPenyetuju, data.batasDiskonPenyetuju);
    final pembulatan = data.pembulatanTunai;
    await SimpanPengaturan(
      KunciPengaturan.pembulatanTunai,
      pembulatan == null ? '' : jsonEncode({'Kelipatan': pembulatan.kelipatan, 'Arah': pembulatan.arah}),
    );
    await SimpanPengaturan(KunciPengaturan.profilPajak, jsonEncode(data.profilPajak.KeJson()));
    await SimpanPengaturan(KunciPengaturan.tutupShiftButa, data.tutupShiftButa ? '1' : '0');
    await SimpanPengaturan(KunciPengaturan.toleransiSelisihKas, data.toleransiSelisihKas);
    await SimpanPengaturan(KunciPengaturan.batasHariRetur, '${data.batasHariRetur}');
    await SimpanPengaturan(KunciPengaturan.batasReturTanpaStrukHarian, data.batasReturTanpaStrukHarian);
    await SimpanPengaturan(KunciPengaturan.batasHariLewatJatuhTempo, '${data.batasHariLewatJatuhTempo}');
    await SimpanPengaturan(KunciPengaturan.bukaLaciPerluPin, data.bukaLaciPerluPin ? '1' : '0');
    await SimpanPengaturan(KunciPengaturan.persetujuanJarakJauh, data.persetujuanJarakJauh ? '1' : '0');
    await SimpanPengaturan(KunciPengaturan.barcodeTimbangan, jsonEncode(data.barcodeTimbangan.KeJson()));
    await SimpanPengaturan(KunciPengaturan.karyawan, jsonEncode([for (final k in data.karyawan) k.KeJson()]));
    await SimpanPengaturan(KunciPengaturan.deposit, jsonEncode(data.deposit.KeJson()));
    await SimpanPengaturan(KunciPengaturan.laundry, jsonEncode(data.laundry.KeJson()));
    await SimpanPengaturan(KunciPengaturan.tokoOnlineAktif, data.tokoOnlineAktif ? '1' : '0');
    await SimpanPengaturan(KunciPengaturan.kodeSektor, jsonEncode(data.kodeSektor));
    final outlet = data.outlet;
    if (outlet != null) {
      await SimpanPengaturan(KunciPengaturan.uuidOutlet, outlet.uuid);
      await SimpanPengaturan(KunciPengaturan.kodeOutlet, outlet.kode);
      if (outlet.nama.isNotEmpty) {
        await SimpanPengaturan(KunciPengaturan.namaOutlet, outlet.nama);
      }
      await SimpanPengaturan(KunciPengaturan.alamatOutlet, outlet.alamat ?? '');
      await SimpanPengaturan(KunciPengaturan.teleponOutlet, outlet.telepon ?? '');
      await SimpanPengaturan(KunciPengaturan.jamTutupBuku, outlet.jamTutupBuku ?? '00:00');
      await SimpanPengaturan(KunciPengaturan.jenisPesanan, jsonEncode(outlet.jenisPesanan));
      await SimpanPengaturan(KunciPengaturan.jenisPesananBawaan, outlet.jenisPesananBawaan ?? '');
      await SimpanPengaturan(KunciPengaturan.modeKasirBawaan, outlet.modeKasirBawaan ?? '');
      final zona = outlet.zonaWaktu?.trim();
      if (zona != null && zona.isNotEmpty) {
        await SimpanPengaturan(KunciPengaturan.zonaWaktu, zona);
      }
    }
    final perangkat = data.perangkat;
    if (perangkat != null && perangkat.kode.isNotEmpty) {
      await SimpanPengaturan(KunciPengaturan.kodePerangkat, perangkat.kode);
      await SimpanPengaturan(KunciPengaturan.awalanAntrian, perangkat.awalanAntrian ?? '');
      // PRD v1.46 (e): sekuens lokal = max(lokal, server) per tanggal, agar pemasangan ulang tidak memakai nomor lama.
      for (final entri in perangkat.nomorUrutPenjualan.entries) {
        final lama = await (db.select(
          db.nomorUrutPenjualan,
        )..where((n) => n.KodePerangkat.equals(perangkat.kode) & n.Tanggal.equals(entri.key))).getSingleOrNull();
        if (lama == null || lama.Terakhir < entri.value) {
          await db
              .into(db.nomorUrutPenjualan)
              .insertOnConflictUpdate(
                NomorUrutPenjualanCompanion.insert(
                  KodePerangkat: perangkat.kode,
                  Tanggal: entri.key,
                  Terakhir: entri.value,
                ),
              );
        }
      }
      // F-16d: sekuens isi deposit (DEP) lokal = max(lokal, server) per tanggal.
      for (final entri in perangkat.nomorUrutIsiDeposit.entries) {
        final lama = await (db.select(
          db.nomorUrutIsiDeposit,
        )..where((n) => n.KodePerangkat.equals(perangkat.kode) & n.Tanggal.equals(entri.key))).getSingleOrNull();
        if (lama == null || lama.Terakhir < entri.value) {
          await db
              .into(db.nomorUrutIsiDeposit)
              .insertOnConflictUpdate(
                NomorUrutIsiDepositCompanion.insert(
                  KodePerangkat: perangkat.kode,
                  Tanggal: entri.key,
                  Terakhir: entri.value,
                ),
              );
        }
      }
      // F-09: sekuens retur (RJ) lokal = max(lokal, server) per tanggal, alasan sama dengan nomor penjualan.
      for (final entri in perangkat.nomorUrutRetur.entries) {
        final lama = await (db.select(
          db.nomorUrutReturPenjualan,
        )..where((n) => n.KodePerangkat.equals(perangkat.kode) & n.Tanggal.equals(entri.key))).getSingleOrNull();
        if (lama == null || lama.Terakhir < entri.value) {
          await db
              .into(db.nomorUrutReturPenjualan)
              .insertOnConflictUpdate(
                NomorUrutReturPenjualanCompanion.insert(
                  KodePerangkat: perangkat.kode,
                  Tanggal: entri.key,
                  Terakhir: entri.value,
                ),
              );
        }
      }
    }
    final struk = data.struk;
    if (struk != null) {
      await SimpanPengaturan(KunciPengaturan.struk, jsonEncode(struk.KeJson()));
      if (struk.namaUsaha.isNotEmpty) {
        await SimpanPengaturan(KunciPengaturan.namaUsaha, struk.namaUsaha);
      }
    }
    await SimpanPengaturan(KunciPengaturan.dataAwalPada, sekarang.toUtc().toIso8601String());
  });

  // Staf & kategori --------------------------------------------------------------------------------------------------

  Future<List<BarisStaf>> AmbilStaf() => (db.select(db.staf)..orderBy([(s) => OrderingTerm.asc(s.Nama)])).get();

  Future<BarisStaf?> CariStaf(String uuid) => (db.select(db.staf)..where((s) => s.Uuid.equals(uuid))).getSingleOrNull();

  Future<List<BarisKategoriKas>> AmbilKategori(String jenis) =>
      (db.select(db.kategoriKas)
            ..where((k) => k.Jenis.equals(jenis))
            ..orderBy([(k) => OrderingTerm.asc(k.Nama)]))
          .get();

  Future<BarisKategoriKas?> CariKategori(String uuid) =>
      (db.select(db.kategoriKas)..where((k) => k.Uuid.equals(uuid))).getSingleOrNull();

  // Shift & mutasi kas -----------------------------------------------------------------------------------------------

  Future<BarisShift?> AmbilShiftAktif() =>
      (db.select(db.shift)..where((s) => s.Status.equals(StatusShiftLokal.terbuka))).getSingleOrNull();

  Stream<BarisShift?> PantauShiftAktif() =>
      (db.select(db.shift)..where((s) => s.Status.equals(StatusShiftLokal.terbuka))).watchSingleOrNull();

  Future<List<BarisMutasiKas>> AmbilMutasi(String uuidShift) =>
      (db.select(db.mutasiKas)
            ..where((m) => m.UuidShift.equals(uuidShift))
            ..orderBy([(m) => OrderingTerm.desc(m.DicatatPada)]))
          .get();

  Stream<List<BarisMutasiKas>> PantauMutasi(String uuidShift) =>
      (db.select(db.mutasiKas)
            ..where((m) => m.UuidShift.equals(uuidShift))
            ..orderBy([(m) => OrderingTerm.desc(m.DicatatPada)]))
          .watch();

  /// Simpan shift baru + entri outbox `Shift.Buka` dalam satu transaksi.
  Future<void> SimpanShiftBaru(ShiftCompanion shift, ItemOutbox item, DateTime sekarang) => db.transaction(() async {
    await db.into(db.shift).insert(shift);
    await TambahOutbox(item, sekarang);
  });

  Stream<BarisShift?> PantauShift(String uuid) =>
      (db.select(db.shift)..where((s) => s.Uuid.equals(uuid))).watchSingleOrNull();

  Future<BarisShift?> CariShift(String uuid) =>
      (db.select(db.shift)..where((s) => s.Uuid.equals(uuid))).getSingleOrNull();

  /// Tutup shift (F-11): isi kolom tutup + status `Tertutup` + entri outbox `Shift.Tutup` + penanda laporan Z dalam
  /// satu transaksi. Hanya shift yang masih `Terbuka` yang diubah (ditutup dua kali = galat).
  Future<void> SimpanTutupShift(String uuid, ShiftCompanion tutup, ItemOutbox item, DateTime sekarang) =>
      db.transaction(() async {
        final diubah = await (db.update(
          db.shift,
        )..where((s) => s.Uuid.equals(uuid) & s.Status.equals(StatusShiftLokal.terbuka))).write(tutup);
        if (diubah != 1) {
          throw StateError('Shift $uuid tidak terbuka.');
        }
        await TambahOutbox(item, sekarang);
        await SimpanPengaturan(KunciPengaturan.laporanZTertunda, uuid);
      });

  /// Shift perangkat ini yang terakhir ditutup (K-18: kandidat buka ulang).
  Future<BarisShift?> AmbilShiftTertutupTerakhir() =>
      (db.select(db.shift)
            ..where((s) => s.Status.equals(StatusShiftLokal.tertutup))
            ..orderBy([(s) => OrderingTerm.desc(s.DitutupPada)])
            ..limit(1))
          .getSingleOrNull();

  /// Buka ulang shift (K-18): status kembali `Terbuka`, data tutup dikosongkan (salinannya disimpan server), entri
  /// outbox `Shift.BukaUlang`, dan penanda laporan Z shift itu dibersihkan, dalam satu transaksi. Hanya shift yang
  /// `Tertutup` dan bila perangkat tidak punya shift terbuka lain.
  Future<void> SimpanBukaUlangShift(String uuid, ItemOutbox item, DateTime sekarang) => db.transaction(() async {
    if (await AmbilShiftAktif() != null) {
      throw StateError('Perangkat masih punya shift terbuka.');
    }
    final diubah =
        await (db.update(
          db.shift,
        )..where((s) => s.Uuid.equals(uuid) & s.Status.equals(StatusShiftLokal.tertutup))).write(
          const ShiftCompanion(
            Status: Value(StatusShiftLokal.terbuka),
            DitutupOleh: Value(null),
            NamaPenutup: Value(null),
            DitutupPada: Value(null),
            KasSeharusnya: Value(null),
            KasAktual: Value(null),
            Selisih: Value(null),
            PecahanKasAkhir: Value(null),
            NonTunaiDilaporkan: Value(null),
            AlasanSelisih: Value(null),
            UuidPenyetujuSelisih: Value(null),
          ),
        );
    if (diubah != 1) {
      throw StateError('Shift $uuid tidak tertutup.');
    }
    await TambahOutbox(item, sekarang);
    if (await AmbilPengaturan(KunciPengaturan.laporanZTertunda) == uuid) {
      await SimpanPengaturan(KunciPengaturan.laporanZTertunda, '');
    }
  });

  /// Simpan mutasi kas + entri outbox `MutasiKas.Catat` dalam satu transaksi.
  Future<void> SimpanMutasiBaru(MutasiKasCompanion mutasi, ItemOutbox item, DateTime sekarang) =>
      db.transaction(() async {
        await db.into(db.mutasiKas).insert(mutasi);
        await TambahOutbox(item, sekarang);
      });

  /// Cetak struk bagian 4: log buka laci manual hanya berupa entri outbox `Laci.Buka` (tidak ada tabel lokal).
  Future<void> SimpanBukaLaci(ItemOutbox item, DateTime sekarang) =>
      db.transaction(() async => TambahOutbox(item, sekarang));

  /// Tambah entri outbox. Hanya dipanggil di dalam `db.transaction` bersama dokumennya.
  /// F-18: staf pelayan dari data awal terakhir.
  Future<List<KaryawanPos>> AmbilKaryawan() async {
    final teks = await AmbilPengaturan(KunciPengaturan.karyawan);
    final data = teks == null ? null : jsonDecode(teks);
    return data is List<Object?>
        ? [for (final k in data.whereType<Map<String, Object?>>()) KaryawanPos.DariJson(k)]
        : const [];
  }

  /// Audit P0 F-01: item ditandai perangkat pembuatnya (identitas perangkat aktif) agar setelah aktivasi ulang tetap
  /// dikirim atas nama perangkat asal.
  Future<void> TambahOutbox(ItemOutbox item, DateTime sekarang) async => db
      .into(db.outbox)
      .insert(
        OutboxCompanion.insert(
          Uuid: item.uuid,
          Jenis: item.jenis,
          Data: jsonEncode(item.data),
          Status: StatusOutbox.tertunda,
          DibuatPada: sekarang.toUtc(),
          BerikutnyaPada: sekarang.toUtc(),
          UuidPerangkat: Value(await AmbilPengaturan(KunciPengaturan.uuidPerangkat)),
        ),
      );

  // Outbox -----------------------------------------------------------------------------------------------------------

  /// Item tertunda yang sudah jatuh tempo, urut FIFO (Id), maks. `batas`. Berhenti di item pertama yang belum jatuh
  /// tempo agar urutan (shift sebelum mutasinya) tidak terlompati.
  Future<List<BarisOutbox>> AmbilOutboxSiapKirim(int batas, DateTime sekarang) async {
    final baris =
        await (db.select(db.outbox)
              ..where((o) => o.Status.equals(StatusOutbox.tertunda))
              ..orderBy([(o) => OrderingTerm.asc(o.Id)])
              ..limit(batas))
            .get();
    final siap = <BarisOutbox>[];
    for (final b in baris) {
      if (b.BerikutnyaPada.isAfter(sekarang.toUtc())) {
        break;
      }
      siap.add(b);
    }
    return siap;
  }

  Future<void> HapusOutbox(List<String> uuid) => (db.delete(db.outbox)..where((o) => o.Uuid.isIn(uuid))).go();

  Future<void> TandaiPerluTindakan(String uuid, String? kode, String? pesan) =>
      (db.update(db.outbox)..where((o) => o.Uuid.equals(uuid))).write(
        OutboxCompanion(
          Status: const Value(StatusOutbox.perluTindakan),
          KodeGalat: Value(kode),
          PesanGalat: Value(pesan),
        ),
      );

  /// Jadwalkan ulang setelah gagal jaringan dengan mundur eksponensial (5 detik × 2^percobaan, maks. 5 menit).
  Future<void> JadwalkanUlang(List<BarisOutbox> baris, DateTime sekarang, String pesan) => db.transaction(() async {
    for (final b in baris) {
      final detik = (5 * (1 << b.Percobaan.clamp(0, 10))).clamp(5, 300);
      await (db.update(db.outbox)..where((o) => o.Id.equals(b.Id))).write(
        OutboxCompanion(
          Percobaan: Value(b.Percobaan + 1),
          PesanGalat: Value(pesan),
          BerikutnyaPada: Value(sekarang.toUtc().add(Duration(seconds: detik))),
        ),
      );
    }
  });

  /// Audit P0 F-01: semua item tertunda jatuh tempo sekarang (mengosongkan outbox sebelum token perangkat dicabut).
  Future<void> SegerakanTertunda(DateTime sekarang) =>
      (db.update(db.outbox)..where((o) => o.Status.equals(StatusOutbox.tertunda))).write(
        OutboxCompanion(BerikutnyaPada: Value(sekarang.toUtc())),
      );

  /// Kirim ulang item "Perlu Tindakan" (misal setelah admin memperbaiki kategori/izin di back-office).
  Future<void> CobaLagi(String uuid, DateTime sekarang) =>
      (db.update(db.outbox)..where((o) => o.Uuid.equals(uuid))).write(
        OutboxCompanion(
          Status: const Value(StatusOutbox.tertunda),
          Percobaan: const Value(0),
          BerikutnyaPada: Value(sekarang.toUtc()),
        ),
      );

  /// Item "Perlu tindakan" dengan salah satu [kodeGalat] kembali ke antrean (urutan kirim tetap menurut `Id`, yaitu yang
  /// terlama dulu). Dipakai setelah penyebab penolakan massal diperbaiki, misal shift lama di server ditutup.
  Future<int> CobaLagiPerluTindakan(Set<String> kodeGalat, DateTime sekarang) =>
      (db.update(
        db.outbox,
      )..where((o) => o.Status.equals(StatusOutbox.perluTindakan) & o.KodeGalat.isIn(kodeGalat))).write(
        OutboxCompanion(
          Status: const Value(StatusOutbox.tertunda),
          Percobaan: const Value(0),
          BerikutnyaPada: Value(sekarang.toUtc()),
        ),
      );

  /// Jumlah outbox tertunda saat ini (header `X-Outbox-Tertunda`, P-10 BR-P10.2).
  Future<int> HitungJumlahTertunda() {
    final jumlah = db.outbox.Id.count();
    return (db.selectOnly(db.outbox)
          ..addColumns([jumlah])
          ..where(db.outbox.Status.equals(StatusOutbox.tertunda)))
        .map((r) => r.read(jumlah) ?? 0)
        .getSingle();
  }

  Stream<int> PantauJumlahTertunda() {
    final jumlah = db.outbox.Id.count();
    return (db.selectOnly(db.outbox)
          ..addColumns([jumlah])
          ..where(db.outbox.Status.equals(StatusOutbox.tertunda)))
        .map((r) => r.read(jumlah) ?? 0)
        .watchSingle();
  }

  /// D-40: jumlah item tertunda per jenis outbox, terbanyak dulu (antrean kirim di layar Status sinkron).
  Stream<List<({String jenis, int jumlah})>> PantauRingkasanTertunda() {
    final jumlah = db.outbox.Id.count();
    return (db.selectOnly(db.outbox)
          ..addColumns([db.outbox.Jenis, jumlah])
          ..where(db.outbox.Status.equals(StatusOutbox.tertunda))
          ..groupBy([db.outbox.Jenis]))
        .map((r) => (jenis: r.read(db.outbox.Jenis)!, jumlah: r.read(jumlah) ?? 0))
        .watch()
        .map((daftar) => daftar..sort((a, b) => b.jumlah.compareTo(a.jumlah)));
  }

  /// K-17: waktu dibuat item tertunda tertua (null = outbox kosong).
  Stream<DateTime?> PantauOutboxTertua() {
    final tertua = db.outbox.DibuatPada.min();
    return (db.selectOnly(db.outbox)
          ..addColumns([tertua])
          ..where(db.outbox.Status.equals(StatusOutbox.tertunda)))
        .map((r) => r.read(tertua))
        .watchSingle();
  }

  /// K-17: catat hasil satu putaran sinkron yang dijawab server: waktu sinkron, selisih jam perangkat − server (detik),
  /// dan Uuid penjualan yang ditandai tinjauan (50 terbaru).
  Future<void> CatatSinkron({required DateTime waktu, DateTime? waktuServer, List<String> perluTinjauan = const []}) =>
      db.transaction(() async {
        await SimpanPengaturan(KunciPengaturan.sinkronTerakhir, waktu.toUtc().toIso8601String());
        if (waktuServer != null) {
          await SimpanPengaturan(KunciPengaturan.selisihJamDetik, '${waktu.difference(waktuServer).inSeconds}');
        }
        if (perluTinjauan.isNotEmpty) {
          final lama = await AmbilPenjualanPerluTinjauan();
          final baru = [...perluTinjauan, ...lama.where((u) => !perluTinjauan.contains(u))].take(50).toList();
          await SimpanPengaturan(KunciPengaturan.penjualanPerluTinjauan, jsonEncode(baru));
        }
      });

  Future<List<String>> AmbilPenjualanPerluTinjauan() async {
    final isi = await AmbilPengaturan(KunciPengaturan.penjualanPerluTinjauan);
    if (isi == null || isi.isEmpty) {
      return const [];
    }
    final data = jsonDecode(isi);
    return data is List<Object?> ? data.whereType<String>().toList() : const [];
  }

  /// K-17: pantau pengaturan sinkron (berubah setiap putaran sinkron tercatat).
  Stream<Map<String, String>> PantauPengaturanSinkron() =>
      (db.select(db.pengaturan)..where(
            (p) => p.Kunci.isIn([
              KunciPengaturan.sinkronTerakhir,
              KunciPengaturan.selisihJamDetik,
              KunciPengaturan.penjualanPerluTinjauan,
              KunciPengaturan.dataAwalPada,
            ]),
          ))
          .watch()
          .map((daftar) => {for (final p in daftar) p.Kunci: p.Nilai});

  Stream<List<BarisOutbox>> PantauPerluTindakan() =>
      (db.select(db.outbox)
            ..where((o) => o.Status.equals(StatusOutbox.perluTindakan))
            ..orderBy([(o) => OrderingTerm.asc(o.Id)]))
          .watch();

  // Penguncian PIN ---------------------------------------------------------------------------------------------------

  Future<BarisPercobaanPin?> AmbilPercobaanPin(String uuidPengguna) =>
      (db.select(db.percobaanPin)..where((p) => p.UuidPengguna.equals(uuidPengguna))).getSingleOrNull();

  Future<void> SimpanPercobaanPin(String uuidPengguna, int jumlahGagal, DateTime? terkunciSampai) => db
      .into(db.percobaanPin)
      .insertOnConflictUpdate(
        PercobaanPinCompanion.insert(
          UuidPengguna: uuidPengguna,
          JumlahGagal: jumlahGagal,
          TerkunciSampai: Value(terkunciSampai?.toUtc()),
        ),
      );

  Future<void> HapusPercobaanPin(String uuidPengguna) =>
      (db.delete(db.percobaanPin)..where((p) => p.UuidPengguna.equals(uuidPengguna))).go();

  /// Perangkat dicabut (PRD §25.2 no. 3): hapus data PIN & staf, serta cache pelanggan salesman (nomor HP penuh &
  /// alamat, K30). Shift, mutasi, kunjungan, pesanan, dan outbox yang belum terkirim tetap disimpan agar tidak ada
  /// transaksi yang hilang.
  Future<void> HapusDataSensitif() => db.transaction(() async {
    await db.delete(db.staf).go();
    await db.delete(db.percobaanPin).go();
    await db.delete(db.pelangganSalesmanLokal).go();
    await HapusPengaturan(KunciPengaturan.pelangganSalesmanDiperbaruiPada);
  });
}
