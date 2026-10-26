/// Katalog uji "Kopi Senja" berbentuk respons `GET /api/pos/v1/katalog` (lengkap). Harga realistis Rupiah, nama
/// produk panjang, pilihan wajib & opsional, multi-satuan dengan harga bertingkat, dan produk yang belum bisa dijual
/// (induk varian, bahan baku, berpelacakan batch).
abstract final class UuidUji {
  static const String kategoriKopi = '01K5KAT0000000000000K0P101';
  static const String kategoriMakanan = '01K5KAT000000000000MAKAN01';
  static const String satuanCangkir = '01K5SAT00000000000CANGK1R1';
  static const String satuanPcs = '01K5SAT0000000000000PCS001';
  static const String satuanLusin = '01K5SAT000000000001VS1N001';
  static const String kelompokPbjt = '01K5KP00000000000000PBJT01';
  static const String kelompokPpn = '01K5KP000000000000000PPN01';

  static const String kopiSusu = '01K5PRD0000000000K0P1SVSV1';
  static const String americano = '01K5PRD000000000AMER1CAN01';
  static const String croissant = '01K5PRD000000000CR01SSANT1';
  static const String roti = '01K5PRD00000000000000R0T11';
  static const String kaos = '01K5PRD00000000000000KA0S1';
  static const String susuUht = '01K5PRD0000000000000SVSV01';
  static const String gulaAren = '01K5PRD0000000000000GV1A01';

  static const String psKopiSusu = '01K5PS0000000000K0P1SVSV01';
  static const String psAmericano = '01K5PS000000000AMER1CAN001';
  static const String psCroissant = '01K5PS000000000CR01SSANT01';
  static const String psRotiPcs = '01K5PS00000000000R0T1PCS01';
  static const String psRotiLusin = '01K5PS0000000000R0T11VS1N1';
  static const String psKaos = '01K5PS00000000000000KA0S01';
  static const String psSusu = '01K5PS00000000000000SVSV01';

  static const String kelompokGula = '01K5K100000000000000GV1A01';
  static const String kelompokTambahan = '01K5K1000000000000TAMBAH01';
  static const String gulaNormal = '01K5P1000000000GV1AN0RMA11';
  static const String gulaKurang = '01K5P1000000000GV1AKVRANG1';
  static const String extraShot = '01K5P10000000000EXTRASH0T1';
  static const String oatMilk = '01K5P1000000000000ATM11K01';

  static const String barcodeKopiSusu = '8991000000011';
  static const String barcodeAmericano = '8991000000028';
  static const String barcodeRotiLusin = '8991000000035';
}

Map<String, Object?> ProdukUji(
  String uuid,
  String nama, {
  String jenis = 'Stok',
  String? sku,
  String? kategori,
  String? kelompokPajak = UuidUji.kelompokPbjt,
  String pelacakan = 'Tidak',
  bool tampil = true,
  bool aktif = true,
  String? urlGambarKecil,
}) => {
  'Uuid': uuid,
  'Sku': sku,
  'Nama': nama,
  'NamaStruk': null,
  'Jenis': jenis,
  'UuidKategori': kategori,
  'Merek': null,
  'UuidSatuanDasar': UuidUji.satuanPcs,
  'Pelacakan': pelacakan,
  'UuidKelompokPajak': kelompokPajak,
  'HargaTermasukPajak': null,
  'BolehMinus': true,
  'TampilDiPos': tampil,
  'TampilOnline': false,
  'UuidInduk': null,
  'AtributVarian': null,
  'UrlGambar': null,
  'UrlGambarKecil': urlGambarKecil,
  'Aktif': aktif,
  'Dihapus': false,
  'DiubahPada': '2026-09-20T01:00:00.000000Z',
};

Map<String, Object?> SatuanProdukUji(
  String uuid,
  String produk,
  String satuan, {
  String konversi = '1',
  bool bawaan = true,
}) => {
  'Uuid': uuid,
  'UuidProduk': produk,
  'UuidSatuan': satuan,
  'KonversiKeDasar': konversi,
  'DefaultJual': bawaan,
  'DefaultBeli': bawaan,
};

