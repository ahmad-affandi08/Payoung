import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:test/test.dart';

KlienPos BuatKlien(Future<http.Response> Function(http.Request permintaan) penangan, {String? token = 'Tkn'}) =>
    KlienPos(
      alamatDasar: Uri.parse('https://kasir.contoh.id/'),
      versiAplikasi: '0.1.0',
      ambilToken: () => token,
      klien: MockClient(penangan),
    );

http.Response Json(Object isi, int status) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

void main() {
  test('konfigurasi aplikasi: FiturPaket.PersetujuanJarakJauh dibaca; server lama tanpa kunci = null', () async {
    Future<KonfigurasiAplikasi> Ambil(Map<String, Object?> isi) =>
        BuatKlien((_) async => Json(isi, 200)).AmbilKonfigurasiAplikasi();

    final baru = await Ambil({
      'FiturPaket': {'PersetujuanJarakJauh': true},
    });
    final mati = await Ambil({
      'FiturPaket': {'PersetujuanJarakJauh': false},
    });
    final lama = await Ambil({'Aplikasi': <String, Object?>{}});

    expect(baru.persetujuanJarakJauh, isTrue);
    expect(mati.persetujuanJarakJauh, isFalse);
    expect(lama.persetujuanJarakJauh, isNull, reason: 'Server lama tidak boleh mengubah keadaan lokal.');
  });

  test('CekGalatPerantara: 429/413 dan balasan proxy tanpa badan Galat bukan item salah; Galat server tetap', () {
    GalatApi Galat(int status, String kode) => GalatApi(kode: kode, pesan: 'x', statusHttp: status);

    expect(Galat(429, 'GalatServer').CekGalatPerantara(), isTrue);
    expect(Galat(413, 'DataTidakValid').CekGalatPerantara(), isTrue);
    expect(Galat(403, 'GalatServer').CekGalatPerantara(), isTrue, reason: 'Proxy/WAF tanpa badan Galat.');
    expect(Galat(422, 'DataTidakValid').CekGalatPerantara(), isFalse);
    expect(Galat(409, 'ShiftTidakDitemukan').CekGalatPerantara(), isFalse);
  });

  test('aktivasi tanpa token; kode dirapikan; respons dipetakan termasuk kunci PIN offline', () async {
    late http.Request dikirim;
    final klien = BuatKlien((permintaan) async {
      dikirim = permintaan;
      return Json({
        'TokenPerangkat': '12|rahasia',
        'KunciPinOffline': 'a2V5',
        'Perangkat': {'Uuid': 'P1', 'Kode': 'POS-001', 'Nama': 'Kasir Depan'},
        'Outlet': {'Uuid': 'O1', 'Nama': 'Kopi Senja Solo Baru'},
        'Tenant': {'Uuid': 'T1', 'Nama': 'Kopi Senja'},
      }, 201);
    });

    final hasil = await klien.AktifkanPerangkat(kode: ' ab12cd34 ', platform: 'Android');

    expect(dikirim.url.toString(), 'https://kasir.contoh.id/api/pos/v1/perangkat/aktivasi');
    expect(dikirim.headers.containsKey('Authorization'), isFalse);
    expect(dikirim.headers['X-Versi-Aplikasi'], '0.1.0');
    expect(jsonDecode(dikirim.body), containsPair('Kode', 'AB12CD34'));
    expect(hasil.tokenPerangkat, '12|rahasia');
    expect(hasil.kunciPinOffline, 'a2V5');
    expect(hasil.kodePerangkat, 'POS-001');
    expect(hasil.namaUsaha, 'Kopi Senja');
  });

  test('sinkron memakai device token dan memetakan hasil per item', () async {
    late http.Request dikirim;
    final klien = BuatKlien((permintaan) async {
      dikirim = permintaan;
      return Json({
        'Hasil': [
          {'Uuid': 'A', 'Jenis': 'Shift.Buka', 'Status': 'Diterima', 'Galat': null},
          {
            'Uuid': 'B',
            'Jenis': 'MutasiKas.Catat',
            'Status': 'Ditolak',
            'Galat': {'Kode': 'PersetujuanDiperlukan', 'Pesan': 'Wajib disetujui'},
          },
        ],
        'WaktuServer': '2026-10-02T03:00:00Z',
        'PerluTinjauan': ['A'],
      }, 200);
    });

    final jawaban = await klien.KirimSinkron([
      const ItemOutbox(jenis: 'Shift.Buka', uuid: 'A', data: {'KasAwal': '500000.00'}),
      const ItemOutbox(jenis: 'MutasiKas.Catat', uuid: 'B', data: {}, uuidPerangkatAsal: 'PRGLAMA'),
    ]);
    final hasil = jawaban.hasil;
    final item = (jsonDecode(dikirim.body) as Map<String, Object?>)['Item']! as List<Object?>;

    expect(dikirim.headers['Authorization'], 'Bearer Tkn');
    expect(item, hasLength(2));
    // Audit P0 F-01: perangkat asal hanya dikirim bila diketahui.
    expect((item[0]! as Map<String, Object?>).containsKey('UuidPerangkatAsal'), isFalse);
    expect((item[1]! as Map<String, Object?>)['UuidPerangkatAsal'], 'PRGLAMA');
    expect(hasil.map((h) => h.status), [StatusItemSinkron.Diterima, StatusItemSinkron.Ditolak]);
    expect(hasil[1].kodeGalat, 'PersetujuanDiperlukan');
    expect(jawaban.perangkatDicabut, isFalse);
    expect(jawaban.waktuServer, DateTime.utc(2026, 10, 2, 3));
    expect(jawaban.perluTinjauan, ['A']);
    // Audit F-12: mutasi membawa Idempotency-Key berformat yang diterima server.
    expect(dikirim.headers['Idempotency-Key'], matches(RegExp(r'^pos-[0-9A-HJKMNP-TV-Z]{26}$')));
  });

  test(
    'galat 4xx menjadi GalatApi (format Galat & errors Laravel); 5xx dan putus jaringan menjadi GalatJaringan',
    () async {
      final dicabut = BuatKlien(
        (_) async => Json({
          'Galat': {'Kode': 'PerangkatDicabut', 'Pesan': 'Dicabut'},
        }, 403),
      );
      await expectLater(
        dicabut.AmbilDataAwal(),
        throwsA(isA<GalatApi>().having((g) => g.CekPerangkatDitolak(), 'ditolak', isTrue)),
      );

      final validasi = BuatKlien(
        (_) async => Json({
          'message': 'x',
          'errors': {
            'Pin': ['PIN harus 6 angka.'],
          },
        }, 422),
      );
      await expectLater(
        validasi.MasukPin(uuidPengguna: 'U', pin: '1'),
        throwsA(
          isA<GalatApi>()
              .having((g) => g.pesan, 'pesan', 'PIN harus 6 angka.')
              .having((g) => g.bidang, 'bidang', 'Pin'),
        ),
      );

      final rusak = BuatKlien((_) async => http.Response('oops', 502));
      await expectLater(rusak.AmbilDataAwal(), throwsA(isA<GalatJaringan>()));

      final putus = BuatKlien((_) async => throw http.ClientException('putus'));
      await expectLater(putus.AmbilDataAwal(), throwsA(isA<GalatJaringan>()));
    },
  );

  test('data awal: staf, izin, PIN terbungkus, kategori, parameter Argon2id', () async {
    final klien = BuatKlien(
      (_) async => Json({
        'Pengaturan': {'BatasKasKeluar': '200000.00', 'ShiftBersama': false},
        'KategoriKas': [
          {'Uuid': 'K1', 'Nama': 'Beli es batu', 'Jenis': 'Keluar'},
        ],
        'Staf': [
          {
            'Uuid': 'S1',
            'Nama': 'Rina',
            'Pemilik': false,
            'Izin': ['penjualan.buat'],
            'PinDiatur': true,
            'Pin': {'Garam': 'g', 'Nonce': 'n', 'Sandi': 's'},
          },
          {'Uuid': 'S2', 'Nama': 'Budi', 'Pemilik': true, 'Izin': <String>[], 'PinDiatur': false, 'Pin': null},
        ],
        'PinOffline': {
          'Tersedia': true,
          'Parameter': {'Iterasi': 2, 'MemoriKiB': 19456, 'Paralelisme': 1, 'Panjang': 32},
          'BatasSalah': 5,
          'MenitKunci': 5,
        },
        'WaktuServer': '2026-09-24T01:00:00Z',
      }, 200),
    );

    final data = await klien.AmbilDataAwal();

    expect(data.batasKasKeluar, '200000.00');
    expect(data.staf.first.PunyaIzin('penjualan.buat'), isTrue);
    expect(data.staf.first.pin?.garam, 'g');
    expect(data.staf.last.pin, isNull);
    expect(data.staf.last.PunyaIzin('kas.keluar.setujui'), isTrue);
    expect(data.parameterPin.memoriKiB, 19456);
    expect(data.kategoriKas.single.jenis, 'Keluar');
  });

  test('mode meja: data meja, snapshot pesanan terbuka dengan ETag (304 = null), kunci bayar 409', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      final jalur = permintaan.url.path;
      if (jalur.endsWith('/meja')) {
        return Json({
          'ModeMejaAktif': true,
          'Area': [
            {'Uuid': 'A1', 'Nama': 'Teras', 'Urutan': 1},
          ],
          'Meja': [
            {
              'Uuid': 'M7',
              'Nama': '7',
              'UuidArea': 'A1',
              'Kapasitas': 4,
              'Bentuk': 'Bundar',
              'Urutan': 0,
              'MinimumCharge': '150000.00',
            },
          ],
          'UuidProdukMinimumCharge': 'PR-MIN',
          'StasiunDapur': [
            {'Uuid': 'S1', 'Nama': 'Bar'},
          ],
          'UuidStasiunBawaan': 'S1',
          'KategoriStasiun': [
            {'UuidKategori': 'K1', 'UuidStasiun': 'S1'},
          ],
        }, 200);
      }
      if (jalur.endsWith('/pesanan-terbuka')) {
        if (permintaan.headers['If-None-Match'] == '"abc"') {
          return http.Response('', 304);
        }
        return http.Response(
          jsonEncode({
            'Pesanan': [
              {
                'Uuid': 'P1',
                'Nomor': 'OB/JKT1/260925/K01-0001',
                'UuidMeja': 'M7',
                'NamaMeja': '7',
                'JumlahTamu': 4,
                'DibukaPada': '2026-09-25T10:00:00Z',
                'DikunciBayar': false,
                'MintaBillPada': '2026-09-25T11:00:00Z',
                'Baris': [
                  {
                    'Uuid': 'B1',
                    'UuidProduk': 'PR1',
                    'NamaProduk': 'Es Kopi Susu',
                    'Jumlah': '2.0000',
                    'HargaSatuan': '25000.00',
                    'HargaPilihan': '0.00',
                    'Pilihan': <Object>[],
                    'Ronde': 1,
                    'Dibatalkan': false,
                    'DikirimKeDapur': true,
                    'StatusDapur': 'Dimasak',
                  },
                ],
              },
            ],
            'Ditutup': [
              {'Uuid': 'P0', 'Status': 'Dibayar'},
            ],
            'MejaPerluDibersihkan': [
              {'UuidMeja': 'M9', 'Sejak': '2026-09-25T09:30:00Z'},
            ],
          }),
          200,
          headers: {'content-type': 'application/json', 'etag': '"abc"'},
        );
      }
      return Json({
        'Galat': {'Kode': 'PesananSedangDibayar', 'Pesan': 'Sedang dibayar di perangkat lain.'},
      }, 409);
    });

    final meja = await klien.AmbilMeja();
    expect(meja.modeMejaAktif, isTrue);
    expect(meja.meja.single.uuidArea, 'A1');
    expect(meja.uuidStasiunBawaan, 'S1');
    expect(meja.kategoriStasiun, {'K1': 'S1'});
    // D-144: minimum charge yang berlaku per meja + produk penampung selisihnya (server lama = null).
    expect(meja.meja.single.minimumCharge, '150000.00');
    expect(meja.uuidProdukMinimumCharge, 'PR-MIN');
    expect(MejaPos.DariJson({'Uuid': 'M8', 'Nama': '8'}).minimumCharge, isNull);
    // POS-03: letak di denah; server lama atau meja belum ditata = null.
    final lama = MejaPos.DariJson({'Uuid': 'M8', 'Nama': '8'});
    expect((lama.posisiX, lama.posisiY), (null, null));
    final tertata = MejaPos.DariJson({'Uuid': 'M9', 'Nama': '9', 'PosisiX': 250, 'PosisiY': 700});
    expect((tertata.posisiX, tertata.posisiY), (250, 700));

    final snapshot = await klien.AmbilPesananTerbuka();
    expect(snapshot!.etag, '"abc"');
    expect(snapshot.pesanan.single.baris.single.statusDapur, 'Dimasak');
    expect(snapshot.pesanan.single.baris.single.jumlah, '2.0000');
    expect(snapshot.pesanan.single.baris.single.uuidProduk, 'PR1');
    expect(snapshot.pesanan.single.baris.single.uuidProdukSatuan, isNull);
    expect(snapshot.ditutup.single.uuid, 'P0');
    expect(snapshot.pesanan.single.mintaBillPada, DateTime.utc(2026, 9, 25, 11));
    expect(snapshot.mejaPerluDibersihkan.single.uuidMeja, 'M9');
    expect(snapshot.mejaPerluDibersihkan.single.sejak, DateTime.utc(2026, 9, 25, 9, 30));
    expect(await klien.AmbilPesananTerbuka(etag: '"abc"'), isNull);

    await expectLater(
      klien.KunciBayar('P1'),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'PesananSedangDibayar')),
    );
    expect(dikirim.last.method, 'POST');
    expect(dikirim.last.url.path, '/api/pos/v1/pesanan-terbuka/P1/kunci-bayar');
  });

  test('KDS: tiket per stasiun lewat stasiun[] dan ubah status', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.method == 'GET') {
        return Json({
          'Tiket': [
            {
              'Uuid': 'T1',
              'UuidStasiun': 'S2',
              'NomorDokumen': 'OB/JKT1/260925/K01-0001',
              'NamaMeja': '7',
              'Ronde': 2,
              'Status': 'Antre',
              'DikirimPada': '2026-09-25T10:05:00Z',
              'Baris': [
                {
                  'UuidBaris': 'B1',
                  'NamaProduk': 'Nasi Goreng Kampung',
                  'Jumlah': '1.0000',
                  'Pilihan': ['Pedas'],
                  'Catatan': 'Tanpa kecap',
                  'Dibatalkan': false,
                },
              ],
            },
          ],
          'WaktuServer': '2026-09-25T10:15:00Z',
        }, 200);
      }
      return Json({'Uuid': 'T1', 'Status': 'Dimasak'}, 200);
    });

    final daftar = await klien.AmbilTiketDapur(stasiun: ['S2']);
    expect(dikirim.first.url.queryParametersAll['stasiun[]'], ['S2']);
    expect(daftar.tiket.single.ronde, 2);
    expect(daftar.tiket.single.baris.single.pilihan, ['Pedas']);
    expect(daftar.waktuServer, DateTime.utc(2026, 9, 25, 10, 15));
    expect(await klien.UbahStatusTiket('T1', 'Dimasak'), 'Dimasak');
    expect(jsonDecode(dikirim.last.body), {'Status': 'Dimasak'});
  });

  test('F-16a cari pelanggan: kata < 3 tanpa permintaan; hasil tersamar', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'Pelanggan': [
          {
            'Uuid': 'P1',
            'Nama': 'Ani Rahmawati',
            'NoHp': '0812****7890',
            'KodeTier': 'GOLD',
            'NamaTier': 'Gold',
            'SaldoPoin': 125,
          },
          {'Uuid': 'P2', 'Nama': 'Anita (server lama)', 'NoHp': '0813****2222'},
        ],
      }, 200);
    });

    expect(await klien.CariPelanggan(' an '), isEmpty);
    expect(dikirim, isEmpty);
    final hasil = await klien.CariPelanggan('ani r');
    expect(dikirim.single.url.queryParameters['kata'], 'ani r');
    expect(hasil.first.nama, 'Ani Rahmawati');
    expect(hasil.first.noHpSamar, '0812****7890');
    expect(hasil.first.kodeTier, 'GOLD');
    expect(hasil.first.saldoPoin, 125);
    expect(hasil.last.kodeTier, isNull);
    expect(hasil.last.saldoPoin, 0);
  });

  test(
    'F-16c bagian 3: data promo pelanggan (hari lahir, jumlah transaksi, pemakaian) + tanggal bisnis acuannya',
    () async {
      final klien = BuatKlien(
        (permintaan) async => Json({
          'Pelanggan': [
            {
              'Uuid': 'P1',
              'Nama': 'Ani Rahmawati',
              'NoHp': '0812****7890',
              'HariLahir': '09-26',
              'JumlahTransaksi': 3,
              'PemakaianPromo': {
                'PR-HARIAN': {'Hari': 1, 'Promo': 4},
              },
            },
            {'Uuid': 'P2', 'Nama': 'Anita (server lama)', 'NoHp': '0813****2222', 'PemakaianPromo': <Object?>[]},
          ],
          'TanggalBisnis': '2026-09-26',
        }, 200),
      );

      final hasil = await klien.CariPelanggan('ani');
      expect(hasil.first.hariLahir, '09-26');
      expect(hasil.first.jumlahTransaksi, 3);
      expect(hasil.first.pemakaianPromo['PR-HARIAN'], (hari: 1, promo: 4));
      expect(hasil.first.pemakaianPada, '2026-09-26');
      expect(hasil.last.hariLahir, isNull);
      expect(hasil.last.jumlahTransaksi, isNull);
      expect(hasil.last.pemakaianPromo, isEmpty);
    },
  );

  test('F-16c bagian 3: promo diminta dengan voucher=1&lanjutan=1', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({'ModeResolusi': 'Terbaik', 'Promo': <Object?>[], 'WaktuServer': '2026-09-26T03:00:00Z'}, 200);
    });

    await klien.AmbilPromo();
    expect(dikirim.single.url.queryParameters, {'voucher': '1', 'lanjutan': '1'});
  });

  test('F-16b saldo poin: jalur per pelanggan, aturan tukar', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'Pelanggan': {'Uuid': 'P1', 'SaldoPoin': 120},
        'TukarPoin': {'Berlaku': true, 'NilaiTukarPoin': '100.00', 'MinimalTukarPoin': 10},
      }, 200);
    });

    final saldo = await klien.AmbilSaldoPoin('P1');
    expect(dikirim.single.url.path, endsWith('/api/pos/v1/pelanggan/P1/poin'));
    expect(saldo.saldoPoin, 120);
    expect(saldo.berlaku, isTrue);
    expect(saldo.nilaiTukarPoin, '100.00');
    expect(saldo.minimalTukarPoin, 10);
  });

  test('F-16d saldo deposit: jalur per pelanggan, saldo desimal apa adanya (boleh minus)', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'Pelanggan': {'Uuid': 'P1', 'SaldoDeposit': '-27000.00'},
        'Berlaku': true,
      }, 200);
    });

    final saldo = await klien.AmbilSaldoDeposit('P1');
    expect(dikirim.single.url.path, endsWith('/api/pos/v1/pelanggan/P1/deposit'));
    expect(saldo.saldoDeposit, '-27000.00');
    expect(saldo.berlaku, isTrue);
  });

  test('F-16d paket sesi: jalur per pelanggan, paket aktif & layanan yang boleh ditukar', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'Pelanggan': {'Uuid': 'P1'},
        'Berlaku': true,
        'Paket': [
          {
            'Uuid': 'SS1',
            'NamaPaket': 'Paket Creambath 10x',
            'JumlahSesi': 10,
            'SisaSesi': 7,
            'BerlakuSampai': '2026-12-31',
            'NomorPenjualan': 'INV/SLB/260924/POS-001-0008',
            'SemuaProdukJasa': false,
            'ProdukBerlaku': [
              {'Uuid': 'PR1', 'Nama': 'Creambath'},
            ],
          },
        ],
      }, 200);
    });

    final saldo = await klien.AmbilSaldoSesi('P1');
    expect(dikirim.single.url.path, endsWith('/api/pos/v1/pelanggan/P1/sesi'));
    expect(saldo.berlaku, isTrue);
    expect(saldo.paket.single.sisaSesi, 7);
    expect(saldo.paket.single.produkBerlaku.single.nama, 'Creambath');
  });

  test('F-16c promo: daftar promo aktif + mode resolusi, definisi dibawa apa adanya', () async {
    final klien = BuatKlien((permintaan) async {
      expect(permintaan.url.path, endsWith('/api/pos/v1/promo'));
      expect(permintaan.url.queryParameters['voucher'], '1');
      return Json({
        'ModeResolusi': 'PrioritasKetat',
        'Promo': [
          {
            'Uuid': 'PR1',
            'Kode': 'KOPI10',
            'Nama': 'Diskon 10% kopi',
            'Prioritas': 5,
            'Eksklusif': false,
            'MulaiPada': '2026-10-01T00:00:00Z',
            'SelesaiPada': null,
            'KuotaTersisa': 12,
            'Definisi': {
              'Aksi': {'Jenis': 'DiskonPersenItem', 'Persen': '10'},
            },
          },
        ],
      }, 200);
    });

    final data = await klien.AmbilPromo();
    expect(data.modeResolusi, 'PrioritasKetat');
    expect(data.promo.single.kode, 'KOPI10');
    expect(data.promo.single.kuotaTersisa, 12);
    expect(data.promo.single.mulaiPada, DateTime.utc(2026, 10));
    expect(data.promo.single.selesaiPada, isNull);
    expect(DataPromoPos.DariJson(data.KeJson()).promo.single.definisi['Aksi'], {
      'Jenis': 'DiskonPersenItem',
      'Persen': '10',
    });
  });

  test('F-16c bagian 2 voucher: pesan mengirim kode & penjualan, promo ikut; habis → GalatApi VoucherHabis', () async {
    final dikirim = <http.Request>[];
    var habis = false;
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (habis) {
        return Json({
          'Galat': {'Kode': 'VoucherHabis', 'Pesan': 'Voucher HEMAT10K sudah habis dipakai.'},
        }, 409);
      }
      if (permintaan.url.path.endsWith('/lepas')) {
        return http.Response('', 204);
      }
      return Json({
        'Voucher': {'Kode': 'HEMAT10K', 'UuidPromo': 'PR2', 'DipesanSampai': '2026-10-05T06:00:00Z', 'SisaPakai': 0},
        'Promo': {
          'Uuid': 'PR2',
          'Kode': 'VCR-HEMAT',
          'Nama': 'Voucher hemat',
          'Prioritas': 0,
          'Eksklusif': false,
          'MulaiPada': null,
          'SelesaiPada': null,
          'KuotaTersisa': null,
          'Definisi': {
            'WajibVoucher': true,
            'Aksi': {'Jenis': 'DiskonTetapPesanan', 'Jumlah': '10000'},
          },
        },
      }, 200);
    });

    final voucher = await klien.PesanVoucher('hemat10k', 'J1');
    expect(dikirim.single.url.path, endsWith('/api/pos/v1/voucher/pesan'));
    expect(jsonDecode(dikirim.single.body), {'Kode': 'hemat10k', 'UuidPenjualan': 'J1'});
    expect(voucher.kode, 'HEMAT10K');
    expect(voucher.uuidPromo, 'PR2');
    expect(voucher.sisaPakai, 0);
    expect(voucher.dipesanSampai, DateTime.utc(2026, 10, 5, 6));
    expect(voucher.promo.definisi['WajibVoucher'], isTrue);

    await klien.LepasVoucher('HEMAT10K', 'J1');
    expect(dikirim.last.url.path, endsWith('/api/pos/v1/voucher/lepas'));

    habis = true;
    await expectLater(
      klien.PesanVoucher('HEMAT10K', 'J2'),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'VoucherHabis')),
    );
  });

  test('F-12 bagian 2: cari pre-order membawa baris, sisa DP, pelanggan, dan metode Uang Muka', () async {
    final klien = BuatKlien((permintaan) async {
      expect(permintaan.url.path, endsWith('/api/pos/v1/pesanan-penjualan'));
      expect(permintaan.url.queryParameters['kata'], 'ratna');
      return Json({
        'Pesanan': [
          {
            'Uuid': 'PO1',
            'Nomor': 'SO/SLO/260925/POS-001-0001',
            'Status': 'Siap',
            'TanggalAmbil': '2026-09-28',
            'Catatan': null,
            'TotalPesanan': '77000.00',
            'UangMuka': '50000.00',
            'SisaUangMuka': '50000.00',
            'Pelanggan': {
              'Uuid': 'PL1',
              'Nama': 'Ibu Ratna',
              'NoHp': '0813****0077',
              'KodeTier': null,
              'NamaTier': null,
            },
            'Baris': [
              {
                'Uuid': 'B1',
                'UuidProduk': 'P1',
                'UuidProdukSatuan': null,
                'NamaProduk': 'Kue Cokelat',
                'Jumlah': '2.0000',
                'HargaSatuan': '38500.00',
                'HargaPilihan': '0.00',
                'Pilihan': <Object?>[],
                'Catatan': 'Krim vanila',
              },
            ],
          },
        ],
        'MetodeUangMuka': {'Uuid': 'MUM', 'Nama': 'Uang muka (DP)'},
      }, 200);
    });

    final hasil = await klien.CariPesananPenjualan(' ratna ');
    final p = hasil.pesanan.single;
    expect(p.sisaUangMuka, '50000.00');
    expect(p.pelanggan?['Nama'], 'Ibu Ratna');
    expect(p.baris.single.catatan, 'Krim vanila');
    expect(hasil.uuidMetodeUangMuka, 'MUM');
  });

  test(
    'P-10 konfigurasi-aplikasi: header X-Outbox-Tertunda, versi, catatan rilis, dan flag fitur (kunci bertitik)',
    () async {
      late http.Request dikirim;
      final klien = KlienPos(
        alamatDasar: Uri.parse('https://kasir.contoh.id/'),
        versiAplikasi: '1.4.0',
        ambilToken: () => 'Tkn',
        ambilJumlahOutbox: () => 3,
        klien: MockClient((permintaan) async {
          dikirim = permintaan;
          return Json({
            'Aplikasi': {
              'VersiSaatIni': '1.4.0',
              'VersiTerbaru': '1.5.0',
              'VersiMinimal': '1.5.0',
              'TautanUnduh': 'https://unduh.payoung.id/kasir.apk',
              'CatatanRilis': 'Cetak struk Bluetooth.',
              'AdaPembaruan': true,
              'WajibPembaruan': true,
            },
            'FlagFitur': {'pos.mode-meja': false, 'kasir.struk-digital': true, 'rusak': 'ya'},
            'Pengumuman': [
              {
                'Uuid': 'P1',
                'Judul': 'Pemeliharaan server',
                'Isi': 'Sinkron berhenti sebentar.',
                'Jenis': 'Pemeliharaan',
                'LabelJenis': 'Pemeliharaan terjadwal',
                'Tautan': null,
                'BolehDitutup': false,
                'PemeliharaanMulai': '2026-10-10T16:00:00Z',
                'PemeliharaanSelesai': '2026-10-10T18:00:00Z',
                'TampilSampai': '2026-10-10T18:00:00Z',
              },
              {'Uuid': 'P2', 'Judul': 'Fitur baru', 'Isi': 'Cetak ulang struk.', 'Jenis': 'JenisMasaDepan'},
              {'Uuid': '', 'Judul': 'Rusak'},
            ],
          }, 200);
        }),
      );

      final konfigurasi = await klien.AmbilKonfigurasiAplikasi();

      expect(dikirim.url.path, '/api/pos/v1/konfigurasi-aplikasi');
      expect(dikirim.headers['X-Outbox-Tertunda'], '3');
      expect(konfigurasi.wajibPembaruan, isTrue);
      expect(konfigurasi.versiTerbaru, '1.5.0');
      expect(konfigurasi.catatanRilis, 'Cetak struk Bluetooth.');
      expect(konfigurasi.flagFitur, {'pos.mode-meja': false, 'kasir.struk-digital': true});
      expect(konfigurasi.CekFlag('pos.mode-meja'), isFalse);
      expect(konfigurasi.CekFlag('tidak.ada'), isTrue);
      // v3.45 PGL-19: pengumuman; jenis tak dikenal = Info (boleh ditutup), baris rusak dibuang.
      expect(konfigurasi.pengumuman.map((p) => p.uuid), ['P1', 'P2']);
      expect(konfigurasi.pengumuman.first.jenis, JenisPengumuman.Pemeliharaan);
      expect(konfigurasi.pengumuman.first.bolehDitutup, isFalse);
      expect(konfigurasi.pengumuman.first.pemeliharaanMulai, DateTime.utc(2026, 10, 10, 16).toLocal());
      expect(konfigurasi.pengumuman.last.jenis, JenisPengumuman.Info);
      expect(konfigurasi.pengumuman.last.bolehDitutup, isTrue);
    },
  );

  test(
    'P-10 server lama tanpa FlagFitur & versi: nilai bawaan aman; tanpa ambilJumlahOutbox header tidak dikirim',
    () async {
      late http.Request dikirim;
      final klien = BuatKlien((permintaan) async {
        dikirim = permintaan;
        return Json({'Aplikasi': <String, Object?>{}}, 200);
      });

      final konfigurasi = await klien.AmbilKonfigurasiAplikasi();

      expect(dikirim.headers.containsKey('X-Outbox-Tertunda'), isFalse);
      expect(konfigurasi.wajibPembaruan, isFalse);
      expect(konfigurasi.adaPembaruan, isFalse);
      expect(konfigurasi.flagFitur, isEmpty);
      expect(konfigurasi.pengumuman, isEmpty);
    },
  );

  test('F-17 pesan sendiri: daftar menunggu, terima & tolak, 409 SudahDiproses', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.method == 'GET') {
        return Json({
          'Pesanan': [
            {
              'Uuid': 'Q1',
              'Nomor': 'QR/JKT1/260926-0001',
              'UuidMeja': 'M7',
              'NamaMeja': '7',
              'NamaPemesan': 'Bu Ani',
              'Catatan': null,
              'DibuatPada': '2026-09-26T03:00:00Z',
              'Subtotal': '50000.00',
              'Baris': [
                {
                  'Uuid': 'B1',
                  'UuidProduk': 'P1',
                  'UuidProdukSatuan': 'PS1',
                  'NamaProduk': 'Es Kopi Susu',
                  'Jumlah': '2',
                  'HargaSatuan': '25000.00',
                  'HargaPilihan': '0.00',
                  'Pilihan': <Object?>[],
                  'Catatan': 'Es sedikit',
                },
              ],
            },
          ],
        }, 200);
      }
      if (permintaan.url.path.endsWith('/tolak')) {
        return Json({
          'Galat': {'Kode': 'SudahDiproses', 'Pesan': 'Pesanan sudah diterima perangkat lain.'},
        }, 409);
      }
      return Json({'Uuid': 'Q1', 'Status': 'Diterima', 'UuidPesananTerbuka': 'OB1'}, 200);
    });

    final daftar = await klien.AmbilPesanSendiri();
    expect(daftar.single.nomor, 'QR/JKT1/260926-0001');
    expect(daftar.single.namaMeja, '7');
    expect(daftar.single.dibuatPada, DateTime.utc(2026, 9, 26, 3));
    expect(daftar.single.baris.single.catatan, 'Es sedikit');
    expect(dikirim.last.url.path, '/api/pos/v1/pesan-sendiri');

    await klien.TerimaPesanSendiri('Q1', uuidPengguna: 'U1', uuidPesananTerbuka: 'OB1');
    expect(dikirim.last.url.path, '/api/pos/v1/pesan-sendiri/Q1/terima');
    expect(jsonDecode(dikirim.last.body), {'UuidPengguna': 'U1', 'UuidPesananTerbuka': 'OB1'});

    await expectLater(
      klien.TolakPesanSendiri('Q1', uuidPengguna: 'U1', alasan: 'Menu habis'),
      throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'SudahDiproses')),
    );
    expect(jsonDecode(dikirim.last.body), {'UuidPengguna': 'U1', 'Alasan': 'Menu habis'});
  });

  test('F-07 mode service: antrian reservasi per tanggal dan check-in', () async {
    final dikirim = <http.Request>[];
    final baris = {
      'Uuid': 'R1',
      'Nomor': 'RS/2026/10/0001',
      'MulaiPada': '2026-10-13T03:00:00Z',
      'SelesaiPada': '2026-10-13T04:00:00Z',
      'NamaPelanggan': 'Rina Wulandari',
      'NoHp': '0812-3456-7890',
      'Pelanggan': null,
      'UuidProduk': 'P1',
      'NamaLayanan': 'Creambath Ginseng',
      'UuidStaf': 'K1',
      'NamaStaf': 'Maya',
      'Status': 'Dikonfirmasi',
      'LabelStatus': 'Dikonfirmasi',
      'Catatan': null,
    };
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.method == 'GET') {
        return Json({
          'Reservasi': [baris],
        }, 200);
      }
      return Json({
        'Reservasi': {...baris, 'Status': 'Hadir', 'LabelStatus': 'Hadir'},
      }, 200);
    });

    final daftar = await klien.AmbilReservasi(tanggal: '2026-10-13');
    expect(dikirim.last.url.path, '/api/pos/v1/reservasi');
    expect(dikirim.last.url.queryParameters, {'tanggal': '2026-10-13'});
    expect(daftar.single.mulaiPada, DateTime.utc(2026, 10, 13, 3));
    expect(daftar.single.uuidStaf, 'K1');
    expect(daftar.single.BisaDilayani, isTrue);

    await klien.AmbilReservasi();
    expect(dikirim.last.url.query, isEmpty);

    final hadir = await klien.HadirReservasi('R1', uuidPengguna: 'U1');
    expect(dikirim.last.url.path, '/api/pos/v1/reservasi/R1/hadir');
    expect(jsonDecode(dikirim.last.body), {'UuidPengguna': 'U1'});
    expect(hadir.status, 'Hadir');
  });

  test('F-17 BR-17.2: daftar produk habis outlet dan tandai habis / tersedia lagi', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.method == 'GET') {
        return Json({
          'Produk': ['P1', 'P2'],
        }, 200);
      }
      final isi = jsonDecode(permintaan.body) as Map<String, Object?>;
      return Json({'Uuid': 'P1', 'Habis': isi['Habis']}, 200);
    });

    final habis = await klien.AmbilProdukHabis();
    expect(dikirim.last.url.path, '/api/pos/v1/produk-habis');
    expect(habis, {'P1', 'P2'});

    expect(await klien.UbahKetersediaanProduk('P1', habis: true, uuidPengguna: 'U1'), isTrue);
    expect(dikirim.last.url.path, '/api/pos/v1/produk/P1/habis');
    expect(jsonDecode(dikirim.last.body), {'UuidPengguna': 'U1', 'Habis': true});
    expect(dikirim.last.headers['Idempotency-Key'], startsWith('pos-'));

    expect(await klien.UbahKetersediaanProduk('P1', habis: false, uuidPengguna: 'U1'), isFalse);
    expect(jsonDecode(dikirim.last.body), {'UuidPengguna': 'U1', 'Habis': false});
  });

  test('K-24: ringkasan akhir hari outlet', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'Tanggal': '2026-10-02',
        'JumlahTransaksi': 2,
        'JumlahVoid': 1,
        'JumlahRetur': 1,
        'Kotor': '154000.00',
        'Diskon': '0.00',
        'Retur': '38500.00',
        'Bersih': '115500.00',
        'Pajak': '0.00',
        'PerMetodeBayar': [
          {'Jenis': 'Tunai', 'Nama': 'Tunai', 'Jumlah': '115500.00'},
        ],
        'PerKasir': [
          {'Nama': 'Rina', 'JumlahTransaksi': 2, 'Bersih': '115500.00'},
        ],
      }, 200);
    });
    final r = await klien.AmbilRingkasanHarian(tanggal: '2026-10-02');
    expect(dikirim.single.url.path, '/api/pos/v1/ringkasan-harian');
    expect(dikirim.single.url.query, 'tanggal=2026-10-02');
    expect(r.jumlahVoid, 1);
    expect(r.bersih, '115500.00');
    expect(r.perMetodeBayar.single.nama, 'Tunai');
    expect(r.perKasir.single.jumlahTransaksi, 2);
  });

  test(
    'PGL-16 bagian 2: PermintaanLog.Diminta dibaca (server lama tanpa kunci = false) dan log diunggah ke perangkat/log',
    () async {
      Future<KonfigurasiAplikasi> Ambil(Map<String, Object?> isi) =>
          BuatKlien((_) async => Json(isi, 200)).AmbilKonfigurasiAplikasi();

      expect(
        (await Ambil({
          'PermintaanLog': {'Diminta': true, 'Sejak': '2026-10-12T03:00:00Z'},
        })).logDiminta,
        isTrue,
      );
      expect(
        (await Ambil({
          'PermintaanLog': {'Diminta': false, 'Sejak': null},
        })).logDiminta,
        isFalse,
      );
      expect((await Ambil({'Aplikasi': <String, Object?>{}})).logDiminta, isFalse);

      final dikirim = <http.Request>[];
      final klien = BuatKlien((permintaan) async {
        dikirim.add(permintaan);
        return Json({'Diterima': 2}, 200);
      });
      expect(await klien.UnggahLog(['baris satu', 'baris dua']), 2);
      expect(dikirim.single.url.path, '/api/pos/v1/perangkat/log');
      expect(jsonDecode(dikirim.single.body), {
        'Baris': ['baris satu', 'baris dua'],
      });
    },
  );

  test('K-21: laporan galat dikirim ke perangkat/galat', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({'Diterima': 1}, 200);
    });
    final galat = {'Waktu': '2026-10-02T03:00:00.000Z', 'Tingkat': 'Galat', 'Sumber': 'Flutter', 'Pesan': 'x'};
    expect(await klien.LaporGalat([galat]), 1);
    expect(dikirim.single.url.path, '/api/pos/v1/perangkat/galat');
    expect(jsonDecode(dikirim.single.body), {
      'Galat': [galat],
    });
  });

  test('K-20: kalender, slot, dan buat booking dari kasir', () async {
    final dikirim = <http.Request>[];
    final reservasi = {
      'Uuid': 'R1',
      'Nomor': 'RS/2026/10/0001',
      'MulaiPada': '2026-10-13T03:00:00Z',
      'SelesaiPada': '2026-10-13T04:00:00Z',
      'NamaPelanggan': 'Rina',
      'NoHp': '0812-3456-7890',
      'Pelanggan': null,
      'UuidProduk': 'L1',
      'NamaLayanan': 'Creambath',
      'UuidStaf': 'S1',
      'NamaStaf': 'Maya',
      'Status': 'Dikonfirmasi',
      'LabelStatus': 'Dikonfirmasi',
      'Catatan': null,
    };
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      if (permintaan.url.path.endsWith('/kalender')) {
        return Json({
          'Tanggal': '2026-10-13',
          'Layanan': [
            {'Uuid': 'L1', 'Nama': 'Creambath', 'DurasiMenit': 60, 'Harga': '85000.00'},
          ],
          'Staf': [
            {'Uuid': 'S1', 'Nama': 'Maya', 'JamMulai': '09:00', 'JamSelesai': '12:00'},
          ],
          'Reservasi': [reservasi],
        }, 200);
      }
      if (permintaan.url.path.endsWith('/slot')) {
        return Json({
          'Slot': [
            {
              'Jam': '09:00',
              'Staf': [
                {'Uuid': 'S1', 'Nama': 'Maya'},
              ],
            },
          ],
        }, 200);
      }
      return Json({'Reservasi': reservasi}, 201);
    });

    final kalender = await klien.AmbilKalenderReservasi(tanggal: '2026-10-13');
    expect(dikirim.last.url.query, 'tanggal=2026-10-13');
    expect(kalender.layanan.single.durasiMenit, 60);
    expect(kalender.staf.single.jamSelesai, '12:00');
    expect(kalender.reservasi.single.nomor, 'RS/2026/10/0001');

    final slot = await klien.AmbilSlotReservasi(uuidLayanan: 'L1', tanggal: '2026-10-13', uuidStaf: 'S1');
    expect(dikirim.last.url.queryParameters, {'UuidLayanan': 'L1', 'Tanggal': '2026-10-13', 'UuidStaf': 'S1'});
    expect(slot.single.jam, '09:00');
    expect(slot.single.staf.single.nama, 'Maya');

    final dibuat = await klien.BuatReservasi(
      uuidPengguna: 'U1',
      uuidLayanan: 'L1',
      tanggal: '2026-10-13',
      jam: '10:00',
      uuidStaf: 'S1',
      namaPelanggan: 'Rina',
      noHp: '081234567890',
    );
    expect(dikirim.last.method, 'POST');
    expect(dikirim.last.url.path, '/api/pos/v1/reservasi');
    expect((jsonDecode(dikirim.last.body) as Map<String, Object?>)['Jam'], '10:00');
    expect(dibuat.namaStaf, 'Maya');
  });

  test('K-19: batch produk urut FEFO dengan sisa hari (null = tanpa kedaluwarsa)', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return Json({
        'UuidProduk': 'P1',
        'Pelacakan': 'Batch',
        'SimbolSatuan': 'pcs',
        'HariSegera': 30,
        'JumlahBatch': 2,
        'Batch': [
          {'NomorBatch': 'A-DEKAT', 'TanggalKedaluwarsa': '2026-10-12', 'JumlahSisa': '3.0000', 'SisaHari': -2},
          {'NomorBatch': 'C-TANPA', 'TanggalKedaluwarsa': null, 'JumlahSisa': '5.0000', 'SisaHari': null},
        ],
      }, 200);
    });

    final hasil = await klien.AmbilBatchProduk('P1');
    expect(dikirim.single.method, 'GET');
    expect(dikirim.single.url.path, '/api/pos/v1/produk/P1/batch');
    expect(hasil.pelacakan, 'Batch');
    expect(hasil.jumlahBatch, 2);
    expect(hasil.batch.first.nomorBatch, 'A-DEKAT');
    expect(hasil.batch.first.sisaHari, -2);
    expect(hasil.batch.last.tanggalKedaluwarsa, isNull);
    expect(hasil.batch.last.sisaHari, isNull);
  });

  test('laundry: cari cucian (kosong = siap), ubah status, data awal membawa pengaturan laundry', () async {
    final dikirim = <http.Request>[];
    final tiket = {
      'Uuid': 'P1',
      'Nomor': 'INV/SLO/261013/K01-0001',
      'DibuatPada': '2026-10-13T02:25:00Z',
      'EstimasiSelesaiPada': '2026-10-14T02:25:00Z',
      'SiapPada': null,
      'NamaPelanggan': 'Ratna',
      'NoHp': '0812-3456-7890',
      'JenisLayanan': 'Express',
      'Berat': '3.50',
      'Item': [
        {'Nama': 'Bed cover', 'Jumlah': 1},
      ],
      'Parfum': 'Lavender',
      'Catatan': null,
      'Status': 'Dicuci',
      'LabelStatus': 'Dicuci',
      'LewatEstimasi': false,
      'NotifikasiTerkirim': false,
      'StatusBerikutnya': ['Dikeringkan', 'Disetrika', 'Siap'],
    };
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      return permintaan.method == 'GET'
          ? Json({
              'Tiket': [tiket],
            }, 200)
          : Json({
              'Tiket': {
                ...tiket,
                'Status': 'Siap',
                'StatusBerikutnya': ['Diambil'],
              },
            }, 200);
    });

    final daftar = await klien.CariLaundry();
    expect(dikirim.last.url.path, '/api/pos/v1/laundry');
    expect(dikirim.last.url.query, isEmpty);
    expect(daftar.single.berat, '3.50');
    expect(daftar.single.item.single.nama, 'Bed cover');
    expect(daftar.single.estimasiSelesaiPada, DateTime.utc(2026, 10, 14, 2, 25));

    await klien.CariLaundry(kata: ' ratna ');
    expect(dikirim.last.url.queryParameters, {'kata': 'ratna'});

    final siap = await klien.UbahStatusLaundry('P1', status: 'Siap', uuidPengguna: 'U1');
    expect(dikirim.last.url.path, '/api/pos/v1/laundry/P1/status');
    expect(jsonDecode(dikirim.last.body), {'Status': 'Siap', 'UuidPengguna': 'U1'});
    expect(siap.statusBerikutnya, ['Diambil']);

    final awal = DataAwal.DariJson({
      'Laundry': {
        'Aktif': true,
        'JamReguler': 72,
        'JamExpress': 12,
        'Parfum': ['Lavender'],
        'AwalanLacak': 'https://x.id/s/1a.',
      },
    });
    expect(awal.laundry.aktif, isTrue);
    expect(awal.laundry.jamExpress, 12);
    expect(LaundryPos.DariJson(awal.laundry.KeJson()).awalanLacak, 'https://x.id/s/1a.');
    expect(DataAwal.DariJson(const {}).laundry.aktif, isFalse);
  });

  test('v2.05 QRIS dinamis & kirim struk: bentuk permintaan dan urai respons', () async {
    final dikirim = <http.Request>[];
    final klien = BuatKlien((permintaan) async {
      dikirim.add(permintaan);
      final jalur = permintaan.url.path;
      if (jalur.endsWith('/qris')) {
        return Json({
          'Uuid': 'Q1',
          'NomorPesanan': 'PY1-Q1',
          'IsiQr': '000201010212',
          'HalamanBayar': false,
          'KedaluwarsaPada': '2026-09-26T03:15:00Z',
          'Status': 'Menunggu',
          'Jumlah': '25000.00',
        }, 201);
      }
      if (jalur.endsWith('/qris/Q1')) {
        return Json({'Uuid': 'Q1', 'Status': 'Lunas', 'LunasPada': '2026-09-26T03:02:00Z'}, 200);
      }
      if (jalur.endsWith('/batal')) {
        return Json({
          'Galat': {'Kode': 'SudahLunas', 'Pesan': 'Tagihan sudah dibayar.'},
        }, 409);
      }
      if (jalur.endsWith('/kirim-struk')) {
        return Json({'Uuid': 'M1', 'Status': 'Diantrekan'}, 202);
      }
      return Json({'Uuid': 'M1', 'Status': 'Gagal', 'PesanGalat': 'Nomor tidak terdaftar di WhatsApp.'}, 200);
    });

    final tagihan = await klien.BuatQris(uuid: 'Q1', uuidMetode: 'MQ', jumlah: '25000.00');
    expect(tagihan.isiQr, '000201010212');
    expect(tagihan.kedaluwarsaPada, DateTime.utc(2026, 9, 26, 3, 15));
    expect(jsonDecode(dikirim.last.body), {'Uuid': 'Q1', 'UuidMetode': 'MQ', 'Jumlah': '25000.00'});

    final status = await klien.AmbilStatusQris('Q1');
    expect(status.lunas, isTrue);
    await expectLater(klien.BatalkanQris('Q1'), throwsA(isA<GalatApi>().having((g) => g.kode, 'kode', 'SudahLunas')));

    final pesan = await klien.KirimStruk(uuidPenjualan: 'P1', uuid: 'M1', kanal: 'Whatsapp', tujuan: '0812');
    expect(pesan.status, 'Diantrekan');
    expect(dikirim.last.url.path, '/api/pos/v1/penjualan/P1/kirim-struk');
    expect((await klien.AmbilPesanKeluar('M1')).pesanGalat, 'Nomor tidak terdaftar di WhatsApp.');
  });

  group('BR-02.3 kait penolakan token perangkat', () {
    http.Response DicabutJson() => Json({
      'Galat': {'Kode': 'PerangkatDicabut', 'Pesan': 'Perangkat ini sudah dicabut dari back-office.'},
    }, 403);

    KlienPos BuatKlienBerkait(
      Future<http.Response> Function(http.Request permintaan) penangan,
      List<String> dilaporkan, {
      String? token = 'Tkn',
    }) => KlienPos(
      alamatDasar: Uri.parse('https://kasir.contoh.id/'),
      versiAplikasi: '0.1.0',
      ambilToken: () => token,
      klien: MockClient(penangan),
      saatPerangkatDitolak: (galat) => dilaporkan.add(galat.kode),
    );

    test('permintaan bertoken yang ditolak melaporkan pencabutan, apa pun endpoint-nya', () async {
      // Katalog & promo sengaja menelan GalatApi di pemanggilnya; tanpa kait ini pencabutan tidak pernah terdengar.
      for (final panggil in <Future<Object?> Function(KlienPos)>[
        (k) => k.AmbilKatalog(),
        (k) => k.AmbilDataAwal(),
        (k) => k.AmbilMeja(),
        (k) => k.AmbilLogoStruk(),
      ]) {
        final dilaporkan = <String>[];
        final klien = BuatKlienBerkait((_) async => DicabutJson(), dilaporkan);

        await expectLater(panggil(klien), throwsA(isA<GalatApi>()));

        expect(dilaporkan, ['PerangkatDicabut'], reason: 'Setiap permintaan bertoken melaporkan pencabutan.');
      }
    });

    test('token tidak berlaku (401) juga dilaporkan', () async {
      final dilaporkan = <String>[];
      final klien = BuatKlienBerkait(
        (_) async => Json({
          'Galat': {'Kode': 'TokenPerangkatTidakValid', 'Pesan': 'Token tidak berlaku.'},
        }, 401),
        dilaporkan,
      );

      await expectLater(klien.AmbilDataAwal(), throwsA(isA<GalatApi>()));

      expect(dilaporkan, ['TokenPerangkatTidakValid']);
    });

    test('aktivasi kode perangkat yang sudah dicabut tidak melaporkan apa pun', () async {
      // Aktivasi tidak memakai token: galatnya milik kode yang diketik, bukan sesi perangkat ini. Kalau ikut
      // dilaporkan, pesan galat di layar aktivasi tertimpa pesan pencabutan dan kodenya hilang dari isian.
      final dilaporkan = <String>[];
      final klien = BuatKlienBerkait((_) async => DicabutJson(), dilaporkan, token: null);

      await expectLater(klien.AktifkanPerangkat(kode: 'A7K9M2QT', platform: 'android'), throwsA(isA<GalatApi>()));

      expect(dilaporkan, isEmpty);
    });

    test('galat lain tidak melaporkan pencabutan', () async {
      final dilaporkan = <String>[];
      final klien = BuatKlienBerkait(
        (_) async => Json({
          'Galat': {'Kode': 'DataTidakValid', 'Pesan': 'Kursor tidak dikenal.'},
        }, 422),
        dilaporkan,
      );

      await expectLater(klien.AmbilDataAwal(), throwsA(isA<GalatApi>()));

      expect(dilaporkan, isEmpty);
    });
  });
}
