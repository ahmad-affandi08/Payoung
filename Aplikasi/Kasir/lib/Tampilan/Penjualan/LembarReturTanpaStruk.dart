import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Pelanggan/LayananPelanggan.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananReturPenjualan.dart';
import '../../Domain/Penjualan/LayananReturTanpaStruk.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatAngka.dart';
import '../LembarMutasiKas.dart';
import '../Struk/BagianCetakDokumen.dart';
import '../Komponen/PilihanAlasan.dart';

/// Cara refund retur tanpa struk (K28): tidak pernah uang tunai/transfer.
enum CaraRefundTanpaStruk { Tukar, Deposit }

/// Retur tanpa struk (K28, PRD v4.01) di dalam lembar Retur: cari/pindai barang → jumlah & kondisi → alasan → tukar
/// barang atau deposit pelanggan → PIN penyetuju ber-izin `penjualan.retur.tanpa-struk` → simpan lokal + outbox
/// (bisa offline). Nilai = harga berlaku + pajak (tanpa promo).
class LembarReturTanpaStruk extends ConsumerStatefulWidget {
  const LembarReturTanpaStruk({
    super.key,
    required this.kasir,
    required this.saatSelesai,
    required this.saatKembali,
    this.saatTukar,
  });

  final StafLokal kasir;
  final VoidCallback saatSelesai;

  /// Kembali ke retur dari struk.
  final VoidCallback saatKembali;

  /// Tukar barang dilanjutkan di layar Jual (null = opsi tukar tidak ditawarkan).
  final VoidCallback? saatTukar;

  @override
  ConsumerState<LembarReturTanpaStruk> createState() => _LembarReturTanpaStrukState();
}

class _LembarReturTanpaStrukState extends ConsumerState<LembarReturTanpaStruk> {
  final _cari = TextEditingController();
  final _alasan = TextEditingController();
  final _cariPelanggan = TextEditingController();
  final List<BarisReturTanpaStruk> _baris = [];
  final List<TextEditingController> _jumlah = [];
  CaraRefundTanpaStruk? _cara;
  PelangganTerpilih? _pelanggan;
  List<PelangganTerpilih> _hasilPelanggan = const [];
  Timer? _jedaPelanggan;
  bool _sibuk = false;
  String? _galat;
  ReturTersimpan? _selesai;
  Uang? _batas;
  Uang? _hariIni;

  LayananReturTanpaStruk get _layanan => ref.read(penyediaLayananReturTanpaStruk);

  @override
  void initState() {
    super.initState();
    unawaited(_MuatBatas());
  }

  Future<void> _MuatBatas() async {
    final k = await ref.read(penyediaKonteksPenjualan.future);
    final batas = await _layanan.AmbilBatasHarian();
    final hariIni = await _layanan.HitungHariIni(k);
    if (mounted) {
      setState(() {
        _batas = batas;
        _hariIni = hariIni;
      });
    }
  }

  @override
  void dispose() {
    _jedaPelanggan?.cancel();
    _cari.dispose();
    _alasan.dispose();
    _cariPelanggan.dispose();
    for (final p in _jumlah) {
      p.dispose();
    }
    super.dispose();
  }

  void _Tambah(ProdukJual produk, [SatuanJual? satuan]) {
    final alasan = LayananReturTanpaStruk.AmbilAlasanTidakBisa(produk);
    final s = satuan ?? LayananReturTanpaStruk.AmbilSatuan(produk);
    setState(() {
      _cari.clear();
      if (alasan != null || s == null) {
        _galat = alasan ?? '"${produk.nama}" belum punya satuan jual.';
        return;
      }
      _galat = null;
      final ada = _baris.indexWhere((b) => b.produk.uuid == produk.uuid && b.satuan.uuid == s.uuid);
      if (ada >= 0) {
        final jumlah = _AmbilJumlah(ada) ?? Kuantitas.Nol();
        _jumlah[ada].text = FormatAngka.FormatJumlah(jumlah.Tambah(Kuantitas.DariBulat(1)));
        return;
      }
      _baris.add(BarisReturTanpaStruk(produk: produk, satuan: s, jumlah: Kuantitas.DariBulat(1)));
      _jumlah.add(TextEditingController(text: '1'));
    });
  }

  void _Hapus(int i) => setState(() {
    _baris.removeAt(i);
    _jumlah.removeAt(i).dispose();
  });