Map<String, Object?> HargaUji(String uuid, String produk, String satuan, String harga, {String minimum = '1.0000'}) => {
  'Uuid': uuid,
  'UuidProduk': produk,
  'UuidProdukSatuan': satuan,
  'UuidDaftarHarga': null,
  'JumlahMinimum': minimum,
  'Harga': harga,
};

Map<String, Object?> KatalogUji() => {
  'Skema': 1,
  'Lengkap': true,
  'Kursor': 'a3Vyc29yLTE',
  'WaktuServer': '2026-09-24T01:00:00Z',
  'Kategori': [
    {'Uuid': UuidUji.kategoriKopi, 'UuidInduk': null, 'Nama': 'Kopi', 'Urutan': 1},
    {'Uuid': UuidUji.kategoriMakanan, 'UuidInduk': null, 'Nama': 'Makanan', 'Urutan': 2},
  ],
  'Satuan': [
    {'Uuid': UuidUji.satuanCangkir, 'Nama': 'Cangkir', 'Simbol': 'cup', 'BolehDesimal': false},
    {'Uuid': UuidUji.satuanPcs, 'Nama': 'Pcs', 'Simbol': 'pcs', 'BolehDesimal': false},
    {'Uuid': UuidUji.satuanLusin, 'Nama': 'Lusin', 'Simbol': 'lsn', 'BolehDesimal': false},
  ],
  'KelompokPajak': [
    {
      'Uuid': UuidUji.kelompokPbjt,
      'Nama': 'Makanan & minuman',
      'Kategori': 'KenaPbjt',
      'Pajak': [
        {
          'KodeJenisPajak': 'PbjtMakananMinuman',
          'DasarPengenaan': 'SubtotalPlusLayanan',
          'Urutan': 1,
          'Kategori': 'Pbjt',
        },
      ],
    },
    {
      'Uuid': UuidUji.kelompokPpn,
      'Nama': 'Barang kena PPN',
      'Kategori': 'KenaPpn',
      'Pajak': [
        {'KodeJenisPajak': 'Ppn', 'DasarPengenaan': 'Subtotal', 'Urutan': 1},
      ],
    },
  ],
  'Produk': [
    ProdukUji(UuidUji.kopiSusu, 'Es Kopi Susu Aren', jenis: 'Resep', sku: 'KSA-01', kategori: UuidUji.kategoriKopi),
    ProdukUji(UuidUji.americano, 'Americano Panas', jenis: 'Resep', sku: 'AMR-01', kategori: UuidUji.kategoriKopi),
    ProdukUji(
      UuidUji.croissant,
      'Croissant Mentega Prancis Isi Cokelat Lumer Ukuran Jumbo',
      sku: 'CRS-01',
      kategori: UuidUji.kategoriMakanan,
    ),
    ProdukUji(
      UuidUji.roti,
      'Roti Tawar Gandum',
      sku: 'RTG-01',
      kategori: UuidUji.kategoriMakanan,
      kelompokPajak: UuidUji.kelompokPpn,
    ),
    ProdukUji(UuidUji.kaos, 'Kaos Kopi Senja', jenis: 'IndukVarian', sku: 'KAOS'),
    ProdukUji(UuidUji.susuUht, 'Susu UHT 1 Liter', sku: 'UHT-1L', pelacakan: 'Batch'),
    ProdukUji(UuidUji.gulaAren, 'Gula Aren Cair', jenis: 'BahanBaku', sku: 'GA-01', tampil: false),
  ],
  'ProdukSatuan': [
    SatuanProdukUji(UuidUji.psKopiSusu, UuidUji.kopiSusu, UuidUji.satuanCangkir),
    SatuanProdukUji(UuidUji.psAmericano, UuidUji.americano, UuidUji.satuanCangkir),
    SatuanProdukUji(UuidUji.psCroissant, UuidUji.croissant, UuidUji.satuanPcs),
    SatuanProdukUji(UuidUji.psRotiPcs, UuidUji.roti, UuidUji.satuanPcs),
    SatuanProdukUji(UuidUji.psRotiLusin, UuidUji.roti, UuidUji.satuanLusin, konversi: '12', bawaan: false),
    SatuanProdukUji(UuidUji.psKaos, UuidUji.kaos, UuidUji.satuanPcs),
    SatuanProdukUji(UuidUji.psSusu, UuidUji.susuUht, UuidUji.satuanPcs),
  ],
  'ProdukBarcode': [
    {
      'Uuid': '01K5BC00000000000K0P1SVSV1',
      'UuidProduk': UuidUji.kopiSusu,
      'UuidProdukSatuan': UuidUji.psKopiSusu,
      'Barcode': UuidUji.barcodeKopiSusu,
    },
    {
      'Uuid': '01K5BC0000000000AMER1CAN01',
      'UuidProduk': UuidUji.americano,
      'UuidProdukSatuan': UuidUji.psAmericano,
      'Barcode': UuidUji.barcodeAmericano,
    },
    {
      'Uuid': '01K5BC000000000R0T11VS1N01',
      'UuidProduk': UuidUji.roti,
      'UuidProdukSatuan': UuidUji.psRotiLusin,
      'Barcode': UuidUji.barcodeRotiLusin,
    },
  ],
  'DaftarHarga': <Object?>[],
  'ProdukHarga': [
    HargaUji('01K5HRG00000000000K0P1SVSV', UuidUji.kopiSusu, UuidUji.psKopiSusu, '18000.00'),
    HargaUji('01K5HRG0000000000AMER1CAN0', UuidUji.americano, UuidUji.psAmericano, '15000.00'),
    HargaUji('01K5HRG0000000000CR01SSANT', UuidUji.croissant, UuidUji.psCroissant, '25000.00'),
    HargaUji('01K5HRG00000000000R0T1PCS1', UuidUji.roti, UuidUji.psRotiPcs, '12000.00'),
    HargaUji('01K5HRG00000000000R0T1PCS2', UuidUji.roti, UuidUji.psRotiPcs, '11000.00', minimum: '10.0000'),
    HargaUji('01K5HRG0000000000R0T11VS1N', UuidUji.roti, UuidUji.psRotiLusin, '130000.00'),
    HargaUji('01K5HRG00000000000000KA0S1', UuidUji.kaos, UuidUji.psKaos, '95000.00'),
    HargaUji('01K5HRG00000000000000SVSV1', UuidUji.susuUht, UuidUji.psSusu, '21500.00'),
  ],
  'KelompokPilihan': [
    {'Uuid': UuidUji.kelompokGula, 'Nama': 'Level gula', 'MinimalPilih': 1, 'MaksimalPilih': 1, 'Urutan': 1},
    {'Uuid': UuidUji.kelompokTambahan, 'Nama': 'Tambahan', 'MinimalPilih': 0, 'MaksimalPilih': 2, 'Urutan': 2},
  ],
  'Pilihan': [
    PilihanUji(UuidUji.gulaNormal, UuidUji.kelompokGula, 'Normal', '0.00', 1),
    PilihanUji(UuidUji.gulaKurang, UuidUji.kelompokGula, 'Kurang manis', '0.00', 2),
    PilihanUji(UuidUji.extraShot, UuidUji.kelompokTambahan, 'Extra shot', '5000.00', 1),
    PilihanUji(UuidUji.oatMilk, UuidUji.kelompokTambahan, 'Oat milk', '7000.00', 2),
  ],
  'ProdukKelompokPilihan': [
    {
      'Uuid': '01K5PKP000000000000GV1A001',
      'UuidProduk': UuidUji.kopiSusu,
      'UuidKelompokPilihan': UuidUji.kelompokGula,
      'Urutan': 1,
    },
    {
      'Uuid': '01K5PKP00000000000TAMBAH01',
      'UuidProduk': UuidUji.kopiSusu,
      'UuidKelompokPilihan': UuidUji.kelompokTambahan,
      'Urutan': 2,
    },
  ],
  'Resep': <Object?>[],
  'PaketProdukDetail': <Object?>[],
  'Terhapus': <Object?>[],
};

