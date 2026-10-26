import '../test/Pendukung/KatalogUji.dart';

/// Satu produk contoh: nama di ubin, harga (Rupiah bulat), indeks kategori, dan nama berkas gambar di
/// `AlatSitus/GambarProduk/` (foto CC0 hasil `Spesifikasi/Merek/SusunFotoProdukDemo.py`).
typedef ProdukDemo = (String nama, int kategori, int harga, String gambar);

/// Gambaran satu sektor usaha untuk tangkapan layar kasir di situs pemasaran (D-212).
class SektorDemo {
  const SektorDemo({
    required this.kode,
    required this.namaOutlet,
    required this.jenisProduk,
    required this.kenaPpn,
    required this.kategori,
    required this.produk,
    required this.keranjang,
  });

  /// Nama berkas hasil: `Sektor{kode}.png`.
  final String kode;
  final String namaOutlet;

  /// `Resep` (kafe), `Stok` (barang), atau `Jasa`.
  final String jenisProduk;

  /// Barang kena PPN (toko, apotek, bengkel); kafe memakai PBJT; jasa kecil tidak dipungut.
  final bool? kenaPpn;
  final List<String> kategori;
  final List<ProdukDemo> produk;

  /// Nama produk yang diketuk berurutan; nama yang sama dua kali = jumlah 2.
  final List<String> keranjang;

  Set<String> get gambarDipakai => {for (final p in produk) p.$4};
}

