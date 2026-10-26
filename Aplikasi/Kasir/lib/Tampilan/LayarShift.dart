import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/BasisData/BasisDataKasir.dart';
import '../Domain/Sesi/StafLokal.dart';
import '../Domain/Shift/LayananTutupShift.dart';
import 'Komponen/FormatAngka.dart';
import 'Komponen/FormatWaktu.dart';
import 'RuangKerja/BagianKerja.dart';
import 'RuangKerja/IsiAreaKerja.dart';

/// Area kerja "Shift" (F-06, F-11, D-40): jalannya shift sekarang — penjualan (angka utama), rincian per metode
/// bayar, produk terlaris, dan penjualan per jam — dengan panel konteks berisi data shift dan daftar periksa sebelum
/// tutup shift. Semua dari data perangkat (berlaku offline). Kasir yang bertugas bisa berganti (ketuk nama kasir di
/// bilah atas) tanpa menutup shift. Laporan X & formulir tutup shift dibuka sebagai panel oleh bingkai ruang kerja.
class LayarShift extends ConsumerWidget {
  const LayarShift({
    super.key,
    required this.shift,
    required this.kasir,
    required this.saatTutupShift,
    required this.saatLaporanX,
  });

  final BarisShift shift;
  final StafLokal kasir;
  final VoidCallback saatTutupShift;
  final VoidCallback saatLaporanX;

  /// Jumlah batang minimum grafik per jam.
  static const int jamGrafikMinimum = 8;

  /// "3 j 12 m" / "45 m" untuk lama shift berjalan.
  static String FormatDurasi(Duration durasi) {
    final menit = durasi.inMinutes < 0 ? 0 : durasi.inMinutes;
    return menit >= 60 ? '${menit ~/ 60} j ${menit % 60} m' : '$menit m';
  }