Map<String, Object?> PilihanUji(String uuid, String kelompok, String nama, String harga, int urutan) => {
  'Uuid': uuid,
  'UuidKelompokPilihan': kelompok,
  'Nama': nama,
  'Harga': harga,
  'UuidProdukBahan': null,
  'Jumlah': null,
  'Aktif': true,
  'Urutan': urutan,
};

/// X8: katalog uji dengan daftar harga GoFood (Americano 19.000, Croissant 31.000; produk lain harga dasar).
Map<String, Object?> KatalogOjolUji() {
  final katalog = KatalogUji();
  katalog['DaftarHarga'] = [
    {
      'Uuid': '01K5DH000000000000G0F00DXX',
      'Nama': 'Harga GoFood',
      'UuidOutlet': null,
      'Kanal': 'GoFood',
      'TierPelanggan': null,
      'MulaiPada': null,
      'SelesaiPada': null,
      'Prioritas': 0,
      'Aktif': true,
    },
  ];
  (katalog['ProdukHarga']! as List<Map<String, Object?>>).addAll([
    {
      ...HargaUji('01K5HRG00000000AMERG0F00D1', UuidUji.americano, UuidUji.psAmericano, '19000.00'),
      'UuidDaftarHarga': '01K5DH000000000000G0F00DXX',
    },
    {
      ...HargaUji('01K5HRG00000000CR01G0F00D1', UuidUji.croissant, UuidUji.psCroissant, '31000.00'),
      'UuidDaftarHarga': '01K5DH000000000000G0F00DXX',
    },
  ]);
  return katalog;
}

