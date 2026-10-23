import 'package:flutter/material.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Penjualan/LayananPenjualan.dart';
import '../Komponen/FormatAngka.dart';

/// Isi keranjang layar Jual (PRD §17.2.3, §17.2.7): daftar baris, ringkasan total, dan aksi Diskon | Tahan | BAYAR.
/// Pintasan: F8 bayar, F9 uang pas. Hierarki: TOTAL paling besar, lalu tombol BAYAR, lalu isi keranjang. Hanya tampilan; aksinya lewat callback.
class PanelKeranjang extends StatelessWidget {
  const PanelKeranjang({
    super.key,
    required this.keranjang,
    required this.hitungan,
    required this.saatUbahBaris,
    required this.saatTambah,
    required this.saatKurang,
    required this.saatDiskonPesanan,
    required this.saatTahan,
    required this.saatKosongkan,
    this.saatBayar,
    this.tampilKepala = true,
    this.judul,
    this.statusBaris = const {},
    this.labelTahan = 'Tahan',
    this.labelKosongkan = 'Batalkan transaksi',
    this.saatPelanggan,
    this.saatLaundry,
    this.saatRacikan,
    this.saatKanal,
    this.saatOngkir,
    this.jenisPesanan = const [],
    this.saatJenisPesanan,
    this.saatNamaPemesan,
    this.kursus = const [],
    this.kursusDipilih,
    this.saatKursus,
    this.kursusDitahan = const [],
    this.saatKirimKursus,
    this.uuidSorot,
    this.lencanaProduk = const {},
  });

  final Keranjang keranjang;
  final HitunganKeranjang? hitungan;
  final ValueChanged<String> saatUbahBaris;
  final ValueChanged<String> saatTambah;
  final ValueChanged<String> saatKurang;
  final VoidCallback saatDiskonPesanan;
  final VoidCallback saatTahan;
  final VoidCallback saatKosongkan;

  /// Null = mode Pelayan (v2.00): tanpa Diskon & Bayar; tombol [labelTahan] menjadi aksi utama.
  final VoidCallback? saatBayar;

  /// Kepala "Keranjang" (disembunyikan saat tampil di dalam lembar yang sudah berjudul).
  final bool tampilKepala;

  /// Judul kepala pengganti "Keranjang" (mode meja: nama meja/pesanan).
  final String? judul;

  /// Mode meja: status baris yang sudah tersimpan di pesanan (Uuid baris → "Dimasak", "Belum dikirim", …). Baris tanpa
  /// status = item baru yang belum disimpan.
  final Map<String, String> statusBaris;

  /// Tombol kedua: "Tahan" (retail) atau "Kirim ke dapur" (mode meja).
  final String labelTahan;
  final String labelKosongkan;

  /// F-16a: buka panel pelanggan (F2). Null = tombol pelanggan tidak ditampilkan.
  final VoidCallback? saatPelanggan;

  /// Laundry (§9.9): buka isian tiket laundry. Null = tidak ditampilkan (laundry belum aktif).
  final VoidCallback? saatLaundry;

  /// Apotek bagian 4: buka penyusun racikan. Null = tidak ditampilkan (bukan apotek).
  final VoidCallback? saatRacikan;

  /// X8: pilih kanal (bawa pulang, GoFood, …). Null = tidak ditampilkan (tidak ada kanal platform/harga berkanal, mode
  /// meja, atau mode Pelayan).
  final VoidCallback? saatKanal;

  /// v3.51: jenis pesanan outlet (Makan di tempat/Bawa pulang/Antar) sebagai tombol segmen di atas keranjang; kurang
  /// dari dua = tidak ditampilkan.
  final List<KanalPenjualan> jenisPesanan;
  final ValueChanged<KanalPenjualan>? saatJenisPesanan;

  /// v3.52: isi nama pemesan (penjualan bayar-dulu FnB). Null = tidak ditampilkan.
  final VoidCallback? saatNamaPemesan;

  /// K-13 (mode meja): pilihan kursus untuk item baru (Pembuka/Utama/Penutup) sebagai tombol segmen; kosong = tidak
  /// ditampilkan. [kursusDipilih] null = tanpa kursus (ketuk segmen terpilih lagi untuk melepas).
  final List<String> kursus;
  final String? kursusDipilih;
  final ValueChanged<String?>? saatKursus;

