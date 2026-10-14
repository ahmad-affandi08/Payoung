import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:kasir/Domain/GalatKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Penjualan/LayananPenjualan.dart';
import 'package:kasir/Domain/Penjualan/LayananPreOrder.dart';
import 'package:kasir/Domain/Sesi/StafLokal.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';

import '../../Pendukung/KatalogUji.dart';
import '../../Pendukung/LingkunganUji.dart';

Matcher GalatDengan(String kode) => throwsA(isA<GalatKasir>().having((g) => g.kode, 'kode', kode));

/// FnB reservasi meja + DP (D-145) di perangkat: antrian dibaca online, tamu datang butuh izin & meja, reservasi
/// ditempel ke keranjang berisi sebagai sumber uang muka, lalu `Penjualan.Buat` membawa `UuidReservasiMeja` dan baris
/// bayar Uang Muka.
void main() {
  late LingkunganUji u;
  late StafLokal rina;

  http.Response Json(Object isi, int status) =>
      http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

  Map<String, Object?> Baris({
    String status = 'Dikonfirmasi',
    String? uuidMeja = '01K7MEJA000000000000000007',
    String sisa = '30000.00',
  }) => {
    'Uuid': '01K7RESERVASIMEJA00000001',
    'Nomor': 'RM/2026/10/0001',
    'MulaiPada': '2026-10-13T12:00:00Z',
    'SelesaiPada': '2026-10-13T14:00:00Z',
    'NamaPemesan': 'Bapak Hendra Wijaya',
    'NoHp': '0812-3456-7890',
    'JumlahTamu': 4,
    'UuidMeja': uuidMeja,
    'NamaMeja': uuidMeja == null ? null : '7',
    'Status': status,
    'LabelStatus': status,
    'Catatan': null,
    'UangMuka': {'Dicatat': '30000.00', 'Terpakai': '0.00', 'Sisa': sisa},
  };

  const metode = {'Uuid': '01K7METODEUANGMUKA00000001', 'Nama': 'Uang muka (DP)'};

  setUp(() async {
    u = LingkunganUji.Buat();
    await u.SiapkanAktif();
    rina = await u.Staf('Rina Wulandari');
    await u.SiapkanKatalog();
    await u.shift.BukaShift(kasir: rina, kasAwal: Uang.DariBulat(500000));
  });
  tearDown(() => u.Tutup());

  test('offline ditolak; antrian terbaca; tamu datang butuh izin dan meja, meja pilihan dikirim ke server', () async {
    u.server.penangan = (p) async => throw http.ClientException('offline');
    await expectLater(u.reservasiMeja.Ambil(), GalatDengan('PerluOnline'));

    u.server.penangan = (p) async => p.method == 'GET'
        ? Json({
            'Reservasi': [Baris(), Baris(uuidMeja: null)],
            'MetodeUangMuka': metode,
          }, 200)
        : Json({'Reservasi': Baris(status: 'Hadir'), 'MetodeUangMuka': metode}, 200);
    final antrian = await u.reservasiMeja.Ambil();
    expect(antrian.uuidMetodeUangMuka, '01K7METODEUANGMUKA00000001');
    final bermeja = antrian.reservasi.first;
    final tanpaMeja = antrian.reservasi.last;

    await u.reservasiMeja.Hadir(bermeja, kasir: rina);
    expect(jsonDecode(u.server.permintaan.last.body), {'UuidPengguna': rina.uuid});
    expect(u.server.permintaan.last.url.path, endsWith('/reservasi-meja/01K7RESERVASIMEJA00000001/hadir'));

    expect(() => u.reservasiMeja.Hadir(tanpaMeja, kasir: rina), GalatDengan('MejaBelumDipilih'));
    await u.reservasiMeja.Hadir(tanpaMeja, kasir: rina, uuidMeja: '01K7MEJA000000000000000009');
    expect(jsonDecode(u.server.permintaan.last.body), {
      'UuidPengguna': rina.uuid,
      'UuidMeja': '01K7MEJA000000000000000009',
    });

    const tamu = StafLokal(uuid: '01K7TAMU000000000000000001', nama: 'Dodi', pemilik: false, izin: []);
    expect(() => u.reservasiMeja.Hadir(bermeja, kasir: tamu), GalatDengan('TanpaIzin'));
  });

  test(
    'pasang uang muka: validasi keranjang & reservasi; bayar membawa UuidReservasiMeja dan baris Uang muka',
    () async {
      final katalog = await u.MuatKatalog();
      final k = await u.MuatKonteks();
      final reservasi = ReservasiMejaPos.DariJson(Baris());
      final kosong = Keranjang.kosong;
      final isi = Keranjang(baris: [u.penjualan.BuatBaris(katalog, k, katalog.CariProduk(UuidUji.americano)!)]);

      expect(
        () => u.reservasiMeja.PasangUangMuka(kosong, keranjangEfektif: kosong, reservasi: reservasi, uuidMetode: 'M'),
        GalatDengan('KeranjangKosong'),
      );
      expect(
        () => u.reservasiMeja.PasangUangMuka(
          isi,
          keranjangEfektif: isi,
          reservasi: ReservasiMejaPos.DariJson(Baris(status: 'Selesai')),
          uuidMetode: 'M',
        ),
        GalatDengan('ReservasiSelesai'),
      );
      expect(
        () => u.reservasiMeja.PasangUangMuka(isi, keranjangEfektif: isi, reservasi: reservasi, uuidMetode: null),
        GalatDengan('DataAwalBelumLengkap'),
      );

      final terpasang = u.reservasiMeja.PasangUangMuka(
        isi,
        keranjangEfektif: isi,
        reservasi: reservasi,
        uuidMetode: metode['Uuid'],
        namaMetode: metode['Nama'],
      );
      expect(terpasang.praPesan?.sumber, SumberUangMuka.reservasiMeja);
      expect(terpasang.praPesan?.sisaUangMuka, Uang.DariBulat(30000));
      expect(Keranjang.DariJson(terpasang.KeJson()).praPesan?.sumber, SumberUangMuka.reservasiMeja);
      // Sudah menagih dokumen uang muka lain: tidak boleh dua sumber.
      expect(
        () => u.reservasiMeja.PasangUangMuka(
          terpasang,
          keranjangEfektif: terpasang,
          reservasi: reservasi,
          uuidMetode: 'M',
        ),
        GalatDengan('KeranjangTerikat'),
      );

      final uangMuka = LayananPreOrder.MetodeUangMuka(terpasang.praPesan!);
      final tunai = k.metodePembayaran.firstWhere((m) => m.Jenis == 'Tunai');
      final total = u.penjualan.Hitung(terpasang, k).hasil.totalAkhir;
      // Uang muka melebihi sisa reservasi ditolak sebelum disimpan.
      final sisaKecil = u.reservasiMeja.PasangUangMuka(
        isi,
        keranjangEfektif: isi,
        reservasi: ReservasiMejaPos.DariJson(Baris(sisa: '10000.00')),
        uuidMetode: metode['Uuid'],
      );
      await expectLater(
        u.penjualan.Bayar(
          keranjang: sisaKecil,
          pembayaran: [
            PembayaranMasukan(metode: uangMuka, jumlah: Uang.DariBulat(12000)),
            PembayaranMasukan(metode: tunai, jumlah: total.Kurangi(Uang.DariBulat(12000))),
          ],
          kasir: rina,
          k: k,
        ),
        GalatDengan('UangMukaMelebihiSisa'),
      );
      final dipakai = total.Bandingkan(Uang.DariBulat(30000)) > 0 ? Uang.DariBulat(30000) : total;
      await u.penjualan.Bayar(
        keranjang: terpasang,
        pembayaran: [
          PembayaranMasukan(metode: uangMuka, jumlah: dipakai),
          if (total.Bandingkan(dipakai) > 0) PembayaranMasukan(metode: tunai, jumlah: total.Kurangi(dipakai)),
        ],
        kasir: rina,
        k: k,
      );
      final outbox = await (u.db.select(u.db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      final data = jsonDecode(outbox.last.Data) as Map<String, Object?>;
      expect(data['UuidReservasiMeja'], '01K7RESERVASIMEJA00000001');
      expect(data.containsKey('UuidPesananPenjualan'), isFalse);
      expect(data.containsKey('UuidPesananOnline'), isFalse);
      final pembayaran = (data['Pembayaran']! as List).cast<Map<String, Object?>>();
      expect(pembayaran.first['UuidMetodePembayaran'], '01K7METODEUANGMUKA00000001');
    },
  );
}