  Kuantitas? _AmbilJumlah(int i) {
    final teks = _jumlah[i].text.trim().replaceAll(',', '.');
    final d = Decimal.tryParse(teks);
    // Lebih dari 4 desimal ditolak sebagai isian tak valid (bukan dilempar), seperti di layar retur dari struk.
    return d == null || d.scale > Kuantitas.skala ? null : Kuantitas.DariDesimal(d);
  }

  /// Baris dengan jumlah terbaru; null bila ada isian jumlah yang tidak valid.
  List<BarisReturTanpaStruk>? _AmbilBaris() {
    final hasil = <BarisReturTanpaStruk>[];
    for (var i = 0; i < _baris.length; i++) {
      final j = _AmbilJumlah(i);
      if (j == null || j.BernilaiNol() || j.BernilaiNegatif()) {
        return null;
      }
      final d = j.KeDesimal();
      if (!_baris[i].satuan.bolehDesimal && d != d.truncate()) {
        return null;
      }
      hasil.add(_baris[i].Salin(jumlah: j));
    }
    return hasil;
  }

  void _CariPelanggan(String _) {
    _jedaPelanggan?.cancel();
    _jedaPelanggan = Timer(const Duration(milliseconds: 350), () async {
      final kata = _cariPelanggan.text;
      if (kata.trim().length < LayananPelanggan.panjangKataMinimal) {
        if (mounted) {
          setState(() => _hasilPelanggan = const []);
        }
        return;
      }
      try {
        final hasil = await ref.read(penyediaLayananPelanggan).Cari(kata);
        if (mounted) {
          setState(() => _hasilPelanggan = hasil.pelanggan);
        }
      } on GalatKasir catch (galat) {
        if (mounted) {
          setState(() => _galat = galat.pesan);
        }
      }
    });
  }