const String ponsel = '01K5PRD0000000000000PONSEL01';
const String psPonsel = '01K5PS0000000000000PONSEL01';

/// Katalog uji + satu produk bernomor seri (ponsel).
Map<String, Object?> KatalogPonselUji() {
  final katalog = KatalogUji();
  katalog['Produk'] = [
    ...(katalog['Produk']! as List<Object?>),
    {...ProdukUji(ponsel, 'Ponsel Android 8/256 GB Hitam', sku: 'HP-8256', pelacakan: 'Seri'), 'MasaGaransiBulan': 12},
  ];
  katalog['ProdukSatuan'] = [
    ...(katalog['ProdukSatuan']! as List<Object?>),
    SatuanProdukUji(psPonsel, ponsel, UuidUji.satuanPcs),
  ];
  katalog['ProdukHarga'] = [
    ...(katalog['ProdukHarga']! as List<Object?>),
    HargaUji('01K5HRG000000000000PONSEL01', ponsel, psPonsel, '6500000.00'),
  ];
  return katalog;
}

const String satuanKg = '01K5SAT0000000000000000KG01';
const String jerukMedan = '01K5PRD000000000000JERUK001';
const String psJeruk = '01K5PS0000000000000JERUK001';

/// v3.55: katalog uji + produk timbangan (Jeruk Medan per kg, barcode label 7 digit `2712345`, Rp 32.000/kg).
Map<String, Object?> KatalogTimbanganUji() {
  final katalog = KatalogUji();
  katalog['Satuan'] = [
    ...(katalog['Satuan']! as List<Object?>),
    {'Uuid': satuanKg, 'Nama': 'Kilogram', 'Simbol': 'kg', 'BolehDesimal': true},
  ];
  katalog['Produk'] = [
    ...(katalog['Produk']! as List<Object?>),
    {...ProdukUji(jerukMedan, 'Jeruk Medan', sku: 'JRK-01'), 'UuidSatuanDasar': satuanKg},
  ];
  katalog['ProdukSatuan'] = [
    ...(katalog['ProdukSatuan']! as List<Object?>),
    SatuanProdukUji(psJeruk, jerukMedan, satuanKg),
  ];
  katalog['ProdukBarcode'] = [
    ...(katalog['ProdukBarcode']! as List<Object?>),
    {'Uuid': '01K5BC0000000000JERUK00001', 'UuidProduk': jerukMedan, 'UuidProdukSatuan': psJeruk, 'Barcode': '2712345'},
  ];
  katalog['ProdukHarga'] = [
    ...(katalog['ProdukHarga']! as List<Object?>),
    HargaUji('01K5HRG000000000000JERUK01', jerukMedan, psJeruk, '32000.00'),
  ];
  return katalog;
}