const sektorDemo = <SektorDemo>[
  SektorDemo(
    kode: 'Kafe',
    namaOutlet: 'Kopi Senja Solo Baru',
    jenisProduk: 'Resep',
    kenaPpn: null,
    kategori: ['Kopi', 'Non-kopi', 'Makanan'],
    produk: [
      ('Es Kopi Susu Aren', 0, 18000, 'KopiSusu'),
      ('Americano Panas', 0, 15000, 'Americano'),
      ('Kopi Tubruk Gayo', 0, 12000, 'KopiTubruk'),
      ('Cappuccino', 0, 24000, 'Cappuccino'),
      ('Matcha Latte', 1, 26000, 'Matcha'),
      ('Es Teh Leci', 1, 16000, 'TehLeci'),
      ('Cokelat Panas', 1, 22000, 'Cokelat'),
      ('Croissant Cokelat', 2, 25000, 'Croissant'),
      ('Roti Bakar Srikaya', 2, 20000, 'RotiSrikaya'),
      ('Pisang Goreng Keju', 2, 18000, 'PisangGoreng'),
      ('Nasi Goreng Kampung', 2, 32000, 'NasiGoreng'),
      ('Mie Goreng Jawa', 2, 30000, 'MieGoreng'),
    ],
    keranjang: ['Es Kopi Susu Aren', 'Es Kopi Susu Aren', 'Croissant Cokelat', 'Matcha Latte'],
  ),
  SektorDemo(
    kode: 'Toko',
    namaOutlet: 'Toko Berkah Jaya',
    jenisProduk: 'Stok',
    kenaPpn: true,
    kategori: ['Sembako', 'Minuman', 'Makanan', 'Kebutuhan harian'],
    produk: [
      ('Beras Pandan Wangi 5 kg', 0, 68000, 'Beras'),
      ('Minyak Goreng 2 L', 0, 38000, 'MinyakGoreng'),
      ('Gula Pasir 1 kg', 0, 17500, 'GulaPasir'),
      ('Telur Ayam 1 kg', 0, 29000, 'TelurAyam'),
      ('Air Galon Isi Ulang', 1, 21000, 'AirGalon'),
      ('Susu Segar Botol', 1, 14000, 'SusuSegar'),
      ('Kopi Biji 250 g', 1, 45000, 'KopiBiji'),
      ('Roti Tawar Gandum', 2, 16500, 'RotiTawar'),
      ('Keripik Singkong Pedas', 2, 12000, 'Keripik'),
      ('Makanan Kaleng', 2, 24000, 'MakananKaleng'),
      ('Sabun Mandi Batang', 3, 4500, 'SabunBatang'),
      ('Sampo & Sabun Cair', 3, 22000, 'SampoBotol'),
    ],
    keranjang: [
      'Beras Pandan Wangi 5 kg',
      'Minyak Goreng 2 L',
      'Telur Ayam 1 kg',
      'Roti Tawar Gandum',
      'Roti Tawar Gandum',
    ],
  ),
  SektorDemo(
    kode: 'Salon',
    namaOutlet: 'Salon Ayu Cantik',
    jenisProduk: 'Jasa',
    kenaPpn: null,
    kategori: ['Rambut', 'Kuku', 'Perawatan', 'Rias & produk'],
    produk: [
      ('Potong Rambut Wanita', 0, 55000, 'PotongRambut'),
      ('Blow Rambut', 0, 45000, 'BlowRambut'),
      ('Pewarnaan Rambut', 0, 250000, 'PewarnaanRambut'),
      ('Smoothing', 0, 400000, 'Smoothing'),
      ('Hair Spa', 0, 90000, 'HairSpa'),
      ('Manicure', 1, 60000, 'Manicure'),
      ('Nail Art', 1, 85000, 'NailArt'),
      ('Facial Wajah', 2, 120000, 'FacialWajah'),
      ('Body Spa', 2, 150000, 'BodySpa'),
      ('Pijat Aromaterapi', 2, 110000, 'Aromaterapi'),
      ('Make Up', 3, 175000, 'MakeUp'),
      ('Parfum Botol', 3, 95000, 'Parfum'),
    ],
    keranjang: ['Potong Rambut Wanita', 'Hair Spa', 'Manicure'],
  ),
  SektorDemo(
    kode: 'Laundry',
    namaOutlet: 'Laundry Bersih Wangi',
    jenisProduk: 'Jasa',
    kenaPpn: null,
    kategori: ['Kiloan', 'Satuan', 'Sepatu & boneka'],
    produk: [
      ('Cuci Kiloan Reguler', 0, 7000, 'CuciKiloan'),
      ('Cuci Setrika', 0, 9000, 'CuciSetrika'),
      ('Express 6 Jam', 0, 14000, 'Express'),
      ('Lipat Pakaian', 0, 3000, 'LipatPakaian'),
      ('Bedcover', 1, 35000, 'Bedcover'),
      ('Kemeja', 1, 8000, 'Kemeja'),
      ('Jas', 1, 30000, 'Jas'),
      ('Karpet', 1, 45000, 'Karpet'),
      ('Gordyn', 1, 20000, 'Gordyn'),
      ('Gaun', 1, 40000, 'Gaun'),
      ('Cuci Sepatu', 2, 35000, 'CuciSepatu'),
      ('Boneka', 2, 20000, 'Boneka'),
    ],
    keranjang: ['Cuci Setrika', 'Cuci Setrika', 'Bedcover', 'Kemeja', 'Cuci Sepatu'],
  ),
  SektorDemo(
    kode: 'Apotek',
    namaOutlet: 'Apotek Sehat Sentosa',
    jenisProduk: 'Stok',
    kenaPpn: true,
    kategori: ['Obat bebas', 'Vitamin & herbal', 'Alat kesehatan'],
    produk: [
      ('Paracetamol 500 mg', 0, 5000, 'Paracetamol'),
      ('Antasida Tablet', 0, 4500, 'Antasida'),
      ('Sirup Obat 60 ml', 0, 18000, 'SirupObat'),
      ('Obat Tetes 10 ml', 0, 15000, 'ObatTetes'),
      ('Vitamin C 500 mg', 1, 35000, 'VitaminC'),
      ('Multivitamin Tablet', 1, 42000, 'VitaminMulti'),
      ('Jamu Herbal', 1, 12000, 'JamuHerbal'),
      ('Minyak Aromaterapi', 1, 22000, 'MinyakAroma'),
      ('Masker Medis 50 pcs', 2, 28000, 'Masker'),
      ('Termometer', 2, 45000, 'Termometer'),
      ('Spuit 3 ml', 2, 3000, 'Spuit'),
      ('Perban Gulung', 2, 9000, 'Perban'),
    ],
    keranjang: ['Paracetamol 500 mg', 'Vitamin C 500 mg', 'Masker Medis 50 pcs', 'Masker Medis 50 pcs'],
  ),
  SektorDemo(
    kode: 'Bengkel',
    namaOutlet: 'Bengkel Maju Motor',
    jenisProduk: 'Stok',
    kenaPpn: true,
    kategori: ['Jasa servis', 'Oli & cairan', 'Sparepart', 'Perkakas'],
    produk: [
      ('Servis Rutin', 0, 75000, 'ServisRutin'),
      ('Servis Mesin', 0, 250000, 'ServisMesin'),
      ('Cuci Kendaraan', 0, 25000, 'CuciKendaraan'),
      ('Oli Mesin 0,8 L', 1, 52000, 'OliMesin'),
      ('Pelumas Semprot', 1, 28000, 'PelumasSemprot'),
      ('Ban Luar 80/90-14', 2, 280000, 'BanLuar'),
      ('Aki Motor 5 Ah', 2, 285000, 'AkiMotor'),
      ('Lampu Depan LED', 2, 65000, 'LampuDepan'),
      ('Helm Standar SNI', 2, 185000, 'Helm'),
      ('Kunci Pas Set', 3, 120000, 'KunciPas'),
      ('Set Kunci Sok', 3, 210000, 'SetKunciSok'),
      ('Obeng Set', 3, 45000, 'Obeng'),
    ],
    keranjang: ['Servis Rutin', 'Oli Mesin 0,8 L', 'Pelumas Semprot', 'Lampu Depan LED'],
  ),
];