  /// Jam (0–23) yang digambar di grafik per jam: dari jam shift dibuka sampai jam [sekarang] (min. [jamGrafikMinimum],
  /// maks. 24 batang),
  /// diperluas ke jam penjualan terakhir bila jam perangkat mundur.
  static List<int> SusunJamGrafik(DateTime dibuka, DateTime sekarang, Iterable<int> jamPenjualan) {
    final awal = DateTime(dibuka.toLocal().year, dibuka.toLocal().month, dibuka.toLocal().day, dibuka.toLocal().hour);
    // Minimal [jamGrafikMinimum] batang supaya shift yang baru berjalan tidak tergambar sebagai satu blok selebar
    // layar; jam yang belum lewat tetap tergambar tipis (nol).
    var jumlah = sekarang.toLocal().difference(awal).inHours + 1;
    jumlah = jumlah.clamp(jamGrafikMinimum, 24);
    final jam = [for (var i = 0; i < jumlah; i++) (awal.hour + i) % 24];
    for (final j in jamPenjualan) {
      if (!jam.contains(j) && jam.length < 24) {
        jam.add(j);
      }
    }
    return jam;
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final laporan = ref.watch(penyediaLaporanShift(shift.Uuid));
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final tertahan = ref.watch(penyediaPesananTertahan).value?.length ?? 0;
    final sekarang = ref.watch(penyediaJam)();

    return IsiAreaKerja(
      judul: 'Shift',
      aksi: [
        OutlinedButton.icon(
          onPressed: saatLaporanX,
          icon: const Icon(Icons.summarize_outlined),
          label: const Text('Laporan sementara (X)'),
        ),
        FilledButton.icon(
          onPressed: saatTutupShift,
          icon: const Icon(Icons.lock_clock_outlined),
          label: const Text('Tutup shift'),
        ),
      ],
      anak: laporan.when(
        loading: () => const [LinearProgressIndicator()],
        error: (galat, _) => [Text('Ringkasan shift tidak bisa dibaca: $galat', style: TextStyle(color: warna.bahaya))],
        data: (l) => _SusunIsi(context, l, sekarang),
      ),
      panelSamping: [
        const JudulPanelSamping('Data shift'),
        BarisInfo(label: 'Dibuka oleh', nilai: Text(shift.NamaKasir)),
        BarisInfo(label: 'Dibuka', nilai: Text(FormatWaktu.FormatTanggalJam(shift.DibukaPada))),
        BarisInfo(label: 'Lama berjalan', nilai: Text(FormatDurasi(sekarang.difference(shift.DibukaPada)))),
        BarisInfo(label: 'Kas awal', nilai: TeksUang(Uang.Dari(shift.KasAwal))),
        BarisInfo(label: 'Jenis shift', nilai: Text(shift.Bersama ? 'Bersama' : 'Per kasir')),
        BarisInfo(label: 'Kasir bertugas', nilai: Text(kasir.nama)),
        const SizedBox(height: TokenJarak.jarak8),
        Divider(color: warna.garis, height: TokenJarak.jarak16),
        const JudulPanelSamping('Sebelum tutup shift'),
        ButirPeriksa(
          nada: tertahan == 0 ? NadaStatus.Sukses : NadaStatus.Peringatan,
          judul: tertahan == 0 ? 'Tidak ada pesanan tertahan' : '$tertahan pesanan masih tertahan',
          keterangan: tertahan == 0 ? null : 'Selesaikan atau batalkan dulu di layar Jual.',
        ),
        ButirPeriksa(
          nada: tertunda == 0 ? NadaStatus.Sukses : NadaStatus.Peringatan,
          judul: tertunda == 0 ? 'Semua data sudah terkirim' : '$tertunda data belum terkirim',
          keterangan: tertunda == 0 ? null : 'Shift tetap boleh ditutup. Data dikirim otomatis saat online.',
        ),
        const ButirPeriksa(
          nada: NadaStatus.Netral,
          judul: 'Hitung uang di laci',
          keterangan: 'Siapkan jumlah per pecahan, lalu tekan Tutup shift.',
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Shift tetap terbuka saat kasir berganti atau layar dikunci. Tutup shift setelah menghitung uang di laci.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      ],
    );
  }

  List<Widget> _SusunIsi(BuildContext context, LaporanShift l, DateTime sekarang) {
    final rataRata = l.jumlahTransaksi == 0 ? '' : ' | rata-rata ${l.rataRataTransaksi.FormatRupiah()}';
    final metode = BagianKerja(
      judul: 'Per metode bayar',
      ikon: Icons.account_balance_wallet_outlined,
      anak: l.perMetode.isEmpty
          ? const KeadaanKosong(
              ringkas: true,
              ilustrasi: IlustrasiKosong.Kas,
              ikon: Icons.account_balance_wallet_outlined,
              judul: 'Belum ada pembayaran',
              keterangan: 'Tunai, QRIS, dan kartu tampil di sini setelah transaksi pertama.',
            )
          : Column(
              children: [
                for (final m in l.perMetode)
                  BatangProporsi(
                    label: m.nama,
                    nilai: TeksUang(m.jumlah),
                    rasio: _HitungRasio(m.jumlah, l.perMetode.map((x) => x.jumlah)),
                  ),
              ],
            ),
    );
    final terlaris = BagianKerja(
      judul: 'Produk terlaris',
      ikon: Icons.local_fire_department_outlined,
      anak: l.terlaris.isEmpty
          ? const KeadaanKosong(
              ringkas: true,
              ilustrasi: IlustrasiKosong.Produk,
              ikon: Icons.local_fire_department_outlined,
              judul: 'Belum ada produk terjual',
              keterangan: 'Lima produk paling laku di shift ini tampil di sini.',
            )
          : Column(
              children: [
                for (final p in l.terlaris)
                  BatangProporsi(
                    label: p.nama,
                    keterangan: '${FormatAngka.FormatJumlah(p.jumlah)}×',
                    nilai: TeksUang(p.total),
                    rasio: _HitungRasioJumlah(p.jumlah, l.terlaris.map((x) => x.jumlah)),
                  ),
              ],
            ),
    );
    final jamGrafik = SusunJamGrafik(shift.DibukaPada, sekarang, l.perJam.keys);
    final tertinggi = l.perJam.values.fold(Uang.Nol(), (t, n) => n.Bandingkan(t) > 0 ? n : t);

    return [
      SorotanAngka(
        label: 'Penjualan shift ini',
        ikon: Icons.trending_up,
        nilai: TeksUang(l.totalAkhir, rataKanan: false),
        keterangan: '${l.jumlahTransaksi} transaksi$rataRata',
      ),
      const SizedBox(height: TokenJarak.jarak12),
      DeretKartuAngka(
        lebarMinimum: 140,
        kartu: [
          KartuAngka(label: 'Penjualan bersih', ikon: Icons.receipt_long_outlined, nilai: TeksUang(l.penjualanBersih)),
          KartuAngka(label: 'Diskon', ikon: Icons.sell_outlined, nilai: TeksUang(l.totalDiskon)),
          KartuAngka(
            label: 'Transaksi void',
            ikon: Icons.block,
            nada: l.jumlahVoid == 0 ? NadaStatus.Netral : NadaStatus.Peringatan,
            nilai: Text('${l.jumlahVoid}'),
            keterangan: l.nominalVoid.FormatRupiah(),
          ),
          KartuAngka(
            label: 'Dokumen retur',
            ikon: Icons.assignment_return_outlined,
            nada: l.jumlahRetur == 0 ? NadaStatus.Netral : NadaStatus.Peringatan,
            nilai: Text('${l.jumlahRetur}'),
            keterangan: l.nominalRetur.FormatRupiah(),
          ),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak12),
      LayoutBuilder(
        builder: (context, batas) => batas.maxWidth >= 600
            ? IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(child: metode),
                    const SizedBox(width: TokenJarak.jarak12),
                    Expanded(child: terlaris),
                  ],
                ),
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  metode,
                  const SizedBox(height: TokenJarak.jarak12),
                  terlaris,
                ],
              ),
      ),
      const SizedBox(height: TokenJarak.jarak12),
      BagianKerja(
        judul: 'Penjualan per jam',
        ikon: Icons.bar_chart,
        anak: GrafikBatang(
          batang: [
            for (final jam in jamGrafik)
              BatangGrafik(
                label: jam.toString().padLeft(2, '0'),
                rasio: _HitungRasio(l.perJam[jam] ?? Uang.Nol(), [tertinggi]),
                keterangan: 'Jam ${jam.toString().padLeft(2, '0')}.00: ${(l.perJam[jam] ?? Uang.Nol()).FormatRupiah()}',
              ),
          ],
        ),
      ),
    ];
  }

  /// Rasio panjang batang (hanya untuk gambar, bukan nilai uang): [bagian] ÷ nilai terbesar di [semua].
  static num _HitungRasio(Uang bagian, Iterable<Uang> semua) {
    final terbesar = semua.fold(Uang.Nol(), (t, n) => n.Bandingkan(t) > 0 ? n : t);
    return terbesar.BernilaiNol() ? 0 : (bagian.KeDesimal() / terbesar.KeDesimal()).toDouble();
  }

  static num _HitungRasioJumlah(Kuantitas bagian, Iterable<Kuantitas> semua) {
    final terbesar = semua.fold(Kuantitas.Nol(), (t, n) => n.Bandingkan(t) > 0 ? n : t);
    return terbesar.Bandingkan(Kuantitas.Nol()) == 0 ? 0 : (bagian.KeDesimal() / terbesar.KeDesimal()).toDouble();
  }
}
