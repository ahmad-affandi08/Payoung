import 'package:drift/drift.dart';

import 'TabelAbsensi.dart';
import 'TabelDeposit.dart';
import 'TabelKatalog.dart';
import 'TabelMeja.dart';
import 'TabelPelanggan.dart';
import 'TabelPascaPenjualan.dart';
import 'TabelPenjualan.dart';
import 'TabelPersediaan.dart';
import 'TabelPreOrder.dart';
import 'TabelSalesman.dart';

export 'TabelAbsensi.dart';
export 'TabelDeposit.dart';
export 'TabelKatalog.dart';
export 'TabelMeja.dart';
export 'TabelPelanggan.dart';
export 'TabelPascaPenjualan.dart';
export 'TabelPenjualan.dart';
export 'TabelPersediaan.dart';
export 'TabelPreOrder.dart';
export 'TabelSalesman.dart';

part 'BasisDataKasir.g.dart';

/// Basis data lokal aplikasi kasir (Drift/SQLite, PRD §18). Nama tabel & kolom sama dengan server. Uang disimpan
/// sebagai TEXT desimal (bukan REAL). Migrasi skema tidak boleh menghapus outbox yang belum terkirim.

/// Pengaturan & identitas perangkat non-rahasia (outlet, perangkat, batas kas keluar, shift bersama).
@DataClassName('BarisPengaturan')
class Pengaturan extends Table {
  TextColumn get Kunci => text()();
  TextColumn get Nilai => text()();

  @override
  Set<Column<Object>> get primaryKey => {Kunci};
}

/// Staf yang boleh masuk di perangkat ini + verifier PIN offline terbungkus (dari data awal).
@DataClassName('BarisStaf')
class Staf extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nama => text()();
  BoolColumn get Pemilik => boolean()();
  TextColumn get Izin => text()();
  BoolColumn get PinDiatur => boolean()();
  TextColumn get PinGaram => text().nullable()();
  TextColumn get PinNonce => text().nullable()();
  TextColumn get PinSandi => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisKategoriKas')
class KategoriKas extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nama => text()();
  TextColumn get Jenis => text()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisShift')
class Shift extends Table {
  TextColumn get Uuid => text()();
  TextColumn get DibukaOleh => text()();
  TextColumn get NamaKasir => text()();
  DateTimeColumn get DibukaPada => dateTime()();
  TextColumn get KasAwal => text()();
  TextColumn get PecahanKasAwal => text().nullable()();
  BoolColumn get Bersama => boolean()();
  TextColumn get Status => text()();

  // Skema 3 (F-11): hasil tutup shift di perangkat. Semua nullable (diisi saat shift ditutup).
  TextColumn get DitutupOleh => text().nullable()();
  TextColumn get NamaPenutup => text().nullable()();
  DateTimeColumn get DitutupPada => dateTime().nullable()();
  TextColumn get KasSeharusnya => text().nullable()();
  TextColumn get KasAktual => text().nullable()();
  TextColumn get Selisih => text().nullable()();

  /// JSON `[{Nominal, Jumlah}]`.
  TextColumn get PecahanKasAkhir => text().nullable()();

