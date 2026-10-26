import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

/// Rincian F-16c di perangkat: promo diunduh bersama katalog dan disimpan (berlaku offline), diterapkan otomatis oleh
/// `MesinPromo` saat keranjang dihitung, tidak dihitung sebagai diskon manual, dan dikirim di `Penjualan.Buat`.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  Map<String, Object?> PromoBundelKopi({String? selesai, int? kuota}) => {
    'ModeResolusi': 'Terbaik',
    'Promo': [
      {
        'Uuid': '01K5PROMO00000000000000001',
        'Kode': 'KOPI2',
        'Nama': '2 kopi Rp 25.000',
        'Prioritas': 10,
        'Eksklusif': false,
        'MulaiPada': null,
        'SelesaiPada': selesai,
        'KuotaTersisa': kuota,
        'Definisi': {
          'Kondisi': {
            'Jenis': 'Kategori',
            'Uuid': [UuidUji.kategoriKopi],
            'JumlahMinimal': '2',
          },
          'Aksi': {'Jenis': 'BundelHargaTetap', 'Harga': '25000'},
        },
      },
    ],
  };

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
  });
  tearDown(() => u.Tutup());

  test('promo diunduh saat katalog diperbarui. Server lama/offline = promo tersimpan tetap dipakai', () async {
    u.server.penangan = (p) async =>
        http.Response(jsonEncode(PromoBundelKopi()), 200, headers: {'content-type': 'application/json'});
    await u.katalog.PerbaruiPromo();
    expect(u.server.permintaan.single.url.path, endsWith('/api/pos/v1/promo'));
    expect((await u.MuatKonteks()).promo.single.kode, 'KOPI2');

    u.server.penangan = (p) async => throw http.ClientException('offline');
    await u.katalog.PerbaruiPromo();
    final k = await u.MuatKonteks();
    expect(k.promo.single.kode, 'KOPI2');
    expect(k.namaPromo['01K5PROMO00000000000000001'], '2 kopi Rp 25.000');
  });

  test('bundel 2 kopi Rp 25.000 diterapkan otomatis, bukan diskon manual; Promo ikut di Penjualan.Buat', () async {
    await u.SiapkanKatalog();
    await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode(PromoBundelKopi()));
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));

    var keranjang = u.penjualan.TambahBaris(
      Keranjang.kosong,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
      katalog,
      k,
    );
    expect(u.penjualan.Hitung(keranjang, k).promoTerpakai, isEmpty, reason: 'Satu kopi belum cukup untuk bundel.');
    keranjang = u.penjualan.TambahBaris(
      keranjang,
      u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!),
      katalog,
      k,
    );

    final hitungan = u.penjualan.Hitung(keranjang, k);
    final promo = hitungan.promoTerpakai.single;
    expect(promo.kode, 'KOPI2');
    // 2 × 15.000 = 30.000 → bundel 25.000, potongan 5.000.
    expect(keranjang.baris, hasLength(1));
    expect(promo.diskonBaris, {0: Uang.Dari('5000.00')});
    expect(hitungan.hasil.diskonBaris, Uang.Dari('5000.00'));
    expect(hitungan.hasilTanpaPromo.diskonBaris, Uang.Nol());
    expect(hitungan.AmbilNamaPromo(promo), '2 kopi Rp 25.000');

    // Diskon manual 10% pesanan dinilai dari hitungan tanpa promo.
    expect(
      u.penjualan.HitungDiskonPesanan(keranjang, DiskonManual.DariPersen(Decimal.fromInt(10)), k).diskon,
      Uang.Dari('3000.00'),
    );

    final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
    await u.penjualan.Bayar(
      keranjang: keranjang,
      pembayaran: [PembayaranMasukan(metode: tunai, jumlah: Uang.DariBulat(100000))],
      kasir: rina,
      k: k,
    );
    final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
    expect(data['Promo'], [
      {
        'UuidPromo': '01K5PROMO00000000000000001',
        'Kode': 'KOPI2',
        'DiskonBaris': [
          {'UuidBaris': keranjang.baris[0].uuid, 'Jumlah': '5000.00'},
        ],
        'DiskonPesanan': '0.00',
      },
    ]);
    expect(
      (data['Ringkasan']! as Map<String, Object?>)['TotalAkhir'],
      u.penjualan.Hitung(keranjang, k).hasil.totalAkhir.KeString(),
    );
  });

  test('promo berakhir atau kuota habis tidak diterapkan. Promo tak terbaca dilewati', () async {
    await u.SiapkanKatalog();
    final data = PromoBundelKopi(selesai: '2020-01-01T00:00:00Z');
    (data['Promo']! as List<Object?>).add({
      'Uuid': '01K5PROMO00000000000000002',
      'Kode': 'BARU',
      'Nama': 'Aksi versi baru',
      'Prioritas': 1,
      'Eksklusif': false,
      'Definisi': {
        'Aksi': {'Jenis': 'AksiMasaDepan'},
      },
    });
    await u.repositori.SimpanPengaturan(KunciPengaturan.promo, jsonEncode(data));
    final katalog = await u.MuatKatalog();
    final k = await u.MuatKonteks();
    expect(k.promo.map((p) => p.kode), ['KOPI2']);

    var keranjang = Keranjang.kosong;
    for (final uuid in [UuidUji.americano, UuidUji.americano]) {
      keranjang = u.penjualan.TambahBaris(
        keranjang,
        u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(uuid)!),
        katalog,
        k,
      );
    }
    expect(u.penjualan.Hitung(keranjang, k).promoTerpakai, isEmpty);
  });
}