  Future<void> _Simpan(KonteksPenjualan k, KatalogLokal katalog, HitunganReturTanpaStruk hitungan) async {
    final baris = _AmbilBaris();
    final cara = _cara;
    try {
      if (baris == null) {
        throw const GalatKasir('JumlahTidakValid', 'Periksa jumlah barang yang ditandai merah.');
      }
      LayananReturTanpaStruk.Validasi(baris);
      if (_alasan.text.trim().runes.length < LayananReturPenjualan.panjangAlasanMinimal) {
        throw const GalatKasir('AlasanDiperlukan', 'Tulis alasan retur minimal 5 huruf.');
      }
      if (cara == null) {
        throw const GalatKasir('CaraRefundKosong', 'Pilih tukar barang atau deposit pelanggan.');
      }
      if (cara == CaraRefundTanpaStruk.Deposit && _pelanggan == null) {
        throw const GalatKasir('DepositTanpaPelanggan', 'Pilih pelanggan penerima deposit.');
      }
      if (cara == CaraRefundTanpaStruk.Tukar && !ref.read(penyediaKeranjang).CekBebas) {
        throw const GalatKasir(
          'KeranjangBerisi',
          'Keranjang masih berisi. Selesaikan atau tahan transaksi itu dulu sebelum tukar barang.',
        );
      }
    } on GalatKasir catch (galat) {
      setState(() => _galat = galat.pesan);
      return;
    }

    // Tukar barang: PIN diminta nanti, saat pembayaran barang pengganti diselesaikan (nilai transaksi sudah pasti).
    if (cara == CaraRefundTanpaStruk.Tukar) {
      _MulaiTukar(k, katalog, baris, hitungan.total);
      return;
    }

    final penyetuju = await showDialog<StafLokal>(
      context: context,
      builder: (_) => DialogPinSupervisor(
        izin: IzinKasir.penjualanReturTanpaStruk,
        judulDialog: 'Persetujuan retur tanpa struk',
        pesan: 'Retur tanpa struk ${hitungan.total.FormatRupiah()} wajib disetujui pemilik atau pengguna yang berhak.',
        pesanKosong:
            'Belum ada pemilik atau pengguna berizin "Menyetujui retur tanpa struk" di perangkat ini. '
            'Atur izinnya di back-office menu Pengguna & peran.',
        judul: 'Retur tanpa struk',
        nilai: hitungan.total,
      ),
    );
    if (penyetuju == null || !mounted) {
      return;
    }

    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final tersimpan = await _layanan.Simpan(
        baris: baris,
        alasan: _alasan.text,
        kasir: widget.kasir,
        penyetuju: penyetuju,
        metode: k.metodePembayaran.firstWhere((m) => m.Jenis == JenisMetodeBayar.deposit),
        katalog: katalog,
        k: k,
        uuidPelanggan: _pelanggan!.uuid,
      );
      if (mounted) {
        setState(() => _selesai = tersimpan);
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
        unawaited(UmpanAksi.Gagal(context, judul: 'Retur tanpa struk belum tersimpan', pesan: galat.pesan));
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  void _MulaiTukar(KonteksPenjualan k, KatalogLokal katalog, List<BarisReturTanpaStruk> baris, Uang nilai) {
    final metode = k.metodeTukar;
    final saatTukar = widget.saatTukar;
    if (metode == null || saatTukar == null) {
      setState(() => _galat = 'Tukar barang belum tersedia. Perbarui data kasir.');
      return;
    }
    final alasan = _alasan.text;
    final kasir = widget.kasir;
    final layanan = _layanan;
    final uuidRetur = ref.read(penyediaLayananPenjualan).BuatUuid();
    final penyetuju = PenyetujuTukar(
      izin: IzinKasir.penjualanReturTanpaStruk,
      pesan: 'Retur tanpa struk ${nilai.FormatRupiah()} wajib disetujui pemilik atau pengguna yang berhak.',
    );
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(
          Keranjang.kosong.Salin(
            tukar: () => TukarKeranjang(
              uuidRetur: uuidRetur,
              penyetuju: penyetuju,
              nomorPenjualanAsal: '',
              nilai: nilai,
              uuidMetode: metode.Uuid,
              namaMetode: metode.Nama,
              tanpaStruk: true,
              // Barang pengganti minimal senilai retur, jadi seluruh nilai menjadi refund Tukar (tanpa tunai).
              simpanRetur: ({required tukar, required tunai}) async => (await layanan.Simpan(
                baris: baris,
                alasan: alasan,
                kasir: penyetuju.kasir ?? kasir,
                penyetuju: penyetuju.staf!,
                metode: metode,
                katalog: katalog,
                k: k,
                uuidRetur: uuidRetur,
              )).nomor,
            ),
          ),
        );
    saatTukar();
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final selesai = _selesai;
    final katalog = ref.watch(penyediaKatalog).value;
    final k = ref.watch(penyediaKonteksPenjualan).value;

    Widget Bingkai(List<Widget> anak) => Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: anak),
    );
    final galat = _galat == null
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
            child: Text(_galat!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
          );

    if (selesai != null) {
      return Bingkai([
        Row(
          children: [
            Icon(Icons.check_circle_outline, color: warna.sukses, size: TokenJarak.ikonBesar),
            const SizedBox(width: TokenJarak.jarak8),
            Expanded(child: Text('Retur tanpa struk tersimpan.', style: teks.titleMedium)),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak8),
        TeksKode(selesai.nomor, gaya: teks.titleSmall),
        const SizedBox(height: TokenJarak.jarak12),
        KotakPanel(
          anak: Row(
            children: [
              Expanded(child: Text('Masuk deposit ${_pelanggan?.nama ?? 'pelanggan'}', style: teks.titleSmall)),
              TeksUang(selesai.totalRefund, gaya: teks.titleSmall),
            ],
          ),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Tidak ada uang keluar dari laci. Stok barang kembali & jurnal dicatat server setelah data terkirim.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        BagianCetakDokumen(
          kunci: 'Retur:${selesai.uuid}',
          namaDokumen: 'nota retur',
          cetak: (l, ulang, otomatis) => l.CetakRetur(selesai.uuid, cetakUlang: ulang),
        ),
        const SizedBox(height: TokenJarak.jarak16),
        SizedBox(
          height: 56,
          child: FilledButton(onPressed: widget.saatSelesai, child: const Text('Selesai')),
        ),
      ]);
    }

    if (katalog == null || k == null) {
      return Bingkai([const LinearProgressIndicator()]);
    }

    final kata = _cari.text.trim();
    final hasilCari = kata.length < 2 ? const <ProdukJual>[] : katalog.AmbilTampil(kata: kata).take(6).toList();
    final baris = _AmbilBaris();
    HitunganReturTanpaStruk? hitungan;
    String? galatHitung;
    if (baris != null && baris.isNotEmpty) {
      try {
        hitungan = _layanan.Hitung(baris, katalog, k);
      } on GalatKasir catch (galat) {
        galatHitung = galat.pesan;
      }
    }
    final bisaTukar = k.metodeTukar != null && widget.saatTukar != null;
    final bisaDeposit = k.deposit.berlaku && k.metodePembayaran.any((m) => m.Jenis == JenisMetodeBayar.deposit);
    final batas = _batas;
    final hariIni = _hariIni;
    final lewatBatas =
        hitungan != null && batas != null && hariIni != null && hariIni.Tambah(hitungan.total).Bandingkan(batas) > 0;

    return Bingkai([
      Text(
        'Untuk pembeli tanpa struk. Nilai mengikuti harga jual saat ini, wajib PIN pemilik atau pengguna yang berhak, '
        'dan hanya bisa ditukar barang atau masuk deposit pelanggan, tidak dikembalikan uang.',
        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
      ),
      const SizedBox(height: TokenJarak.jarak12),
      TextField(
        key: const ValueKey('CariReturTanpaStruk'),
        controller: _cari,
        autofocus: true,
        textInputAction: TextInputAction.search,
        onChanged: (_) => setState(() {}),
        onSubmitted: (kode) {
          final persis = katalog.CariKode(kode);
          if (persis != null) {
            _Tambah(persis.produk, persis.satuan);
          } else if (hasilCari.length == 1) {
            _Tambah(hasilCari.single);
          }
        },
        decoration: const InputDecoration(
          labelText: 'Barang yang dikembalikan',
          hintText: 'Pindai barcode atau ketik nama/SKU',
          prefixIcon: Icon(Icons.search),
          border: OutlineInputBorder(),
        ),
      ),
      for (final p in hasilCari)
        ListTile(
          key: ValueKey('HasilReturTanpaStruk-${p.uuid}'),
          contentPadding: EdgeInsets.zero,
          title: Text(p.nama),
          subtitle: LayananReturTanpaStruk.AmbilAlasanTidakBisa(p) == null
              ? (p.sku == null ? null : Text(p.sku!))
              : const Text('Hanya bisa diretur dengan struk'),
          enabled: LayananReturTanpaStruk.AmbilAlasanTidakBisa(p) == null,
          trailing: const Icon(Icons.add),
          onTap: () => _Tambah(p),
        ),
      const SizedBox(height: TokenJarak.jarak12),
      if (_baris.isEmpty)
        Text('Belum ada barang.', style: teks.bodyMedium?.copyWith(color: warna.teksSekunder))
      else
        for (var i = 0; i < _baris.length; i++)
          _BarisTanpaStruk(
            key: ValueKey('BarisTanpaStruk-${_baris[i].produk.uuid}-${_baris[i].satuan.uuid}'),
            baris: _baris[i],
            pengendali: _jumlah[i],
            galat: switch (_AmbilJumlah(i)) {
              null => 'Isi jumlah',
              final j when j.BernilaiNol() || j.BernilaiNegatif() => 'Lebih dari 0',
              final j when !_baris[i].satuan.bolehDesimal && j.KeDesimal() != j.KeDesimal().truncate() =>
                'Bilangan bulat',
              _ => null,
            },
            nilai: hitungan == null || i >= hitungan.nilaiBaris.length ? null : hitungan.nilaiBaris[i],
            saatBerubah: () => setState(() => _galat = null),
            saatKondisi: (kondisi) => setState(() => _baris[i] = _baris[i].Salin(kondisi: kondisi)),
            saatHapus: () => _Hapus(i),
          ),
      const SizedBox(height: TokenJarak.jarak12),
      PilihanAlasan(pengendali: _alasan, pilihan: PilihanAlasan.retur),
      TextField(
        controller: _alasan,
        maxLength: 255,
        decoration: const InputDecoration(
          labelText: 'Alasan retur',
          hintText: 'Contoh: struk hilang, barang belum dibuka',
          border: OutlineInputBorder(),
        ),
      ),
      Text('Dikembalikan sebagai', style: teks.titleSmall),
      const SizedBox(height: TokenJarak.jarak8),
      if (!bisaTukar && !bisaDeposit)
        Text(
          'Tukar barang dan deposit pelanggan belum tersedia di perangkat ini. Perbarui data kasir.',
          style: teks.bodyMedium?.copyWith(color: warna.bahaya),
        )
      else
        SegmentedButton<CaraRefundTanpaStruk>(
          showSelectedIcon: false,
          emptySelectionAllowed: true,
          segments: [
            ButtonSegment(value: CaraRefundTanpaStruk.Tukar, label: const Text('Tukar barang'), enabled: bisaTukar),
            ButtonSegment(
              value: CaraRefundTanpaStruk.Deposit,
              label: const Text('Deposit pelanggan'),
              enabled: bisaDeposit,
            ),
          ],
          selected: {?_cara},
          onSelectionChanged: (pilih) => setState(() {
            _cara = pilih.firstOrNull;
            _galat = null;
          }),
        ),
      if (_cara == CaraRefundTanpaStruk.Tukar)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak4),
          child: Text(
            'Barang pengganti dipilih di layar Jual dan minimal senilai retur. Kekurangannya dibayar pelanggan.',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
        ),
      if (_cara == CaraRefundTanpaStruk.Deposit) ...[
        const SizedBox(height: TokenJarak.jarak8),
        if (_pelanggan case final p?)
          InputChip(
            key: const ValueKey('PelangganReturTanpaStruk'),
            label: Text('${p.nama} | ${p.noHpSamar}'),
            onDeleted: () => setState(() => _pelanggan = null),
          )
        else ...[
          TextField(
            controller: _cariPelanggan,
            onChanged: _CariPelanggan,
            decoration: const InputDecoration(
              labelText: 'Pelanggan penerima deposit',
              hintText: 'Nama atau nomor HP (min. 3 huruf)',
              border: OutlineInputBorder(),
            ),
          ),
          for (final p in _hasilPelanggan.take(5))
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(p.nama),
              subtitle: Text(p.noHpSamar),
              onTap: () => setState(() {
                _pelanggan = p;
                _hasilPelanggan = const [];
                _cariPelanggan.clear();
              }),
            ),
        ],
      ],
      const SizedBox(height: TokenJarak.jarak12),
      KotakPanel(
        anak: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(child: Text('Nilai retur', style: teks.titleSmall)),
                if (hitungan != null) TeksUang(hitungan.total, gaya: teks.titleSmall) else const Text('—'),
              ],
            ),
            if (galatHitung != null) Text(galatHitung, style: teks.bodySmall?.copyWith(color: warna.bahaya)),
            for (final p in hitungan?.peringatan ?? const <String>[])
              Text(p, style: teks.bodySmall?.copyWith(color: warna.peringatan)),
            if (lewatBatas)
              Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.warning_amber_outlined, color: warna.peringatan, size: TokenJarak.ikonSedang),
                    const SizedBox(width: TokenJarak.jarak8),
                    Expanded(
                      child: Text(
                        'Retur tanpa struk hari ini menjadi ${hariIni.Tambah(hitungan.total).FormatRupiah()}, melewati '
                        'batas ${batas.FormatRupiah()}. Retur tetap tersimpan dan akan dicek pemilik.',
                        style: teks.bodySmall,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
      galat,
      const SizedBox(height: TokenJarak.jarak8),
      SizedBox(
        height: 56,
        child: FilledButton(
          key: const ValueKey('SimpanReturTanpaStruk'),
          onPressed: _sibuk || hitungan == null ? null : () => unawaited(_Simpan(k, katalog, hitungan!)),
          child: Text(
            _sibuk
                ? 'Menyimpan…'
                : _cara == CaraRefundTanpaStruk.Tukar
                ? 'Pilih barang pengganti'
                : 'Minta PIN & simpan retur',
          ),
        ),
      ),
      const SizedBox(height: TokenJarak.jarak8),
      SizedBox(
        height: TokenJarak.targetSentuh,
        child: TextButton(onPressed: widget.saatKembali, child: const Text('Kembali ke retur dari struk')),
      ),
    ]);
  }
}