  /// JSON `[{UuidMetodePembayaran, Jumlah}]` (hitungan non-tunai kasir).
  TextColumn get NonTunaiDilaporkan => text().nullable()();
  TextColumn get AlasanSelisih => text().nullable()();
  TextColumn get UuidPenyetujuSelisih => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisMutasiKas')
class MutasiKas extends Table {
  TextColumn get Uuid => text()();
  TextColumn get UuidShift => text().references(Shift, #Uuid)();
  TextColumn get Jenis => text()();
  TextColumn get UuidKategori => text().nullable()();
  TextColumn get NamaKategori => text().nullable()();
  TextColumn get Jumlah => text()();
  TextColumn get Catatan => text().nullable()();
  TextColumn get DicatatOleh => text()();
  DateTimeColumn get DicatatPada => dateTime()();
  TextColumn get DisetujuiOleh => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

/// Antrean kirim FIFO per perangkat (PRD §18 no. 4). Satu baris per item; `Status` Tertunda/PerluTindakan.
/// `UuidPerangkat` = perangkat yang membuat item (audit P0 F-01): setelah aktivasi ulang, item lama dikirim atas nama
/// perangkat asalnya (`UuidPerangkatAsal`), tidak diklaim perangkat baru.
@DataClassName('BarisOutbox')
class Outbox extends Table {
  IntColumn get Id => integer().autoIncrement()();
  TextColumn get Uuid => text().unique()();
  TextColumn get Jenis => text()();
  TextColumn get Data => text()();
  TextColumn get Status => text()();
  IntColumn get Percobaan => integer().withDefault(const Constant(0))();
  TextColumn get KodeGalat => text().nullable()();
  TextColumn get PesanGalat => text().nullable()();
  DateTimeColumn get DibuatPada => dateTime()();
  DateTimeColumn get BerikutnyaPada => dateTime()();
  TextColumn get UuidPerangkat => text().nullable()();
}

/// Penguncian PIN lokal: 5 kali salah → kunci 5 menit (PRD §20.2, §25.2 no. 3), berlaku juga offline.
@DataClassName('BarisPercobaanPin')
class PercobaanPin extends Table {
  TextColumn get UuidPengguna => text()();
  IntColumn get JumlahGagal => integer()();
  DateTimeColumn get TerkunciSampai => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {UuidPengguna};
}

@DriftDatabase(
  tables: [
    Pengaturan,
    Staf,
    KategoriKas,
    Shift,
    MutasiKas,
    Outbox,
    PercobaanPin,
    // Skema 2 (F-07c): katalog, pajak & metode bayar, penjualan.
    Kategori,
    Satuan,
    KelompokPajak,
    KelompokPajakDetail,
    Produk,
    ProdukSatuan,
    ProdukBarcode,
    DaftarHarga,
    ProdukHarga,
    KelompokPilihan,
    Pilihan,
    ProdukKelompokPilihan,
    TarifPajak,
    MetodePembayaran,
    Penjualan,
    PenjualanDetail,
    PenjualanPembayaran,
    PesananTertahan,
    NomorUrutPenjualan,
    // Skema 5 (F-09 fase 1): void & retur penjualan.
    VoidPenjualan,
    ReturPenjualan,
    ReturPenjualanDetail,
    ReturPenjualanPembayaran,
    NomorUrutReturPenjualan,
    // Skema 6 (F-07 mode meja fase 1): meja & pesanan terbuka.
    AreaMeja,
    Meja,
    PesananTerbuka,
    NomorUrutPesananTerbuka,
    // Skema 7 (F-16a): pelanggan yang pernah dipakai perangkat.
    PelangganLokal,
    // Skema 10 (F-18): absensi staf di perangkat ini.
    AbsensiLokal,
    // Skema 11 (F-12 bagian 2): pre-order + uang muka dari perangkat ini.
    PesananPenjualanLokal,
    NomorUrutPesananPenjualan,
    // Skema 13 (F-16d bagian 1): isi deposit pelanggan dari perangkat ini.
    IsiDepositLokal,
    NomorUrutIsiDeposit,
    // Skema 17 (F-05f bagian 2): bahan terbuang yang dicatat perangkat ini.
    BahanTerbuangLokal,
    // Skema 24 (K-12): meja yang perlu dibersihkan.
    MejaPerluDibersihkan,
    // Skema 26 (Modul Salesman bagian 2): cache pelanggan salesman, kunjungan & pesanan grosir dari perangkat ini.
    PelangganSalesmanLokal,
    KunjunganSalesLokal,
    PesananGrosirLokal,
  ],
)
class BasisDataKasir extends _$BasisDataKasir {
  BasisDataKasir(super.executor);

  /// Riwayat skema: 1 = F-06 (shift, kas, outbox); 2 = F-07c (katalog, pajak, metode bayar, penjualan); 3 = F-11
  /// (kolom tutup shift); 4 = F-07 tindak lanjut v1.46 (kategori jenis pajak di kelompok pajak); 5 = F-09 fase 1 (void
  /// & retur penjualan); 6 = F-07 mode meja fase 1 (meja & pesanan terbuka); 7 = F-16a (pelanggan lokal); 8 = F-16b
  /// (tier pelanggan lokal); 9 = F-12 (posisi kredit pelanggan lokal); 10 = F-18 (absensi lokal); 11 = F-12 bagian 2
  /// (pre-order lokal); 12 = F-16c bagian 3 (data promo pelanggan); 13 = F-16d bagian 1 (isi deposit lokal); 14 = F-16d
  /// bagian 2 (produk paket sesi); 15 = laundry (blok tiket di penjualan); 16 = audit P0 F-01 (perangkat pembuat item
  /// outbox); 17 = F-05f bagian 2 (bahan terbuang lokal); 18 = X8 (kanal metode pembayaran platform ojol);
  /// 19 = F-17 bagian 3 (ongkir ikut DPP pajak); 24 = K-12 (minta bill & meja perlu dibersihkan); 25 = K-25 (harga
  /// terbuka); 26 = Modul Salesman bagian 2 (pelanggan salesman, kunjungan, pesanan grosir lokal); 27 = Bengkel & Apotek
  /// bagian 2 (golongan obat produk, perintah kerja & ringkasan resep penjualan).
  @override
  int get schemaVersion => 30;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (m) => m.createAll(),
    onUpgrade: (m, dari, ke) async {
      // Migrasi hanya MENAMBAH tabel/indeks. Outbox & dokumen lama tidak disentuh (PRD §18.3 no. 9).
      if (dari < 2) {
        for (final tabel in <TableInfo<Table, Object?>>[
          kategori,
          satuan,
          kelompokPajak,
          kelompokPajakDetail,
          produk,
          produkSatuan,
          produkBarcode,
          daftarHarga,
          produkHarga,
          kelompokPilihan,
          pilihan,
          produkKelompokPilihan,
          tarifPajak,
          metodePembayaran,
          penjualan,
          penjualanDetail,
          penjualanPembayaran,
          pesananTertahan,
          nomorUrutPenjualan,
        ]) {
          await m.createTable(tabel);
        }
        await m.createIndex(indeksProdukBarcodeBarcode);
        await m.createIndex(indeksPenjualanTanggalBisnis);
      }
      if (dari < 3) {
        for (final kolom in <GeneratedColumn<Object>>[
          shift.DitutupOleh,
          shift.NamaPenutup,
          shift.DitutupPada,
          shift.KasSeharusnya,
          shift.KasAktual,
          shift.Selisih,
          shift.PecahanKasAkhir,
          shift.NonTunaiDilaporkan,
          shift.AlasanSelisih,
          shift.UuidPenyetujuSelisih,
        ]) {
          await m.addColumn(shift, kolom);
        }
      }
      // Dari skema 1, tabel katalog baru dibuat di atas sudah berkolom lengkap.
      if (dari >= 2 && dari < 4) {
        await m.addColumn(kelompokPajakDetail, kelompokPajakDetail.Kategori);
      }
      if (dari < 5) {
        for (final tabel in <TableInfo<Table, Object?>>[
          voidPenjualan,
          returPenjualan,
          returPenjualanDetail,
          returPenjualanPembayaran,
          nomorUrutReturPenjualan,
        ]) {
          await m.createTable(tabel);
        }
      }
      if (dari < 6) {
        for (final tabel in <TableInfo<Table, Object?>>[areaMeja, meja, pesananTerbuka, nomorUrutPesananTerbuka]) {
          await m.createTable(tabel);
        }
      }
      if (dari < 7) {
        await m.createTable(pelangganLokal);
      }
      // Dari skema ≤ 6, tabel di atas sudah dibuat berkolom lengkap.
      if (dari == 7) {
        await m.addColumn(pelangganLokal, pelangganLokal.KodeTier);
        await m.addColumn(pelangganLokal, pelangganLokal.NamaTier);
      }
      if (dari >= 7 && dari < 9) {
        await m.addColumn(pelangganLokal, pelangganLokal.LimitKredit);
        await m.addColumn(pelangganLokal, pelangganLokal.SisaPiutang);
        await m.addColumn(pelangganLokal, pelangganLokal.HariLewatJatuhTempo);
      }
      if (dari < 10) {
        await m.createTable(absensiLokal);
      }
      if (dari < 11) {
        await m.createTable(pesananPenjualanLokal);
        await m.createTable(nomorUrutPesananPenjualan);
      }
      // Skema 12 (F-16c bagian 3): data promo pelanggan; tabel yang dibuat di skema < 7 sudah berkolom lengkap.
      if (dari >= 7 && dari < 12) {
        await m.addColumn(pelangganLokal, pelangganLokal.HariLahir);
        await m.addColumn(pelangganLokal, pelangganLokal.JumlahTransaksi);
        await m.addColumn(pelangganLokal, pelangganLokal.PemakaianPromo);
        await m.addColumn(pelangganLokal, pelangganLokal.PemakaianPada);
      }
      if (dari < 13) {
        await m.createTable(isiDepositLokal);
        await m.createTable(nomorUrutIsiDeposit);
      }
      // Skema 14 (F-16d bagian 2): tanda paket sesi di produk. Tabel produk dari skema < 2 sudah berkolom lengkap.
      // Kursor katalog dihapus agar sinkron berikutnya lengkap dan produk paket yang sudah ada ikut tertandai.
      if (dari >= 2 && dari < 14) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Produk') WHERE name = 'JumlahSesiPaket'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(produk, produk.JumlahSesiPaket);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 20: ongkir di penjualan lokal (struk) dan nomor seri di detail penjualan lokal. Penjualan lama berongkir nol
      // dan tanpa nomor seri; struk lama tidak berubah.
      if (dari >= 2 && dari < 20) {
        Future<bool> Ada(String tabel, String kolom) async =>
            await customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('$tabel') WHERE name = '$kolom'")
                .map((r) => r.read<int>('Jumlah'))
                .getSingle() >
            0;
        if (!await Ada('Penjualan', 'BiayaKirim')) {
          await m.addColumn(penjualan, penjualan.BiayaKirim);
        }
        if (!await Ada('Penjualan', 'DiskonKirim')) {
          await m.addColumn(penjualan, penjualan.DiskonKirim);
        }
        if (!await Ada('PenjualanDetail', 'NomorSeri')) {
          await m.addColumn(penjualanDetail, penjualanDetail.NomorSeri);
        }
      }
      // Skema 21 (F-05h): masa garansi di produk (katalog) dan snapshot di detail penjualan lokal. Kursor katalog dihapus
      // supaya produk bernomor seri yang garansinya sudah diatur ikut termuat pada sinkron berikutnya.
      if (dari >= 2 && dari < 21) {
        Future<bool> Ada(String tabel, String kolom) async =>
            await customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('$tabel') WHERE name = '$kolom'")
                .map((r) => r.read<int>('Jumlah'))
                .getSingle() >
            0;
        if (!await Ada('Produk', 'MasaGaransiBulan')) {
          await m.addColumn(produk, produk.MasaGaransiBulan);
        }
        if (!await Ada('PenjualanDetail', 'MasaGaransiBulan')) {
          await m.addColumn(penjualanDetail, penjualanDetail.MasaGaransiBulan);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 15 (laundry §9.9): blok tiket laundry di penjualan lokal (nota & cetak ulang offline). Aditif; outbox utuh.
      if (dari >= 2 && dari < 15) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Penjualan') WHERE name = 'Laundry'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(penjualan, penjualan.Laundry);
        }
      }
      // Skema 16 (audit P0 F-01): perangkat pembuat item outbox. Item tertunda yang sudah ada dibuat oleh perangkat yang
      // aktif saat ini (identitasnya di `Pengaturan`), jadi ditandai dengan perangkat itu. Outbox tidak dihapus.
      if (dari < 16) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Outbox') WHERE name = 'UuidPerangkat'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(outbox, outbox.UuidPerangkat);
        }
        await customStatement(
          "UPDATE Outbox SET UuidPerangkat = (SELECT Nilai FROM Pengaturan WHERE Kunci = 'UuidPerangkat') "
          'WHERE UuidPerangkat IS NULL',
        );
      }
      if (dari < 17) {
        await m.createTable(bahanTerbuangLokal);
      }
      // Skema 18 (X8): kanal metode pembayaran platform. Tabel metode dari skema < 2 sudah berkolom lengkap. Isinya
      // diganti utuh saat data awal berikutnya dimuat, jadi baris lama cukup berkanal null.
      if (dari >= 2 && dari < 18) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('MetodePembayaran') WHERE name = 'Kanal'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(metodePembayaran, metodePembayaran.Kanal);
        }
      }
      // Skema 19 (F-17 bagian 3): ongkir ikut DPP pajak. Tabel kelompok pajak dari skema < 2 sudah berkolom lengkap.
      // Kursor katalog dihapus supaya sinkron berikutnya memuat ulang kelompok pajak beserta bendera barunya; tanpa itu
      // seluruh kelompok yang sudah ada akan tetap `false` sampai kelompoknya kebetulan diubah di back-office.
      if (dari >= 2 && dari < 19) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('KelompokPajakDetail') WHERE name = 'KenaBiayaKirim'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(kelompokPajakDetail, kelompokPajakDetail.KenaBiayaKirim);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 22 (v3.52): nomor antrian & nama pemesan penjualan. Tabel penjualan dari skema < 2 sudah lengkap.
      if (dari >= 2 && dari < 22) {
        Future<bool> Ada(String kolom) async =>
            await customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Penjualan') WHERE name = '$kolom'")
                .map((r) => r.read<int>('Jumlah'))
                .getSingle() >
            0;
        if (!await Ada('NomorAntrian')) {
          await m.addColumn(penjualan, penjualan.NomorAntrian);
        }
        if (!await Ada('NamaPemesan')) {
          await m.addColumn(penjualan, penjualan.NamaPemesan);
        }
      }
      // Skema 23 (K-9): atribut varian produk untuk pemilih varian kasir. Kursor katalog dihapus supaya sinkron
      // berikutnya memuat ulang produk beserta atributnya; tanpa itu varian lama tidak terpilih sampai produknya diubah.
      if (dari >= 2 && dari < 23) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Produk') WHERE name = 'AtributVarian'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(produk, produk.AtributVarian);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 24 (K-12): tanda minta bill di pesanan terbuka & tabel meja perlu dibersihkan. Pesanan terbuka dari skema
      // < 6 sudah berkolom lengkap.
      if (dari >= 6 && dari < 24) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('PesananTerbuka') WHERE name = 'MintaBillPada'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(pesananTerbuka, pesananTerbuka.MintaBillPada);
        }
      }
      if (dari < 24) {
        await m.createTable(mejaPerluDibersihkan);
      }
      // Skema 25 (K-25): item harga terbuka. Kursor katalog dihapus supaya sinkron berikutnya memuat ulang produk beserta
      // benderanya; tanpa itu produk harga terbuka lama terjual dengan harga daftar sampai produknya diubah.
      if (dari >= 2 && dari < 25) {
        final kolom = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Produk') WHERE name = 'HargaTerbuka'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (kolom == 0) {
          await m.addColumn(produk, produk.HargaTerbuka);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 26 (Modul Salesman bagian 2): hanya menambah tabel; outbox & dokumen lama tidak disentuh.
      if (dari < 26) {
        await m.createTable(pelangganSalesmanLokal);
        await m.createTable(kunjunganSalesLokal);
        await m.createTable(pesananGrosirLokal);
      }
      // Skema 27 (Bengkel & Apotek bagian 2): golongan obat produk serta tautan perintah kerja & ringkasan resep
      // penjualan; hanya menambah kolom, outbox & dokumen lama tidak disentuh. Kursor katalog dihapus supaya sinkron
      // berikutnya memuat ulang produk beserta golongannya; tanpa itu obat keras lama terjual tanpa dialog resep.
      if (dari >= 2 && dari < 27) {
        Future<bool> Ada(String tabel, String kolom) async =>
            await customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('$tabel') WHERE name = '$kolom'")
                .map((r) => r.read<int>('Jumlah'))
                .getSingle() >
            0;
        for (final (nama, kolom) in [
          ('GolonganObat', produk.GolonganObat),
          ('ObatWajibApotek', produk.ObatWajibApotek),
          ('Prekursor', produk.Prekursor),
          ('WajibResep', produk.WajibResep),
        ]) {
          if (!await Ada('Produk', nama)) {
            await m.addColumn(produk, kolom);
          }
        }
        if (!await Ada('Penjualan', 'PerintahKerja')) {
          await m.addColumn(penjualan, penjualan.PerintahKerja);
        }
        if (!await Ada('Penjualan', 'Resep')) {
          await m.addColumn(penjualan, penjualan.Resep);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
      // Skema 28 (Apotek bagian 4): racikan pada baris penjualan lokal; hanya menambah kolom, outbox utuh.
      if (dari >= 2 && dari < 28) {
        final ada = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('PenjualanDetail') WHERE name = 'Racikan'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (ada == 0) {
          await m.addColumn(penjualanDetail, penjualanDetail.Racikan);
        }
      }
      // Skema 29 (K-14 struk per tamu): bagian bayar tiap tamu pada penjualan lokal; hanya menambah kolom, outbox utuh.
      if (dari >= 2 && dari < 29) {
        final ada = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Penjualan') WHERE name = 'BagianTagihan'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (ada == 0) {
          await m.addColumn(penjualan, penjualan.BagianTagihan);
        }
      }
      // Skema 30 (K-16 favorit): tanda favorit produk dari pemilik. Kursor katalog dihapus supaya sinkron berikutnya
      // memuat ulang produk beserta tandanya; outbox & dokumen lama tidak disentuh.
      if (dari >= 2 && dari < 30) {
        final ada = await customSelect(
          "SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Produk') WHERE name = 'Favorit'",
        ).map((r) => r.read<int>('Jumlah')).getSingle();
        if (ada == 0) {
          await m.addColumn(produk, produk.Favorit);
        }
        await (delete(pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).go();
      }
    },
    beforeOpen: (detail) async {
      await customStatement('PRAGMA foreign_keys = ON');
    },
  );
}