/// K-25: "Barang lain-lain" (NonStok, harga terbuka tanpa harga daftar) dan "Jasa servis ringan" (Jasa, harga terbuka
/// dengan harga daftar Rp 50.000 sebagai saran).
const String barangLainLain = '01K5PRD00000000000LA1NLA1N1';
const String jasaServis = '01K5PRD000000000000SERV1S01';
const String psBarangLain = '01K5PS00000000000LA1NLA1N1';
const String psJasaServis = '01K5PS000000000000SERV1S01';

Map<String, Object?> KatalogHargaTerbukaUji() {
  final katalog = KatalogUji();
  katalog['Produk'] = [
    ...(katalog['Produk']! as List<Object?>),
    {...ProdukUji(barangLainLain, 'Barang lain-lain', jenis: 'NonStok', sku: 'LAIN'), 'HargaTerbuka': true},
    {...ProdukUji(jasaServis, 'Jasa servis ringan', jenis: 'Jasa', sku: 'SRV-01'), 'HargaTerbuka': true},
  ];
  katalog['ProdukSatuan'] = [
    ...(katalog['ProdukSatuan']! as List<Object?>),
    SatuanProdukUji(psBarangLain, barangLainLain, UuidUji.satuanPcs),
    SatuanProdukUji(psJasaServis, jasaServis, UuidUji.satuanPcs),
  ];
  katalog['ProdukHarga'] = [
    ...(katalog['ProdukHarga']! as List<Object?>),
    HargaUji('01K5HRG0000000000SERV1S001', jasaServis, psJasaServis, '50000.00'),
  ];
  return katalog;
}

/// Bengkel bagian 2: jasa servis (Jasa, harga terbuka tanpa harga daftar — harga dari perintah kerja) dan oli (harga
/// daftar Rp 60.000; perintah kerja menyepakati Rp 55.000).
const String jasaServisMotor = '01K6PRD000000000000SERV1S1';
const String psJasaServisMotor = '01K6PS0000000000000SERV1S1';
const String oliMesin = '01K6PRD0000000000000OL1001';
const String psOliMesin = '01K6PS00000000000000OL1001';

/// Apotek bagian 2: obat per golongan (Rupiah realistis, nama panjang).
const String amoxicillin = '01K6PRD00000000000AM0X1C01';
const String asamMefenamat = '01K6PRD0000000000MEFENAM01';
const String diazepam = '01K6PRD0000000000D1AZEPAM1';
const String paracetamol = '01K6PRD000000000PARACETAM1';
const String ctm = '01K6PRD0000000000000CTM001';
const String psAmoxicillin = '01K6PS000000000000AM0X1C01';
const String psAsamMefenamat = '01K6PS0000000000MEFENAM001';
const String psDiazepam = '01K6PS0000000000D1AZEPAM01';
const String psParacetamol = '01K6PS000000000PARACETAM01';
const String psCtm = '01K6PS00000000000000CTM001';