/// Respons katalog (`GET /api/pos/v1/katalog`) untuk satu sektor, dengan gambar produk dari server tiruan.
Map<String, Object?> KatalogSektor(SektorDemo s) {
  final katalog = KatalogUji();
  String Id(String awalan, int i) => '$awalan${i.toString().padLeft(26 - awalan.length, '0')}';
  final kelompokPajak = s.kenaPpn == true
      ? UuidUji.kelompokPpn
      : (s.jenisProduk == 'Resep' ? UuidUji.kelompokPbjt : null);

  katalog['Kategori'] = [
    for (final (i, nama) in s.kategori.indexed)
      {'Uuid': Id('01K5KATSEKT', i), 'UuidInduk': null, 'Nama': nama, 'Urutan': i + 1},
  ];
  katalog['Produk'] = [
    for (final (i, p) in s.produk.indexed)
      ProdukUji(
        Id('01K5PRDSEKT', i),
        p.$1,
        jenis: s.jenisProduk,
        sku: '${s.kode.toUpperCase()}-${i + 1}',
        kategori: Id('01K5KATSEKT', p.$2),
        kelompokPajak: kelompokPajak,
        urlGambarKecil: 'https://payoung.test/api/pos/v1/gambar-demo/${p.$4}.jpg',
      ),
  ];
  katalog['ProdukSatuan'] = [
    for (final (i, _) in s.produk.indexed)
      SatuanProdukUji(Id('01K5PSSEKT', i), Id('01K5PRDSEKT', i), UuidUji.satuanPcs),
  ];
  katalog['ProdukHarga'] = [
    for (final (i, p) in s.produk.indexed)
      HargaUji(Id('01K5HRGSEKT', i), Id('01K5PRDSEKT', i), Id('01K5PSSEKT', i), '${p.$3}.00'),
  ];
  katalog['ProdukBarcode'] = <Object?>[];
  katalog['ProdukKelompokPilihan'] = <Object?>[];

  return katalog;
}
