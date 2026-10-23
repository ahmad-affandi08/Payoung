import 'dart:convert';
import 'dart:typed_data';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';
import 'package:kasir/Data/PenyimpanRahasia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Katalog/KatalogLokal.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/KonteksPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPreOrder.dart';
import 'package:kasir/Domain/Penjualan/LayananVoidPenjualan.dart';
import 'package:kasir/Domain/Sesi/LayananPerangkat.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:kasir/Domain/Struk/IdentitasStruk.dart';
import 'package:kasir/Domain/Struk/PenyusunDokumenKasir.dart';
import 'package:kasir/Domain/Struk/PenyusunStrukPenjualan.dart';
import 'package:kasir/Domain/Struk/ProfilPrinter.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Cetak struk (PRD v1.79): isi struk dari penjualan lokal sesuai pengaturan back-office, cetak otomatis/ulang, laci
/// hanya untuk tunai, galat printer tidak membatalkan penjualan, dan logo 1 bit dari data awal.
void main() {
  late LingkunganUji u;
  late KatalogLokal katalog;
  late KonteksPenjualan k;
  late StafLokal rina;

  const profil = ProfilPrinter(alamat: '192.168.1.50');

  Map<String, Object?> DataAwalStruk(Map<String, Object?> struk) => {
    ...DataAwalUji(),
    'Struk': {
      'NamaUsaha': 'Kopi Senja',
      'Npwp': '0123456789012345',
      'AdaLogo': false,
      'TandaAir': true,
      'TeksKepala': ['Buka 07.00-22.00'],
      'CatatanKaki': 'Barang yang sudah dibeli bisa ditukar dalam 7 hari.',
      ...struk,
    },
  };

  Future<void> Siapkan({Map<String, Object?> struk = const {}}) async {
    await u.SiapkanAktif(dataAwal: DataAwalStruk(struk));
    await u.SiapkanKatalog();
    katalog = await u.MuatKatalog();
    k = await u.MuatKonteks();
    rina = await u.Staf('Rina Wulandari');
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  }

  /// 2× Es Kopi Susu Aren (kurang manis) + 1× Croissant = Rp 61.000 + PBJT 10% = Rp 67.100.
  Future<PenjualanTersimpan> Jual(String jenisMetode, {int jumlah = 100000}) {
    var keranjang = Keranjang.kosong;
    final gula = [PilihanTerpilih(uuid: UuidUji.gulaKurang, nama: 'Kurang manis', harga: Uang.Nol())];
    for (var i = 0; i < 2; i++) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.kopiSusu)!, pilihan: gula),
        katalog,
        k,
      );
    }
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
      katalog,
      k,
    );
    final metode = k.metodePembayaran.firstWhere((m) => m.Jenis == jenisMetode);
    return u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [
        PembayaranMasukan(
          metode: metode,
          jumlah: jenisMetode == JenisMetodeBayar.tunai ? Uang.DariBulat(jumlah) : Uang.DariBulat(67100),
          referensi: jenisMetode == JenisMetodeBayar.edc ? 'BCA 123456' : null,
        ),
      ],
      kasir: rina,
      k: k,
    );
  }

  setUp(() => u = LingkunganUji.Buat());
  tearDown(() => u.Tutup());

  test('struk 58 mm: kepala dari pengaturan, rincian, total, kembalian, catatan kaki, tanda air', () async {
    await Siapkan();
    final hasil = await Jual(JenisMetodeBayar.tunai);
    final data = DataStrukPenjualan(
      penjualan: (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!,
      detail: await u.repositoriPenjualan.AmbilDetail(hasil.uuid),
      pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
      namaPelanggan: 'Budi Santoso',
    );
    final baris = TataLetakStruk.KeTeks(
      PenyusunStrukPenjualan.Susun(await IdentitasStruk.Muat(u.repositori), data),
      LebarKertas.Mm58,
    ).map((b) => b.trim()).toList();

    expect(baris.take(3), ['Kopi Senja', 'Buka 07.00-22.00', 'Kopi Senja Solo Baru']);
    expect(baris, contains('NPWP 0123456789012345'));
    expect(baris, contains(hasil.nomor));
    expect(baris, contains('Kasir: Rina Wulandari'));
    expect(baris, contains('Pelanggan: Budi Santoso'));
    expect(baris, contains('+ Kurang manis'));
    expect(baris.any((b) => b.startsWith('2 x ') && b.endsWith('36.000')), isTrue, reason: baris.join('\n'));
    expect(baris.any((b) => b.startsWith('TOTAL') && b.endsWith('Rp 67.100')), isTrue);
    expect(baris.any((b) => b.startsWith('Pajak') && b.endsWith('6.100')), isTrue);
    expect(baris.any((b) => b.startsWith('Kembalian') && b.endsWith('32.900')), isTrue);
    expect(baris, contains('Barang yang sudah dibeli bisa'));
    expect(baris[baris.length - 2], 'Terima kasih atas kunjungan Anda');
    expect(baris.last, 'Dibuat dengan Payoung');
    expect(baris, isNot(contains('CETAK ULANG')));
    expect(baris, isNot(contains('Poin masuk setelah transaksi tersinkron')));
  });

  test(
    'skema 20–21: ongkir, diskon ongkir, nomor seri, dan garansi tersimpan di penjualan lokal dan tercetak di struk',
    () async {
      await Siapkan();
      await u.SiapkanKatalog(KatalogPonselUji());
      katalog = await u.MuatKatalog();
      var keranjang = u.penjualan.TambahBaris(
        Keranjang.kosong,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(ponsel)!, nomorSeri: ['IMEI-0001', 'IMEI-0002']),
        katalog,
        k,
      );
      keranjang = keranjang.Salin(biayaKirim: Uang.DariBulat(20000), diskonKirim: Uang.DariBulat(5000));
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == JenisMetodeBayar.tunai);
      final hasil = await u.penjualan.Bayar(
        keranjang: keranjang,
        pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(20000000))],
        kasir: rina,
        k: k,
        katalog: katalog,
      );

      final jual = (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!;
      expect((jual.BiayaKirim, jual.DiskonKirim), ('20000.00', '5000.00'));
      final detail = await u.repositoriPenjualan.AmbilDetail(hasil.uuid);
      expect(jsonDecode(detail.single.NomorSeri!), ['IMEI-0001', 'IMEI-0002']);

      final baris = TataLetakStruk.KeTeks(
        PenyusunStrukPenjualan.Susun(
          await IdentitasStruk.Muat(u.repositori),
          DataStrukPenjualan(
            penjualan: jual,
            detail: detail,
            pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
          ),
        ),
        LebarKertas.Mm58,
      ).map((b) => b.trim()).toList();
      expect(baris, contains('No. seri: IMEI-0001, IMEI-0002'), reason: baris.join('\n'));
      expect(detail.single.MasaGaransiBulan, 12);
      final tahunGaransi = int.parse(jual.TanggalBisnis.substring(0, 4)) + 1;
      expect(
        baris.any((b) => RegExp('^Garansi sampai \\d+ \\w+ $tahunGaransi\$').hasMatch(b)),
        isTrue,
        reason: baris.join('\n'),
      );
      expect(baris.any((b) => b.startsWith('Ongkir') && b.endsWith('20.000')), isTrue, reason: baris.join('\n'));
      expect(baris.any((b) => b.startsWith('Diskon ongkir') && b.endsWith('-5.000')), isTrue, reason: baris.join('\n'));
    },
  );

  test('F-16c bagian 4a: promo poin berlipat dicetak di bawah total; tidak dicetak pada penjualan void', () async {
    await Siapkan();
    final hasil = await Jual(JenisMetodeBayar.tunai);
    Future<List<String>> Teks() async => TataLetakStruk.KeTeks(
      PenyusunStrukPenjualan.Susun(
        await IdentitasStruk.Muat(u.repositori),
        DataStrukPenjualan(
          penjualan: (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!,
          detail: await u.repositoriPenjualan.AmbilDetail(hasil.uuid),
          pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
          namaPelanggan: 'Budi Santoso',
          labelPoin: 'Poin 2× · Poin dobel akhir pekan',
        ),
      ),
      LebarKertas.Mm80,
    ).map((b) => b.trim()).toList();

    final baris = await Teks();
    final total = baris.indexWhere((b) => b.startsWith('TOTAL'));
    // Printer thermal hanya ASCII: "×" → "x", "·" → "-".
    final poin = baris.indexOf('Poin 2x - Poin dobel akhir pekan');
    expect(poin, greaterThan(total), reason: baris.join('\n'));
    expect(baris[poin + 1], 'Poin masuk setelah transaksi tersinkron');

    await (u.db.update(
      u.db.penjualan,
    )..where((p) => p.Uuid.equals(hasil.uuid))).write(const PenjualanCompanion(Status: Value('Void')));
    expect(await Teks(), isNot(contains('Poin masuk setelah transaksi tersinkron')));
  });

  test('POS-11 struk digital: QR & tautan = awalan dari server + Uuid penjualan; tanpa awalan tidak dicetak', () async {
    await Siapkan(struk: {'AwalanStrukDigital': 'https://payoung.id/s/1c.'});
    final hasil = await Jual(JenisMetodeBayar.tunai);
    Future<DokumenStruk> Susun() async => PenyusunStrukPenjualan.Susun(
      await IdentitasStruk.Muat(u.repositori),
      DataStrukPenjualan(
        penjualan: (await u.repositoriPenjualan.CariPenjualan(hasil.uuid))!,
        detail: await u.repositoriPenjualan.AmbilDetail(hasil.uuid),
        pembayaran: await u.repositoriPenjualan.AmbilPembayaran(hasil.uuid),
      ),
    );
    final tautan = 'https://payoung.id/s/1c.${hasil.uuid.toUpperCase()}';
    final dokumen = await Susun();
    expect(dokumen.baris.whereType<BarisQr>().single.data, tautan);
    expect(dokumen.baris.whereType<BarisTeks>().map((b) => b.teks), containsAll(['Struk digital:', tautan]));

    await u.repositori.SimpanPengaturan(
      KunciPengaturan.struk,
      jsonEncode({...DataAwalStruk(const {})['Struk']! as Map<String, Object?>, 'AwalanStrukDigital': null}),
    );
    expect((await Susun()).baris.whereType<BarisQr>(), isEmpty);
  });

  test('saklar back-office dipatuhi: tanpa kasir, pelanggan, NPWP; nama & penutup kustom; tanpa tanda air', () async {
    await Siapkan(
      struk: {
        'NamaDicetak': 'Senja Coffee',
        'TampilkanKasir': false,
        'TampilkanPelanggan': false,
        'TampilkanNpwp': false,
        'TeksPenutup': 'Sampai jumpa lagi',
        'TandaAir': false,
      },
    );
    final hasil = await Jual(JenisMetodeBayar.tunai);
    await profil.Simpan(u.repositori);
    await u.struk.CetakPenjualan(hasil.uuid, namaPelanggan: 'Budi Santoso');
    final teks = u.printer.AmbilTeks();
    expect(teks, contains('Senja Coffee'));
    expect(teks, isNot(contains('Kasir:')));
    expect(teks, isNot(contains('Budi Santoso')));
    expect(teks, isNot(contains('NPWP')));
    expect(teks, contains('Sampai jumpa lagi'));
    expect(teks, isNot(contains('Payoung')));
  });

  test('cetak otomatis: tanpa printer / otomatis mati = tidak mencetak; tunai membuka laci, EDC tidak', () async {
    await Siapkan();
    final tunai = await Jual(JenisMetodeBayar.tunai);
    expect(await u.struk.CetakSetelahBayar(tunai.uuid), isFalse);
    expect(u.printer.kiriman, isEmpty);

    await profil.copyWith(cetakOtomatis: false).Simpan(u.repositori);
    expect(await u.struk.CetakSetelahBayar(tunai.uuid), isFalse);

    await profil.Simpan(u.repositori);
    expect(await u.struk.CetakSetelahBayar(tunai.uuid), isTrue);
    expect(u.printer.CekBukaLaci(), isTrue);

    final edc = await Jual(JenisMetodeBayar.edc);
    await u.struk.CetakSetelahBayar(edc.uuid);
    expect(u.printer.CekBukaLaci(), isFalse);

    await profil.copyWith(bukaLaciTunai: false).Simpan(u.repositori);
    await u.struk.CetakSetelahBayar(tunai.uuid);
    expect(u.printer.CekBukaLaci(), isFalse);
  });

  test('cetak ulang bertanda CETAK ULANG; penjualan void bertanda DIBATALKAN', () async {
    await Siapkan();
    final hasil = await Jual(JenisMetodeBayar.tunai);
    await profil.Simpan(u.repositori);
    await u.struk.CetakPenjualan(hasil.uuid, cetakUlang: true);
    expect(u.printer.AmbilTeks(), contains('CETAK ULANG'));

    await (u.db.update(
      u.db.penjualan,
    )..where((p) => p.Uuid.equals(hasil.uuid))).write(const PenjualanCompanion(Status: Value('Void')));
    await u.struk.CetakPenjualan(hasil.uuid, cetakUlang: true);
    expect(u.printer.AmbilTeks(), contains('DIBATALKAN'));
  });

  test(
    'printer gagal → GalatPrinter berpesan; penjualan tetap tersimpan; belum diatur → pesan cara mengatur',
    () async {
      await Siapkan();
      final hasil = await Jual(JenisMetodeBayar.tunai);
      await expectLater(
        u.struk.CetakPenjualan(hasil.uuid),
        throwsA(isA<GalatPrinter>().having((g) => g.pesan, 'pesan', contains('Printer belum diatur'))),
      );
      await profil.Simpan(u.repositori);
      u.printer.galat = 'Printer di 192.168.1.50:9100 tidak tersambung.';
      await expectLater(u.struk.CetakPenjualan(hasil.uuid), throwsA(isA<GalatPrinter>()));
      expect(await u.repositoriPenjualan.CariPenjualan(hasil.uuid), isNotNull);
    },
  );

  test('logo outlet bilah atas (D-16): diunduh apa adanya (base64) saat data awal; tanpa logo → kosong; gagal unduh → '
      'logo lama tetap', () async {
    var adaLogo = true;
    var logoTersedia = true;
    u.server.penangan = (p) async {
      if (p.url.path.endsWith('/data-awal')) {
        return JsonUji({
          ...DataAwalUji(),
          'Outlet': {'Uuid': '01JOUTLETUJI0000000000000', 'Kode': 'POS-001', 'Nama': 'Kopi Senja', 'AdaLogo': adaLogo},
        });
      }
      if (p.url.path.endsWith('/logo-outlet')) {
        if (!logoTersedia) {
          throw http.ClientException('offline');
        }
        return BytesUji([137, 80, 78, 71]);
      }
      throw http.ClientException('offline');
    };
    final perangkat = LayananPerangkat(
      klien: u.klien,
      repositori: u.repositori,
      rahasia: u.rahasia,
      platform: 'Android',
    );
    u.rahasia.isi[PenyimpanRahasia.kunciToken] = '12|rahasia';

    await perangkat.SegarkanDataAwal();
    expect(base64Decode((await u.repositori.AmbilPengaturan(KunciPengaturan.logoOutlet))!), [137, 80, 78, 71]);

    logoTersedia = false;
    await perangkat.SegarkanDataAwal();
    expect(base64Decode((await u.repositori.AmbilPengaturan(KunciPengaturan.logoOutlet))!), [137, 80, 78, 71]);

    adaLogo = false;
    await perangkat.SegarkanDataAwal();
    expect(await u.repositori.AmbilPengaturan(KunciPengaturan.logoOutlet), '');
  });

  test('profil printer: simpan/muat/hapus, data rusak = belum diatur, validasi alamat & port', () async {
    await Siapkan();
    expect(await ProfilPrinter.Muat(u.repositori), isNull);
    await profil.copyWith(lebar: LebarKertas.Mm80, port: 9101).Simpan(u.repositori);
    final dimuat = (await ProfilPrinter.Muat(u.repositori))!;
    expect((dimuat.alamat, dimuat.port, dimuat.lebar), ('192.168.1.50', 9101, LebarKertas.Mm80));
    expect(dimuat.umpanAkhir, PerintahEscPos.umpanAkhirBawaan);
    await profil.copyWith(umpanAkhir: 1).Simpan(u.repositori);
    expect((await ProfilPrinter.Muat(u.repositori))!.umpanAkhir, 1);
    // Profil lama tanpa kunci UmpanAkhir memakai bawaan baru (lebih pendek dari 4 baris dulu).
    expect(
      ProfilPrinter.DariJson({...profil.KeJson()}..remove('UmpanAkhir'))!.umpanAkhir,
      PerintahEscPos.umpanAkhirBawaan,
    );
    await u.repositori.SimpanPengaturan(KunciPengaturan.profilPrinter, '{rusak');
    expect(await ProfilPrinter.Muat(u.repositori), isNull);
    await ProfilPrinter.Hapus(u.repositori);
    expect(await ProfilPrinter.Muat(u.repositori), isNull);

    expect(ProfilPrinter.ValidasiAlamat(''), isNotNull);
    expect(ProfilPrinter.ValidasiAlamat('192.168.1.300'), isNotNull);
    expect(ProfilPrinter.ValidasiAlamat('192.168.1.50'), isNull);
    expect(ProfilPrinter.ValidasiAlamat('printer-kasir.local'), isNull);
    expect(ProfilPrinter.ValidasiPort('0'), isNotNull);
    expect(ProfilPrinter.ValidasiPort('9100'), isNull);
  });

  test(
    'logo struk: diunduh & disimpan 1 bit saat data awal; dimatikan → dihapus; gagal unduh → logo lama tetap',
    () async {
      final logo = GambarMonokrom(8, 1, Uint8List.fromList([1, 1, 1, 1, 0, 0, 0, 0]));
      var adaLogo = true;
      var logoTersedia = true;
      u.server.penangan = (p) async {
        if (p.url.path.endsWith('/data-awal')) {
          return JsonUji(DataAwalStruk({'AdaLogo': adaLogo}));
        }
        if (p.url.path.endsWith('/logo-struk')) {
          if (!logoTersedia) {
            throw http.ClientException('offline');
          }
          return BytesUji([137, 80, 78, 71]);
        }
        throw http.ClientException('offline');
      };
      final perangkat = LayananPerangkat(
        klien: u.klien,
        repositori: u.repositori,
        rahasia: u.rahasia,
        platform: 'Android',
        ubahLogo: (byte) async => logo,
      );
      u.rahasia.isi[PenyimpanRahasia.kunciToken] = '12|rahasia';

      await perangkat.SegarkanDataAwal();
      final identitas = await IdentitasStruk.Muat(u.repositori);
      expect(identitas.logo?.titik, logo.titik);
      expect(PenyusunStrukPenjualan.SusunKepala(identitas).first, isA<BarisGambar>());

      logoTersedia = false;
      await perangkat.SegarkanDataAwal();
      expect((await IdentitasStruk.Muat(u.repositori)).logo, isNotNull);

      adaLogo = false;
      await perangkat.SegarkanDataAwal();
      expect(await u.repositori.AmbilPengaturan(KunciPengaturan.logoStruk), '');
      expect((await IdentitasStruk.Muat(u.repositori)).logo, isNull);
      expect(jsonDecode((await u.repositori.AmbilPengaturan(KunciPengaturan.struk))!), isA<Map<String, Object?>>());
      expect(StrukPos.DariJson(null), isNull);
    },
  );

  test(
    '3b bukti void: nomor, alasan, penyetuju, refund; laci hanya bila refund tunai & diminta; cetak ulang bertanda',
    () async {
      await Siapkan();
      final hasil = await Jual(JenisMetodeBayar.tunai);
      final budi = await u.Staf('Budi Santoso');
      await LayananVoidPenjualan(
        repositori: u.repositori,
        repositoriPenjualan: u.repositoriPenjualan,
        jam: () => u.jam,
      ).Void(uuidPenjualan: hasil.uuid, kasir: rina, alasan: 'Salah input pesanan meja 4', penyetuju: budi);
      await profil.Simpan(u.repositori);

      await u.struk.CetakVoid(hasil.uuid, bukaLaci: true);
      final teks = u.printer.AmbilTeks();
      expect(teks, contains('BUKTI VOID'));
      expect(teks, contains(hasil.nomor));
      // Kertas 58 mm (32 kolom): alasan panjang dibungkus di spasi.
      expect(teks, contains('Alasan: Salah input pesanan meja\n4'));
      expect(teks, contains('Disetujui: Budi Santoso'));
      expect(teks, contains('Rp 67.100'));
      expect(RegExp(r'Refund tunai\s+67\.100').hasMatch(teks), isTrue, reason: teks);
      expect(_AdaPulsaLaci(u.printer.kiriman.last), isTrue);

      await u.struk.CetakVoid(hasil.uuid, cetakUlang: true);
      expect(u.printer.AmbilTeks(), contains('CETAK ULANG'));
      expect(_AdaPulsaLaci(u.printer.kiriman.last), isFalse);
    },
  );

  test('3b laporan shift X: judul, transaksi, per metode, kas laci; laporan Z setelah tutup', () async {
    await Siapkan();
    await Jual(JenisMetodeBayar.tunai);
    await profil.Simpan(u.repositori);
    final shiftAktif = (await u.repositori.AmbilShiftAktif())!;

    await u.struk.CetakLaporanShift(await u.tutupShift.SusunLaporan(shiftAktif.Uuid));
    var teks = u.printer.AmbilTeks();
    expect(teks, contains('LAPORAN X'));
    expect(RegExp(r'Jumlah transaksi\s+1').hasMatch(teks), isTrue, reason: teks);
    expect(teks, contains('Kas seharusnya'));
    expect(teks, contains('Tanda tangan kasir'));

    final z = await u.tutupShift.TutupShift(shift: shiftAktif, penutup: rina, kasAktual: Uang.DariBulat(567100));
    await u.struk.CetakLaporanShift(z);
    teks = u.printer.AmbilTeks();
    expect(teks, contains('LAPORAN Z'));
    expect(teks, contains('Kas aktual'));
  });

  test('3b nota retur: nomor, asal, baris (rusak ditandai), total refund per metode, alasan; laci bila diminta', () {
    const identitas = IdentitasStruk(
      pengaturan: StrukPos(namaUsaha: 'Kopi Senja'),
      namaUsaha: 'Kopi Senja',
      namaOutlet: 'Kopi Senja Solo Baru',
    );
    final retur = BarisReturPenjualan(
      Uuid: 'R1',
      Nomor: 'RJ/SLO/260920/POS-001-0001',
      UuidPenjualanAsal: 'P1',
      NomorPenjualanAsal: 'INV/SLO/260920/POS-001-0007',
      UuidShift: 'S1',
      UuidPengguna: 'U1',
      NamaKasir: 'Rina Wulandari',
      UuidPenyetuju: 'U2',
      Alasan: 'Kemasan bocor',
      DibuatPada: DateTime.utc(2026, 9, 20, 3, 15),
      TanggalBisnis: '2026-09-20',
      MetodeRefund: 'Tunai',
      TotalRefund: '36000.00',
      RefundTunai: '36000.00',
    );
    final dokumen = PenyusunDokumenKasir.SusunRetur(
      identitas,
      retur,
      [
        const BarisReturPenjualanDetail(
          Uuid: 'D1',
          UuidReturPenjualan: 'R1',
          UuidPenjualanDetail: 'PD1',
          NamaProduk: 'Es Kopi Susu Aren',
          SimbolSatuan: 'gls',
          Jumlah: '2.0000',
          Kondisi: 'Rusak',
          NilaiBaris: '36000.00',
        ),
      ],
      [
        const BarisReturPenjualanPembayaran(
          Uuid: 'B1',
          UuidReturPenjualan: 'R1',
          UuidMetodePembayaran: 'M1',
          Jenis: 'Tunai',
          NamaMetode: 'Tunai',
          Jumlah: '36000.00',
        ),
      ],
      bukaLaci: true,
    );
    final teks = TataLetakStruk.KeTeks(dokumen, LebarKertas.Mm58).map((b) => b.trim()).toList();
    expect(teks, containsAll(['NOTA RETUR', 'RJ/SLO/260920/POS-001-0001', 'Asal:', 'INV/SLO/260920/POS-001-0007']));
    expect(teks.any((b) => b.startsWith('2 gls (rusak)') && b.endsWith('36.000')), isTrue, reason: teks.join('\n'));
    expect(teks.any((b) => b.startsWith('TOTAL REFUND') && b.endsWith('Rp 36.000')), isTrue);
    expect(teks, contains('Alasan: Kemasan bocor'));
    expect(dokumen.bukaLaci, isTrue);
  });

  test(
    '4a bukti uang muka pre-order: pelanggan, tanggal ambil, barang, DP & sisa; laci hanya bila DP tunai & diminta',
    () async {
      await Siapkan();
      await profil.Simpan(u.repositori);
      var keranjang = Keranjang.kosong.Salin(
        pelanggan: () => const PelangganTerpilih(uuid: 'PLG1', nama: 'Ani Rahmawati', noHpSamar: '0812****7890'),
      );
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.croissant)!),
        katalog,
        k,
      );
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == JenisMetodeBayar.tunai);
      final tanggalAmbil = u.penjualan.Hitung(keranjang, k).tanggalBisnis;
      final hasil = await u.preOrder.Buat(
        keranjang: keranjang,
        k: k,
        kasir: rina,
        tanggalAmbil: tanggalAmbil,
        uangMuka: Uang.DariBulat(10000),
        metode: tunai,
        catatan: 'Tulisan: Selamat ulang tahun',
      );
      expect(hasil.baris.single.nama, contains('Croissant'));

      await u.struk.CetakPreOrder(hasil, bukaLaci: true);
      final teks = u.printer.AmbilTeks();
      expect(teks, contains('BUKTI UANG MUKA'));
      expect(teks, contains(hasil.nomor));
      expect(teks, contains('Pelanggan: Ani Rahmawati'));
      expect(teks, contains('Diambil: ${tanggalAmbil.substring(8, 10)}/${tanggalAmbil.substring(5, 7)}'));
      expect(RegExp(r'UANG MUKA\s+Rp 10\.000').hasMatch(teks), isTrue, reason: teks);
      final sisa = PenyusunStrukPenjualan.Angka(hasil.totalPesanan.Kurangi(Uang.DariBulat(10000)));
      expect(RegExp('Sisa saat diambil\\s+${RegExp.escape(sisa)}').hasMatch(teks), isTrue, reason: teks);
      expect(_AdaPulsaLaci(u.printer.kiriman.last), isTrue);

      await u.struk.CetakPreOrder(hasil, cetakUlang: true);
      expect(u.printer.AmbilTeks(), contains('CETAK ULANG'));
      expect(_AdaPulsaLaci(u.printer.kiriman.last), isFalse);

      // DP non-tunai: laci tidak dibuka walau diminta.
      final nonTunai = PreOrderTersimpan(
        uuid: 'PO2',
        nomor: 'SO/SLO/260926/POS-001-0002',
        uangMuka: Uang.DariBulat(5000),
        totalPesanan: Uang.DariBulat(25000),
        tanggalAmbil: '2026-09-30',
        namaMetode: 'QRIS',
      );
      await u.struk.CetakPreOrder(nonTunai, bukaLaci: true);
      expect(_AdaPulsaLaci(u.printer.kiriman.last), isFalse);
    },
  );

  test(
    'v3.53 tagihan sementara: TAGIHAN SEMENTARA + BELUM LUNAS, item, pajak, TOTAL sama dengan mesin bayar',
    () async {
      await Siapkan();
      await profil.Simpan(u.repositori);
      var keranjang = Keranjang.kosong;
      for (final uuid in [UuidUji.croissant, UuidUji.croissant]) {
        keranjang = u.penjualan.TambahBaris(
          keranjang,
          u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(uuid)!),
          katalog,
          k,
        );
      }
      final hitungan = u.penjualan.Hitung(keranjang, k);
      final outboxSebelum = (await u.db.select(u.db.outbox).get()).length;
      await u.struk.CetakTagihanSementara(
        judul: 'Meja 7',
        nomor: 'PT/SLB/261002/POS-001-0003',
        keranjang: keranjang,
        hitungan: hitungan,
        waktu: DateTime.utc(2026, 10, 2, 5, 30),
        namaKasir: 'Rina Wulandari',
        jumlahTamu: 4,
      );
      final teks = u.printer.AmbilTeks();
      expect(teks, contains('TAGIHAN SEMENTARA'));
      expect(teks, contains('BELUM LUNAS'));
      expect(teks, contains('Meja 7'));
      expect(teks, contains('Tamu: 4 orang'));
      expect(teks, contains('Bukan bukti pembayaran.'));
      expect(teks, contains(hitungan.hasil.totalAkhir.FormatRupiah()));
      expect(
        await u.db.select(u.db.outbox).get(),
        hasLength(outboxSebelum),
        reason: 'Tagihan sementara tidak mencatat apa pun.',
      );
    },
  );
}

/// Pulsa buka laci `ESC p` ada di kiriman printer.
bool _AdaPulsaLaci(List<int> data) {
  for (var i = 0; i + 1 < data.length; i++) {
    if (data[i] == 0x1B && data[i + 1] == 0x70) {
      return true;
    }
  }
  return false;
}