  /// K-13: kursus yang ditahan di pesanan (urut saji) → tombol "Kirim Utama | 2 item".
  final List<({String kursus, int jumlah})> kursusDitahan;
  final ValueChanged<String>? saatKirimKursus;

  /// K-15: Uuid baris yang baru bertambah lewat pindaian (disorot sebentar).
  final String? uuidSorot;

  /// Apotek bagian 2: lencana golongan obat per Uuid produk (produk bukan obat tidak ada di peta). Artinya juga tampil
  /// sebagai rincian berteks ("Obat keras, wajib resep").
  final Map<String, LencanaTeks> lencanaProduk;

  /// v3.29: isi ongkir penjualan kanal Antar. Null = tidak ditampilkan (kanal lain, pesanan online, mode Pelayan).
  final VoidCallback? saatOngkir;

  static List<String> AmbilRincian(ItemKeranjang b) => [
    if (b.namaSatuan != null && b.namaSatuan!.isNotEmpty) '@ ${b.hargaSatuan.FormatRupiah()}/${b.namaSatuan}',
    for (final p in b.pilihan) p.harga.BernilaiNol() ? p.nama : '${p.nama} +${p.harga.FormatRupiah()}',
    if (b.diskon != null)
      b.diskon!.persen != null ? b.diskon!.AmbilLabel() : 'Diskon ${b.diskon!.jumlah!.FormatRupiah()}',
    if (b.nomorSeri.isNotEmpty) 'No. seri: ${b.nomorSeri.join(', ')}',
    if (b.racikan case final r?) 'Racikan ${r.nama} | ${r.Ringkasan}',
  ];

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final hasil = hitungan?.hasil;
    final kosong = keranjang.CekKosong;
    final gayaTombolKecil = OutlinedButton.styleFrom(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8),
    );
    final kanalKeranjang = LayananPenjualan.AmbilKanal(keranjang);
    final adaJenisPesanan = jenisPesanan.length >= 2 && saatJenisPesanan != null;
    // Ringkasan satu baris supaya lima baris opsi tidak mendorong daftar item keluar layar 800dp (mockup 03).
    final bagianRingkasan = <String>[
      if (adaJenisPesanan) LayananPenjualan.AmbilLabelKanal(kanalKeranjang),
      if (saatKanal != null && !(adaJenisPesanan && LayananPenjualan.kanalPlatform.contains(kanalKeranjang)))
        'Kanal: ${LayananPenjualan.AmbilLabelKanal(kanalKeranjang)}',
      if (saatPelanggan != null) keranjang.pelanggan?.nama ?? 'Pelanggan umum',
      if (saatNamaPemesan != null && keranjang.namaPemesan != null) 'Pemesan: ${keranjang.namaPemesan}',
      if (saatOngkir != null && !keranjang.biayaKirim.BernilaiNol()) 'Ongkir ${keranjang.biayaKirim.FormatRupiah()}',
      if (saatLaundry != null && keranjang.laundry != null) 'Laundry ${keranjang.laundry!.jenisLayanan}',
      if (saatRacikan != null) 'Racikan obat',
      if (kursus.isNotEmpty && kursusDipilih != null) 'Kursus: $kursusDipilih',
    ];
    final adaOpsi =
        adaJenisPesanan ||
        (kursus.isNotEmpty && saatKursus != null) ||
        saatNamaPemesan != null ||
        saatKanal != null ||
        saatOngkir != null ||
        saatPelanggan != null ||
        saatRacikan != null ||
        saatLaundry != null;

    return ColoredBox(
      color: warna.permukaan,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (tampilKepala)
            Container(
              padding: const EdgeInsets.fromLTRB(
                TokenJarak.jarak16,
                TokenJarak.jarak8,
                TokenJarak.jarak8,
                TokenJarak.jarak8,
              ),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Semantics(
                      header: true,
                      child: Text(
                        kosong
                            ? judul ?? 'Keranjang'
                            : '${judul ?? 'Keranjang'} | ${FormatAngka.FormatJumlah(keranjang.HitungJumlahItem())} item',
                        style: teks.titleMedium,
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: labelKosongkan,
                    onPressed: kosong && keranjang.pesananMeja == null && keranjang.tukar == null
                        ? null
                        : saatKosongkan,
                    icon: const Icon(Icons.remove_shopping_cart_outlined),
                  ),
                ],
              ),
            ),
          if (adaOpsi)
            _PanelOpsi(
              ringkasan: bagianRingkasan.isEmpty ? 'Atur pesanan' : bagianRingkasan.join(' | '),
              ikon: adaJenisPesanan ? Icons.restaurant_outlined : Icons.tune,
              children: [
                if (jenisPesanan.length >= 2 && saatJenisPesanan != null)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak8),
                    decoration: BoxDecoration(
                      border: Border(
                        bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                      ),
                    ),
                    child: Semantics(
                      label: 'Jenis pesanan',
                      container: true,
                      child: SegmentedButton<KanalPenjualan>(
                        key: const ValueKey('JenisPesanan'),
                        showSelectedIcon: false,
                        emptySelectionAllowed: true,
                        segments: [
                          for (final j in jenisPesanan)
                            ButtonSegment(
                              value: j,
                              label: Text(
                                LayananPenjualan.AmbilLabelKanal(j),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              icon: Icon(switch (j) {
                                KanalPenjualan.MakanDiTempat => Icons.restaurant_outlined,
                                KanalPenjualan.Antar => Icons.delivery_dining_outlined,
                                _ => Icons.shopping_bag_outlined,
                              }),
                            ),
                        ],
                        selected: {
                          if (jenisPesanan.contains(LayananPenjualan.AmbilKanal(keranjang)))
                            LayananPenjualan.AmbilKanal(keranjang),
                        },
                        onSelectionChanged: (pilih) {
                          if (pilih.isNotEmpty) {
                            saatJenisPesanan!(pilih.first);
                          }
                        },
                      ),
                    ),
                  ),
                if (kursus.isNotEmpty && saatKursus != null)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak8),
                    decoration: BoxDecoration(
                      border: Border(
                        bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Semantics(
                          label: 'Kursus item baru',
                          container: true,
                          child: SegmentedButton<String>(
                            key: const ValueKey('KursusPesanan'),
                            showSelectedIcon: false,
                            emptySelectionAllowed: true,
                            segments: [
                              for (final k in kursus)
                                ButtonSegment(
                                  value: k,
                                  label: Text(k, maxLines: 1, overflow: TextOverflow.ellipsis),
                                ),
                            ],
                            selected: {?kursusDipilih},
                            onSelectionChanged: (pilih) => saatKursus!(pilih.isEmpty ? null : pilih.first),
                          ),
                        ),
                      ],
                    ),
                  ),
                if (saatNamaPemesan != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      key: const ValueKey('IsiNamaPemesan'),
                      onTap: saatNamaPemesan,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.campaign_outlined,
                              size: TokenJarak.ikonSedang,
                              color: keranjang.namaPemesan == null ? warna.teksSekunder : warna.brand,
                            ),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                keranjang.namaPemesan == null
                                    ? 'Nama pemesan | ketuk untuk mengisi'
                                    : 'Pemesan: ${keranjang.namaPemesan} | ketuk untuk mengubah',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (saatKanal != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      key: const ValueKey('PilihKanal'),
                      onTap: saatKanal,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              LayananPenjualan.kanalPlatform.contains(LayananPenjualan.AmbilKanal(keranjang))
                                  ? Icons.delivery_dining
                                  : Icons.storefront_outlined,
                              size: TokenJarak.ikonSedang,
                              color: keranjang.kanal == null ? warna.teksSekunder : warna.brand,
                            ),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                'Kanal: ${LayananPenjualan.AmbilLabelKanal(LayananPenjualan.AmbilKanal(keranjang))} | ketuk untuk mengganti',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (saatOngkir != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      key: const ValueKey('IsiOngkir'),
                      onTap: saatOngkir,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.local_shipping_outlined,
                              size: TokenJarak.ikonSedang,
                              color: keranjang.biayaKirim.BernilaiNol() ? warna.teksSekunder : warna.brand,
                            ),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                keranjang.biayaKirim.BernilaiNol()
                                    ? 'Tanpa ongkir | ketuk untuk mengisi'
                                    : 'Ongkir ${keranjang.biayaKirim.FormatRupiah()} | ketuk untuk mengubah',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (saatPelanggan != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      key: const ValueKey('PilihPelanggan'),
                      onTap: saatPelanggan,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              keranjang.pelanggan == null ? Icons.person_outline : Icons.person,
                              size: TokenJarak.ikonSedang,
                              color: keranjang.pelanggan == null ? warna.teksSekunder : warna.brand,
                            ),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                keranjang.pelanggan == null
                                    ? 'Pelanggan umum | ketuk untuk memilih (F2)'
                                    : [
                                        keranjang.pelanggan!.nama,
                                        keranjang.pelanggan!.noHpSamar,
                                        if (keranjang.pelanggan!.namaTier != null) keranjang.pelanggan!.namaTier!,
                                      ].join(' | '),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (saatRacikan != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      onTap: saatRacikan,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(Icons.medication_outlined, size: TokenJarak.ikonSedang, color: warna.teksSekunder),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                'Buat racikan obat | ketuk untuk menyusun',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (saatLaundry != null)
                  Material(
                    color: warna.permukaan,
                    child: InkWell(
                      onTap: saatLaundry,
                      child: Container(
                        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                        padding: const EdgeInsets.symmetric(
                          horizontal: TokenJarak.jarak16,
                          vertical: TokenJarak.jarak8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.local_laundry_service_outlined,
                              size: TokenJarak.ikonSedang,
                              color: keranjang.laundry == null ? warna.teksSekunder : warna.brand,
                            ),
                            const SizedBox(width: TokenJarak.jarak8),
                            Expanded(
                              child: Text(
                                keranjang.laundry == null
                                    ? 'Tanpa tiket laundry | ketuk untuk mengisi'
                                    : 'Laundry ${keranjang.laundry!.jenisLayanan} | ${keranjang.laundry!.RingkasIsi()}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: teks.bodyMedium,
                              ),
                            ),
                            Icon(Icons.chevron_right, color: warna.teksSekunder),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          for (final d in kursusDitahan)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                TokenJarak.jarak16,
                TokenJarak.jarak8,
                TokenJarak.jarak16,
                TokenJarak.jarak8,
              ),
              child: SizedBox(
                height: TokenJarak.targetSentuh,
                child: OutlinedButton.icon(
                  key: ValueKey('KirimKursus-${d.kursus}'),
                  onPressed: saatKirimKursus == null ? null : () => saatKirimKursus!(d.kursus),
                  icon: const Icon(Icons.soup_kitchen_outlined),
                  label: Text('Kirim ${d.kursus} | ${d.jumlah} item'),
                ),
              ),
            ),
          Expanded(
            child: kosong
                ? Center(
                    child: SingleChildScrollView(
                      child: KeadaanKosong(
                        ikon: Icons.shopping_basket_outlined,
                        ilustrasi: keranjang.pesananMeja == null ? IlustrasiKosong.Keranjang : IlustrasiKosong.Dapur,
                        judul: keranjang.pesananMeja == null ? 'Keranjang masih kosong' : 'Pesanan masih kosong',
                        keterangan: keranjang.pesananMeja == null
                            ? 'Ketuk produk atau pindai barcode untuk mulai.'
                            : 'Ketuk produk untuk menambah, lalu Kirim ke dapur.',
                      ),
                    ),
                  )
                : ListView(
                    children: [
                      for (var i = 0; i < keranjang.baris.length; i++)
                        BarisKeranjang(
                          key: ValueKey(keranjang.baris[i].uuid),
                          disorot: keranjang.baris[i].uuid == uuidSorot,
                          lencana: lencanaProduk[keranjang.baris[i].uuidProduk],
                          nama: keranjang.baris[i].nama,
                          jumlah: FormatAngka.FormatJumlah(keranjang.baris[i].jumlah),
                          total: hasil == null
                              ? keranjang.baris[i].hargaSatuan
                              : hasil.baris[i].bruto.Kurangi(hasil.baris[i].diskon),
                          rincian: [
                            if (statusBaris[keranjang.baris[i].uuid] case final status?) 'Status: $status',
                            if (keranjang.pesananMeja != null && !statusBaris.containsKey(keranjang.baris[i].uuid))
                              'Item baru',
                            ?lencanaProduk[keranjang.baris[i].uuidProduk]?.label,
                            ...AmbilRincian(keranjang.baris[i]),
                            for (final p in hitungan?.promoTerpakai ?? const <PromoTerpakai>[])
                              if (p.diskonBaris[i] case final diskon?)
                                'Promo ${hitungan!.AmbilNamaPromo(p)} −${diskon.FormatRupiah()}',
                          ],
                          catatan: keranjang.baris[i].catatan,
                          saatDiketuk: () => saatUbahBaris(keranjang.baris[i].uuid),
                          saatTambah: () => saatTambah(keranjang.baris[i].uuid),
                          saatKurang: () => saatKurang(keranjang.baris[i].uuid),
                        ),
                      for (final peringatan in hitungan?.peringatan ?? const <String>[])
                        Padding(
                          padding: const EdgeInsets.fromLTRB(
                            TokenJarak.jarak16,
                            TokenJarak.jarak8,
                            TokenJarak.jarak16,
                            0,
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Icon(Icons.warning_amber_outlined, size: TokenJarak.ikonKecil, color: warna.peringatan),
                              const SizedBox(width: TokenJarak.jarak8),
                              Expanded(child: Text(peringatan, style: teks.bodySmall)),
                            ],
                          ),
                        ),
                    ],
                  ),
          ),
          Container(
            padding: const EdgeInsets.fromLTRB(
              TokenJarak.jarak16,
              TokenJarak.jarak8,
              TokenJarak.jarak16,
              TokenJarak.jarak16,
            ),
            decoration: BoxDecoration(
              border: Border(
                top: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (hasil != null) ...RingkasanTotal.BangunBaris(context, hitungan!, keranjang),
                const SizedBox(height: TokenJarak.jarak8),
                if (saatBayar == null)
                  SizedBox(
                    height: 56,
                    child: FilledButton(
                      onPressed: kosong ? null : saatTahan,
                      child: Text(labelTahan, style: teks.titleMedium?.copyWith(color: warna.permukaan)),
                    ),
                  )
                else
                  Row(
                    children: [
                      Expanded(
                        child: SizedBox(
                          height: 56,
                          child: OutlinedButton(
                            style: gayaTombolKecil,
                            onPressed: kosong ? null : saatDiskonPesanan,
                            child: const Text('Diskon', maxLines: 1, overflow: TextOverflow.ellipsis),
                          ),
                        ),
                      ),
                      const SizedBox(width: TokenJarak.jarak8),
                      Expanded(
                        child: SizedBox(
                          height: 56,
                          child: OutlinedButton(
                            style: gayaTombolKecil,
                            onPressed: kosong ? null : saatTahan,
                            child: Text(labelTahan, maxLines: 1, overflow: TextOverflow.ellipsis),
                          ),
                        ),
                      ),
                      const SizedBox(width: TokenJarak.jarak8),
                      Expanded(
                        flex: 2,
                        child: SizedBox(
                          height: 56,
                          child: Tooltip(
                            message: 'Bayar (F8) | uang pas (F9)',
                            child: FilledButton(
                              onPressed: kosong ? null : saatBayar,
                              child: Text('Bayar', style: teks.titleMedium?.copyWith(color: warna.permukaan)),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Baris ringkasan total (subtotal, diskon, biaya layanan, pajak per jenis, pembulatan, TOTAL).
abstract final class RingkasanTotal {
  static List<Widget> BangunBaris(
    BuildContext context,
    HitunganKeranjang hitungan,
    Keranjang keranjang, {
    bool tampilPembulatan = false,
  }) {
    final teks = Theme.of(context).textTheme;
    final hasil = hitungan.hasil;
    // Diskon pesanan manual = total diskon pesanan − tukar poin (F-16b) − promo pesanan (F-16c).
    final diskonManualPesanan = hasil.diskonPesanan
        .Kurangi(hasil.diskonPoin)
        .Kurangi(hitungan.HitungDiskonPromoPesanan());
    Widget Baris(String label, Uang nilai, {TextStyle? gaya}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(child: Text(label, style: gaya ?? teks.bodyMedium)),
          TeksUang(nilai, gaya: gaya ?? teks.bodyMedium),
        ],
      ),
    );

    return [
      Baris('Subtotal', hasil.subtotal),
      if (!diskonManualPesanan.BernilaiNol())
        Baris(
          keranjang.diskonPesanan?.persen != null
              ? '${keranjang.diskonPesanan!.AmbilLabel()} pesanan'
              : 'Diskon pesanan',
          Uang.Nol().Kurangi(diskonManualPesanan),
        ),
      for (final p in hitungan.promoTerpakai)
        if (!p.diskonPesanan.BernilaiNol())
          Baris('Promo ${hitungan.AmbilNamaPromo(p)}', Uang.Nol().Kurangi(p.diskonPesanan)),
      if (keranjang.tukarPoin != null)
        Baris('Tukar ${keranjang.tukarPoin!.poin} poin', Uang.Nol().Kurangi(hasil.diskonPoin)),
      if (hitungan.AmbilLabelPoinBerlipat() case final String label)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4 / 2),
          child: Row(
            children: [
              Icon(Icons.stars_outlined, size: 18, color: TokenWarna.AmbilDari(context).brand),
              const SizedBox(width: TokenJarak.jarak4),
              Expanded(child: Text(label, style: Theme.of(context).textTheme.bodyMedium)),
            ],
          ),
        ),
      if (!hasil.biayaLayanan.BernilaiNol()) Baris('Biaya layanan', hasil.biayaLayanan),
      // F-17 bagian 3: ongkir & diskonnya dua baris terpisah supaya gratis ongkir terbaca sebagai potongan, bukan
      // sebagai ongkir yang tidak pernah ada.
      if (!hasil.biayaKirim.BernilaiNol()) Baris('Ongkir', hasil.biayaKirim),
      if (!hasil.diskonKirim.BernilaiNol())
        Baris(
          hasil.diskonKirim.Bandingkan(hasil.biayaKirim) == 0 ? 'Gratis ongkir' : 'Diskon ongkir',
          Uang.Nol().Kurangi(hasil.diskonKirim),
        ),
      for (final p in hitungan.pajakDokumen)
        if (hasil.pajak[p.kode] != null && !hasil.pajak[p.kode]!.jumlah.BernilaiNol())
          Baris(
            '${hitungan.labelPajak[p.kode] ?? p.kode} ${FormatAngka.FormatPersen(hitungan.tarifDipakai[p.kode]!.tarif)}',
            hasil.pajak[p.kode]!.jumlah,
          ),
      if (tampilPembulatan && !hasil.pembulatan.BernilaiNol()) Baris('Pembulatan tunai', hasil.pembulatan),
      const SizedBox(height: TokenJarak.jarak4),
      Semantics(
        label: 'Total ${hasil.totalAkhir.FormatRupiah()}',
        excludeSemantics: true,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Expanded(child: Text('TOTAL', style: teks.titleMedium)),
            Flexible(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerRight,
                child: TeksUang(hasil.totalAkhir, gaya: teks.displaySmall),
              ),
            ),
          ],
        ),
      ),
    ];
  }
}

/// Opsi pesanan (kanal, pelanggan, pemesan, ongkir, laundry, racikan) diringkas satu baris; rinciannya dibuka lewat
/// "Ubah". Lima baris opsi 48 dp mendorong item keempat ke luar layar 800 dp (mockup desain 03).
class _PanelOpsi extends StatefulWidget {
  const _PanelOpsi({required this.ringkasan, required this.ikon, required this.children});

  final String ringkasan;
  final IconData ikon;
  final List<Widget> children;

  @override
  State<_PanelOpsi> createState() => _PanelOpsiState();
}

class _PanelOpsiState extends State<_PanelOpsi> {
  bool _terbuka = false;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          button: true,
          expanded: _terbuka,
          label: _terbuka ? 'Tutup pengaturan pesanan' : 'Atur pesanan: ${widget.ringkasan}',
          excludeSemantics: true,
          child: Material(
            color: warna.brandTint,
            child: InkWell(
              key: const ValueKey('UbahOpsiKeranjang'),
              onTap: () => setState(() => _terbuka = !_terbuka),
              child: Container(
                constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak8),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                  ),
                ),
                child: Row(
                  children: [
                    Icon(widget.ikon, size: TokenJarak.ikonSedang, color: warna.brand),
                    const SizedBox(width: TokenJarak.jarak8),
                    Expanded(
                      child: Text(
                        widget.ringkasan,
                        key: const ValueKey('RingkasanOpsi'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                      ),
                    ),
                    const SizedBox(width: TokenJarak.jarak8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8, vertical: TokenJarak.jarak4),
                      decoration: BoxDecoration(
                        color: warna.permukaan,
                        border: Border.all(color: warna.garisInput, width: TokenJarak.tebalGaris),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            _terbuka ? 'Tutup' : 'Ubah',
                            style: teks.labelMedium?.copyWith(color: warna.brand, fontWeight: FontWeight.w700),
                          ),
                          Icon(
                            _terbuka ? Icons.expand_less : Icons.chevron_right,
                            size: TokenJarak.ikonKecil,
                            color: warna.brand,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
        if (_terbuka) ...widget.children,
      ],
    );
  }
}