Map<String, Object?> KatalogBengkelApotekUji() {
  final katalog = KatalogUji();
  Map<String, Object?> Obat(String uuid, String nama, String golongan, {bool owa = false, bool resep = false}) => {
    ...ProdukUji(uuid, nama, kelompokPajak: UuidUji.kelompokPpn),
    'GolonganObat': golongan,
    'ObatWajibApotek': owa,
    'Prekursor': false,
    'WajibResep': resep,
  };
  katalog['Produk'] = [
    ...(katalog['Produk']! as List<Object?>),
    {
      ...ProdukUji(jasaServisMotor, 'Servis rutin motor matic', jenis: 'Jasa', kelompokPajak: null),
      'HargaTerbuka': true,
    },
    ProdukUji(oliMesin, 'Oli Mesin MPX2 10W-30 0,8 Liter', kelompokPajak: null),
    Obat(amoxicillin, 'Amoxicillin Trihydrate 500 mg Kapsul Strip 10', 'Keras', resep: true),
    Obat(asamMefenamat, 'Asam Mefenamat 500 mg Tablet Strip 10', 'Keras', owa: true),
    Obat(diazepam, 'Diazepam 2 mg Tablet Strip 10', 'Psikotropika', resep: true),
    Obat(paracetamol, 'Paracetamol 500 mg Tablet Strip 10', 'Bebas'),
    Obat(ctm, 'CTM Chlorpheniramine 4 mg Tablet Strip 10', 'BebasTerbatas'),
  ];
  katalog['ProdukSatuan'] = [
    ...(katalog['ProdukSatuan']! as List<Object?>),
    SatuanProdukUji(psJasaServisMotor, jasaServisMotor, UuidUji.satuanPcs),
    SatuanProdukUji(psOliMesin, oliMesin, UuidUji.satuanPcs),
    SatuanProdukUji(psAmoxicillin, amoxicillin, UuidUji.satuanPcs),
    SatuanProdukUji(psAsamMefenamat, asamMefenamat, UuidUji.satuanPcs),
    SatuanProdukUji(psDiazepam, diazepam, UuidUji.satuanPcs),
    SatuanProdukUji(psParacetamol, paracetamol, UuidUji.satuanPcs),
    SatuanProdukUji(psCtm, ctm, UuidUji.satuanPcs),
  ];
  katalog['ProdukHarga'] = [
    ...(katalog['ProdukHarga']! as List<Object?>),
    HargaUji('01K6HRG00000000000000L1001', oliMesin, psOliMesin, '60000.00'),
    HargaUji('01K6HRG00000000000AM0X1C01', amoxicillin, psAmoxicillin, '8500.00'),
    HargaUji('01K6HRG0000000000MEFENAM01', asamMefenamat, psAsamMefenamat, '12000.00'),
    HargaUji('01K6HRG0000000000D1AZEPAM1', diazepam, psDiazepam, '15000.00'),
    HargaUji('01K6HRG000000000PARACETAM1', paracetamol, psParacetamol, '5000.00'),
    HargaUji('01K6HRG00000000000000CTM01', ctm, psCtm, '3500.00'),
  ];
  return katalog;
}

/// Bentuk persis `GET /api/pos/v1/perintah-kerja/{uuid}` (`PerintahKerjaPos::Petakan`): servis Rp 50.000 dengan
/// mekanik Joko, oli Rp 55.000 diskon Rp 5.000, kendaraan AD 1234 XY milik pelanggan Bambang.
Map<String, Object?> PerintahKerjaUji({bool siapTagih = true, String status = 'Selesai'}) => {
  'Uuid': '01K6PK000000000000000000A1',
  'Nomor': 'WO/SLB/2610/0007',
  'Status': status,
  'LabelStatus': status == 'Selesai' ? 'Selesai' : 'Dikerjakan',
  'SiapTagih': siapTagih,
  'DibuatPada': '2026-09-24T00:15:00Z',
  'Pelanggan': {
    'Uuid': '01K5PELANGGAN0000000000009',
    'Nama': 'Bambang Sutrisno',
    'NoHp': '0812****7890',
    'KodeTier': null,
  },
  'Kendaraan': {'Uuid': '01K6KND00000000000000000A1', 'NomorPolisi': 'AD 1234 XY', 'Label': 'Honda Vario 125 Hitam'},
  'KmMasuk': 23450,
  'Keluhan': 'Rem belakang bunyi, tarikan berat',
  'CatatanQc': 'Rem & tarikan sudah normal',
  'TotalDisetujui': '100000.00',
  'Baris': [
    {
      'Uuid': '01K6PKD0000000000000000001',
      'Jenis': 'Jasa',
      'UuidProduk': jasaServisMotor,
      'UuidProdukSatuan': psJasaServisMotor,
      'NamaProduk': 'Servis rutin motor matic',
      'Jumlah': '1.0000',
      'HargaSatuan': '50000.00',
      'Diskon': '0.00',
      'UuidKaryawan': '01K6KRY00000000000000MKN01',
      'NamaKaryawan': 'Joko Prasetyo',
      'Catatan': null,
    },
    {
      'Uuid': '01K6PKD0000000000000000002',
      'Jenis': 'Sparepart',
      'UuidProduk': oliMesin,
      'UuidProdukSatuan': psOliMesin,
      'NamaProduk': 'Oli Mesin MPX2 10W-30 0,8 Liter',
      'Jumlah': '1.0000',
      'HargaSatuan': '55000.00',
      'Diskon': '5000.00',
      'UuidKaryawan': null,
      'NamaKaryawan': null,
      'Catatan': 'Ganti baru',
    },
  ],
};