/// Satu barang retur tanpa struk: jumlah, kondisi, nilai, hapus.
class _BarisTanpaStruk extends StatelessWidget {
  const _BarisTanpaStruk({
    super.key,
    required this.baris,
    required this.pengendali,
    required this.galat,
    required this.nilai,
    required this.saatBerubah,
    required this.saatKondisi,
    required this.saatHapus,
  });

  final BarisReturTanpaStruk baris;
  final TextEditingController pengendali;
  final String? galat;
  final Uang? nilai;
  final VoidCallback saatBerubah;
  final ValueChanged<String> saatKondisi;
  final VoidCallback saatHapus;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak12),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(baris.produk.nama, style: teks.bodyLarge)),
              if (nilai != null) TeksUang(nilai!, gaya: teks.bodyMedium),
              IconButton(tooltip: 'Hapus barang', icon: const Icon(Icons.close), onPressed: saatHapus),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          TextField(
            controller: pengendali,
            keyboardType: TextInputType.numberWithOptions(decimal: baris.satuan.bolehDesimal),
            textAlign: TextAlign.right,
            onChanged: (_) => saatBerubah(),
            style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
            decoration: InputDecoration(
              labelText: 'Jumlah (${baris.satuan.nama})',
              errorText: galat,
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          SegmentedButton<String>(
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: KondisiRetur.layakJual, label: Text('Layak jual')),
              ButtonSegment(value: KondisiRetur.rusak, label: Text('Rusak')),
            ],
            selected: {baris.kondisi},
            onSelectionChanged: (pilih) => saatKondisi(pilih.first),
          ),
        ],
      ),
    );
  }
}
