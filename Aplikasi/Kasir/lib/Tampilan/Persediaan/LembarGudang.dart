import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Persediaan/LayananGudang.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// Panel tugas modul Gudang (POS-25): daftar dokumen [jenis] di lokasi outlet perangkat (online), lalu isian per baris
/// dengan kolom pindai (pemindai barcode USB/Bluetooth mengetik kode + Enter, atau ketik SKU). Isian tersimpan sebagai
/// draf lokal setiap berubah; posting ke server saat tombol utama ditekan.
class LembarGudang extends ConsumerStatefulWidget {
  const LembarGudang({super.key, required this.jenis, required this.staf});

  final JenisGudang jenis;
  final StafLokal staf;

  @override
  ConsumerState<LembarGudang> createState() => _LembarGudangState();
}

/// Satu dokumen gudang dalam bentuk seragam untuk daftar & isian.
class _Dokumen {
  const _Dokumen({required this.uuid, required this.judul, required this.keterangan, required this.baris, this.asli});

  final String uuid;
  final String judul;
  final String keterangan;
  final List<_Baris> baris;
  final Object? asli;
}

/// Satu baris isian: [info] = sisa/sistem/batch/seri (berteks), [bawaan] = isian awal (hitung opname tersimpan).
class _Baris {
  const _Baris({
    required this.urutan,
    required this.uuidProduk,
    required this.nama,
    required this.sku,
    required this.satuan,
    required this.info,
    required this.bolehDesimal,
    this.sisa,
    this.pelacakan = 'Tidak',
    this.nomorBatch,
    this.nomorSeri,
    this.bawaan,
  });

  final int urutan;
  final String? uuidProduk;
  final String nama;
  final String? sku;
  final String satuan;
  final String info;
  final bool bolehDesimal;
  final String? sisa;
  final String pelacakan;
  final String? nomorBatch;
  final String? nomorSeri;
  final String? bawaan;
}

class _LembarGudangState extends ConsumerState<LembarGudang> {
  List<_Dokumen>? _daftar;
  _Dokumen? _dokumen;
  DrafGudang? _draf;
  bool _memuat = false;
  bool _mengirim = false;
  String? _galat;
  String? _pesanPindai;

  /// Baris seri terakhir yang produknya dipindai (penerimaan); tujuan nomor seri yang dipindai sesudahnya.
  int? _urutanSeriAktif;
  final _cari = TextEditingController();
  final _pindai = TextEditingController();
  final _fokusPindai = FocusNode();
  final _suratJalan = TextEditingController();
  final Map<int, TextEditingController> _jumlah = {};
  final Map<int, TextEditingController> _batch = {};
  final Map<int, TextEditingController> _kedaluwarsa = {};
  final Map<int, TextEditingController> _seri = {};
  final Map<String, TextEditingController> _produkBaru = {};

  LayananGudang get _layanan => ref.read(penyediaLayananGudang);

  @override
  void initState() {
    super.initState();
    unawaited(Future<void>.microtask(_Muat));
  }

  @override
  void dispose() {
    for (final c in [
      _cari,
      _pindai,
      _suratJalan,
      ..._jumlah.values,
      ..._batch.values,
      ..._kedaluwarsa.values,
      ..._seri.values,
      ..._produkBaru.values,
    ]) {
      c.dispose();
    }
    _fokusPindai.dispose();
    super.dispose();
  }

  // Pemetaan dokumen server → bentuk seragam -------------------------------------------------------------------------

  static String _F(String jumlah) => LayananGudang.FormatJumlah(jumlah);

  static _Dokumen _DariPesanan(PesananGudangPos p) => _Dokumen(
    uuid: p.uuid,
    judul: p.nomor,
    keterangan: '${p.namaPemasok} | ${p.labelStatus}${p.perkiraanTiba == null ? '' : ' | tiba ${p.perkiraanTiba}'}',
    asli: p,
    baris: [
      for (final b in p.baris)
        _Baris(
          urutan: b.urutan,
          uuidProduk: b.uuidProduk,
          nama: b.namaProduk,
          sku: b.sku,
          satuan: b.simbolSatuan,
          bolehDesimal: true,
          sisa: b.sisa,
          pelacakan: b.pelacakan,
          info:
              'Dipesan ${_F(b.jumlah)} | diterima ${_F(b.jumlahDiterima)} | sisa ${_F(b.sisa)} ${b.simbolSatuan}'
              '${b.pelacakan == 'Batch'
                  ? ' | wajib nomor batch'
                  : b.pelacakan == 'Seri'
                  ? ' | wajib nomor seri'
                  : ''}',
        ),
    ],
  );

  static _Dokumen _DariTransfer(TransferGudangPos t) => _Dokumen(
    uuid: t.uuid,
    judul: t.nomor,
    keterangan: 'Dari ${t.namaAsal} | ${t.labelStatus}',
    asli: t,
    baris: [
      for (final b in t.baris)
        _Baris(
          urutan: b.urutan,
          uuidProduk: b.uuidProduk,
          nama: b.namaProduk,
          sku: b.sku,
          satuan: b.simbolSatuan,
          bolehDesimal: b.bolehDesimal,
          sisa: b.sisa,
          nomorBatch: b.nomorBatch,
          nomorSeri: b.nomorSeri,
          info:
              'Dikirim ${_F(b.jumlahDikirim)} | diterima ${_F(b.jumlahDiterima)} | sisa ${_F(b.sisa)} ${b.simbolSatuan}'
              '${b.nomorBatch == null ? '' : ' | batch ${b.nomorBatch}'}${b.nomorSeri == null ? '' : ' | seri ${b.nomorSeri}'}',
        ),
    ],
  );

  static _Dokumen _DariOpname(OpnameGudangPos o) => _Dokumen(
    uuid: o.uuid,
    judul: o.nomor,
    keterangan:
        '${o.namaLokasi}${o.namaRak == null ? '' : ' | rak ${o.namaRak}'}${o.namaKategori == null ? '' : ' | ${o.namaKategori}'} | ${o.jumlahDihitung}/${o.jumlahBaris} '
        'dihitung${o.hitungButa ? ' | hitung buta' : ''}',
    asli: o,
    baris: [
      for (final b in o.baris)
        _Baris(
          urutan: b.urutan,
          uuidProduk: b.uuidProduk,
          nama: b.namaProduk,
          sku: b.sku,
          satuan: b.simbolSatuan,
          bolehDesimal: b.nomorSeri == null && b.bolehDesimal,
          nomorBatch: b.nomorBatch,
          nomorSeri: b.nomorSeri,
          bawaan: b.jumlahFisik == null ? null : _F(b.jumlahFisik!),
          info: [
            if (b.jumlahSistem != null) 'Sistem ${_F(b.jumlahSistem!)} ${b.simbolSatuan}' else b.simbolSatuan,
            if (b.nomorBatch != null) 'batch ${b.nomorBatch}',
            if (b.nomorSeri != null) 'seri ${b.nomorSeri} (isi 1 bila ada, 0 bila tidak)',
            if (b.jumlahFisik != null) 'tersimpan ${_F(b.jumlahFisik!)}',
          ].join(' | '),
        ),
    ],
  );

  // Daftar -----------------------------------------------------------------------------------------------------------

  Future<void> _Muat() async {
    setState(() {
      _memuat = true;
      _galat = null;
    });
    try {
      final daftar = switch (widget.jenis) {
        JenisGudang.Penerimaan => (await _layanan.AmbilPesanan(kata: _cari.text)).map(_DariPesanan).toList(),
        JenisGudang.Transfer => (await _layanan.AmbilTransfer()).map(_DariTransfer).toList(),
        JenisGudang.Opname => (await _layanan.AmbilOpname()).map(_DariOpname).toList(),
      };
      if (mounted) {
        setState(() => _daftar = daftar);
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _memuat = false);
      }
    }
  }

  Future<void> _Buka(_Dokumen dokumen) async {
    final draf = await _layanan.MuatDraf(widget.jenis, dokumen.uuid);
    if (!mounted) {
      return;
    }
    _BersihkanIsian();
    for (final b in dokumen.baris) {
      _jumlah[b.urutan] = TextEditingController(text: draf.jumlah[b.urutan] ?? b.bawaan ?? '');
      final p = draf.pelacakan[b.urutan];
      if (b.pelacakan == 'Batch' && widget.jenis == JenisGudang.Penerimaan) {
        _batch[b.urutan] = TextEditingController(text: p?.nomorBatch ?? '');
        _kedaluwarsa[b.urutan] = TextEditingController(text: p?.tanggalKedaluwarsa ?? '');
      }
      if (b.pelacakan == 'Seri' && widget.jenis == JenisGudang.Penerimaan) {
        _seri[b.urutan] = TextEditingController();
      }
    }
    for (final e in draf.produkBaru.entries) {
      _produkBaru[e.key] = TextEditingController(text: e.value);
    }
    _suratJalan.text = draf.nomorSuratJalan ?? '';
    setState(() {
      _dokumen = dokumen;
      _draf = draf;
      _galat = null;
      _pesanPindai = null;
    });
  }

  void _BersihkanIsian() {
    for (final peta in [_jumlah, _batch, _kedaluwarsa, _seri]) {
      for (final c in peta.values) {
        c.dispose();
      }
      peta.clear();
    }
    for (final c in _produkBaru.values) {
      c.dispose();
    }
    _produkBaru.clear();
  }

  void _Kembali() {
    _BersihkanIsian();
    setState(() {
      _dokumen = null;
      _draf = null;
      _galat = null;
    });
    unawaited(_Muat());
  }

  // Isian ------------------------------------------------------------------------------------------------------------

  /// Salin isian formulir ke draf lalu simpan lokal.
  Future<void> _SimpanDraf() async {
    final draf = _draf;
    final dokumen = _dokumen;
    if (draf == null || dokumen == null) {
      return;
    }
    draf.jumlah
      ..clear()
      ..addAll({
        for (final e in _jumlah.entries)
          if (e.value.text.trim().isNotEmpty && e.value.text.trim() != _BawaanDari(dokumen, e.key))
            e.key: e.value.text.trim(),
      });
    for (final b in dokumen.baris) {
      final p = draf.pelacakan[b.urutan] ?? PelacakanDraf();
      if (_batch.containsKey(b.urutan)) {
        p
          ..nomorBatch = _batch[b.urutan]!.text.trim()
          ..tanggalKedaluwarsa = _kedaluwarsa[b.urutan]!.text.trim();
      }
      if (_batch.containsKey(b.urutan) || p.nomorSeri.isNotEmpty) {
        draf.pelacakan[b.urutan] = p;
      }
    }
    draf.produkBaru
      ..clear()
      ..addAll({for (final e in _produkBaru.entries) e.key: e.value.text.trim()});
    draf.nomorSuratJalan = _suratJalan.text.trim();
    await _layanan.SimpanDraf(draf);
  }

  /// Opname: isian yang sama dengan jumlah tersimpan di server tidak dianggap perubahan.
  static String? _BawaanDari(_Dokumen dokumen, int urutan) =>
      dokumen.baris.where((b) => b.urutan == urutan).firstOrNull?.bawaan;

  void _Ubah() => unawaited(_SimpanDraf());

  void _Tambah(int urutan, int langkah) {
    final c = _jumlah[urutan];
    if (c == null) {
      return;
    }
    c.text = LayananGudang.TambahJumlah(c.text, langkah: langkah).replaceAll('.', ',');
    setState(() {});
    _Ubah();
  }

  /// Hasil pindai/ketik: cocokkan ke baris (barcode/SKU/nomor batch/seri) lalu tambah 1; nomor seri penerimaan masuk
  /// ke baris seri yang belum lengkap; opname: produk katalog di luar lembar → baris baru.
  void _Pindai(String kode) {
    final dokumen = _dokumen;
    final rapi = kode.trim();
    _pindai.clear();
    _fokusPindai.requestFocus();
    if (dokumen == null || rapi.isEmpty) {
      return;
    }
    final katalog = ref.read(penyediaKatalog).value ?? KatalogLokal.kosong;
    final uuidProduk = LayananGudang.CariProdukDariKode(rapi, katalog);
    final kecil = rapi.toLowerCase();

    // Nomor seri/batch persis (opname & transfer) lebih dulu.
    final persis = dokumen.baris
        .where((b) => b.nomorSeri?.toLowerCase() == kecil || b.nomorBatch?.toLowerCase() == kecil)
        .firstOrNull;
    final cocok =
        persis ??
        dokumen.baris
            .where((b) => (uuidProduk != null && b.uuidProduk == uuidProduk) || b.sku?.toLowerCase() == kecil)
            .where((b) => b.nomorSeri == null)
            .firstOrNull;

    if (cocok != null && cocok.pelacakan == 'Seri' && widget.jenis == JenisGudang.Penerimaan) {
      // Nomor seri yang dipindai berikutnya masuk ke baris produk ini, bukan baris seri pertama dokumen.
      _urutanSeriAktif = cocok.urutan;
      setState(() => _pesanPindai = '${cocok.nama}: sekarang pindai nomor seri di kolom nomor seri barisnya.');
      return;
    }
    if (cocok != null) {
      if (cocok.nomorSeri != null) {
        _jumlah[cocok.urutan]!.text = '1';
        setState(() => _pesanPindai = '${cocok.nama} (${cocok.nomorSeri}) ditemukan.');
        _Ubah();
      } else {
        _Tambah(cocok.urutan, 1);
        setState(() => _pesanPindai = '${cocok.nama}: ${_jumlah[cocok.urutan]!.text} ${cocok.satuan}.');
      }
      return;
    }

    // Seri penerimaan: kode yang tidak dikenal sebagai produk dianggap nomor seri untuk baris seri pertama yang belum lengkap.
    final draf = _draf!;
    final barisSeri = dokumen.baris.where((b) => b.pelacakan == 'Seri' && widget.jenis == JenisGudang.Penerimaan);
    if (uuidProduk == null && barisSeri.isNotEmpty) {
      final aktif = barisSeri.where((b) => b.urutan == _urutanSeriAktif).firstOrNull ?? barisSeri.first;
      _TambahSeri(aktif.urutan, rapi);
      return;
    }

    if (widget.jenis == JenisGudang.Opname && uuidProduk != null) {
      final produk = katalog.CariProduk(uuidProduk)!;
      if (produk.berBatch || produk.bernomorSeri) {
        // Hitung opname produk berpelacakan butuh batch/nomor serinya; server menolak seluruh simpan bila kosong.
        setState(
          () => _pesanPindai =
              '${produk.nama} ber-batch atau bernomor seri dan tidak ada di lembar hitung ini. Mulai opname dari '
              'back-office agar barisnya muncul.',
        );
        return;
      }
      final c = _produkBaru.putIfAbsent(uuidProduk, TextEditingController.new);
      c.text = LayananGudang.TambahJumlah(c.text).replaceAll('.', ',');
      draf.produkBaru[uuidProduk] = c.text;
      setState(() => _pesanPindai = '${produk.nama} tidak ada di lembar hitung. Ditambahkan sebagai baris baru.');
      _Ubah();
      return;
    }
    setState(
      () => _pesanPindai = uuidProduk == null
          ? 'Kode "$rapi" tidak dikenal di katalog perangkat.'
          : 'Produk dengan kode "$rapi" tidak ada di dokumen ini.',
    );
  }

  Future<void> _PindaiKamera() async {
    final hasil = await ref
        .read(penyediaPemindaiQr)
        .Pindai(
          context,
          judul: 'Pindai barcode barang',
          petunjuk: 'Arahkan kamera ke barcode produk, batch, atau nomor seri.',
        );
    if (!mounted || hasil == null) {
      return;
    }
    _Pindai(hasil);
  }

  void _TambahSeri(int urutan, String nomor) {
    final draf = _draf!;
    final p = draf.pelacakan.putIfAbsent(urutan, PelacakanDraf.new);
    final rapi = nomor.trim();
    if (rapi.isEmpty) {
      return;
    }
    if (p.nomorSeri.any((s) => s.toLowerCase() == rapi.toLowerCase())) {
      setState(() => _pesanPindai = 'Nomor seri $rapi sudah dipindai.');
      return;
    }
    p.nomorSeri.add(rapi);
    _jumlah[urutan]?.text = '${p.nomorSeri.length}';
    setState(() => _pesanPindai = 'Nomor seri $rapi ditambahkan.');
    _Ubah();
  }

  void _HapusSeri(int urutan, String nomor) {
    final p = _draf!.pelacakan[urutan];
    if (p == null) {
      return;
    }
    p.nomorSeri.remove(nomor);
    _jumlah[urutan]?.text = p.nomorSeri.isEmpty ? '' : '${p.nomorSeri.length}';
    setState(() {});
    _Ubah();
  }

  void _IsiSemuaSisa() {
    for (final b in _dokumen?.baris ?? const <_Baris>[]) {
      if (b.sisa != null && b.pelacakan != 'Seri' && _jumlah[b.urutan] != null) {
        _jumlah[b.urutan]!.text = _F(b.sisa!);
      }
    }
    setState(() {});
    _Ubah();
  }

  Future<void> _Kirim() async {
    final dokumen = _dokumen;
    final draf = _draf;
    if (dokumen == null || draf == null) {
      return;
    }
    await _SimpanDraf();
    final isi = draf.jumlah.length + draf.produkBaru.length;
    if (!mounted) {
      return;
    }
    final setuju = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: Text(switch (widget.jenis) {
          JenisGudang.Penerimaan => 'Terima barang ${dokumen.judul}?',
          JenisGudang.Transfer => 'Terima transfer ${dokumen.judul}?',
          JenisGudang.Opname => 'Simpan hitung ${dokumen.judul}?',
        }),
        content: Text(switch (widget.jenis) {
          JenisGudang.Opname =>
            '$isi baris hasil hitung disimpan. Selisih dihitung saat opname disetujui di back-office.',
          _ =>
            '$isi baris diterima. Stok bertambah sekarang dan tidak bisa diubah. Koreksi lewat retur atau penyesuaian.',
        }),
        actions: [
          TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Periksa lagi')),
          FilledButton(onPressed: () => Navigator.of(konteks).pop(true), child: const Text('Ya, simpan')),
        ],
      ),
    );
    if (setuju != true || !mounted) {
      return;
    }
    setState(() {
      _mengirim = true;
      _galat = null;
    });
    try {
      final pesan = switch (widget.jenis) {
        JenisGudang.Penerimaan => await _KirimPenerimaan(dokumen, draf),
        JenisGudang.Transfer => await _KirimTransfer(dokumen, draf),
        JenisGudang.Opname => await _KirimOpname(dokumen, draf),
      };
      if (mounted) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(pesan)));
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _mengirim = false);
      }
    }
  }

  Future<String> _KirimPenerimaan(_Dokumen dokumen, DrafGudang draf) async {
    final hasil = await _layanan.KirimPenerimaan(dokumen.asli! as PesananGudangPos, draf, widget.staf);
    final baru = hasil.pesanan;
    if (baru == null) {
      _Kembali();
      return '${hasil.nomor} diposting. Pesanan sudah diterima penuh.';
    }
    await _Buka(_DariPesanan(baru));
    return '${hasil.nomor} diposting. Stok bertambah. Sisa pesanan masih bisa diterima.';
  }

  Future<String> _KirimTransfer(_Dokumen dokumen, DrafGudang draf) async {
    final hasil = await _layanan.KirimTerimaTransfer(dokumen.asli! as TransferGudangPos, draf, widget.staf);
    if (hasil.status == 'Diterima') {
      _Kembali();
      return '${hasil.nomor} diterima penuh. Stok bertambah.';
    }
    await _Buka(_DariTransfer(hasil));
    return '${hasil.nomor} diterima sebagian. Sisa masih dalam perjalanan.';
  }

  Future<String> _KirimOpname(_Dokumen dokumen, DrafGudang draf) async {
    final hasil = await _layanan.KirimHitung(dokumen.asli! as OpnameGudangPos, draf, widget.staf);
    await _Buka(_DariOpname(hasil));
    return 'Hasil hitung ${hasil.nomor} tersimpan (${hasil.jumlahDihitung}/${hasil.jumlahBaris} baris dihitung).';
  }

  // Tampilan ---------------------------------------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    if (!LayananGudang.CekBoleh(widget.staf, widget.jenis)) {
      return Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak24),
        child: Text('${widget.staf.nama} tidak punya izin untuk ${widget.jenis.judul.toLowerCase()}.'),
      );
    }
    final dokumen = _dokumen;
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (dokumen == null) ..._BangunDaftar(context) else ..._BangunIsian(context, dokumen),
          if (_galat != null) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Text(_galat!, style: TextStyle(color: warna.bahaya)),
          ],
        ],
      ),
    );
  }

  List<Widget> _BangunDaftar(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final daftar = _daftar;
    return [
      Row(
        children: [
          if (widget.jenis == JenisGudang.Penerimaan)
            Expanded(
              child: TextField(
                controller: _cari,
                onSubmitted: (_) => unawaited(_Muat()),
                decoration: const InputDecoration(
                  labelText: 'Cari nomor PO',
                  prefixIcon: Icon(Icons.search),
                  border: OutlineInputBorder(),
                ),
              ),
            )
          else
            Expanded(child: Text(_JudulDaftar(), style: teks.titleSmall)),
          const SizedBox(width: TokenJarak.jarak8),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: OutlinedButton.icon(
              onPressed: _memuat ? null : () => unawaited(_Muat()),
              icon: const Icon(Icons.refresh),
              label: const Text('Muat ulang'),
            ),
          ),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak8),
      if (_memuat) const LinearProgressIndicator(),
      if (daftar != null && daftar.isEmpty && !_memuat)
        KeadaanKosong(
          ikon: Icons.inventory_2_outlined,
          ilustrasi: IlustrasiKosong.Stok,
          ringkas: true,
          judul: _PesanKosong(),
        ),
      for (final d in daftar ?? const <_Dokumen>[])
        ListTile(
          minTileHeight: TokenJarak.targetSentuh,
          contentPadding: EdgeInsets.zero,
          title: TeksKode(d.judul, gaya: teks.labelLarge),
          subtitle: Text('${d.keterangan} | ${d.baris.length} baris', style: teks.bodySmall),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => unawaited(_Buka(d)),
        ),
    ];
  }

  String _JudulDaftar() => switch (widget.jenis) {
    JenisGudang.Transfer => 'Transfer yang sedang menuju outlet ini',
    JenisGudang.Opname => 'Stok opname yang sedang berlangsung',
    JenisGudang.Penerimaan => 'Pesanan pembelian siap diterima',
  };

  String _PesanKosong() => switch (widget.jenis) {
    JenisGudang.Penerimaan => 'Tidak ada pesanan pembelian yang menunggu diterima di outlet ini.',
    JenisGudang.Transfer => 'Tidak ada transfer stok yang menuju outlet ini.',
    JenisGudang.Opname => 'Tidak ada stok opname yang berlangsung. Mulai opname dari back-office.',
  };

  List<Widget> _BangunIsian(BuildContext context, _Dokumen dokumen) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final katalog = ref.watch(penyediaKatalog).value ?? KatalogLokal.kosong;
    final adaPemindaiKamera = ref.watch(penyediaPemindaiQr).CekTersedia();
    return [
      Row(
        children: [
          IconButton(
            tooltip: 'Kembali ke daftar',
            onPressed: _mengirim ? null : _Kembali,
            icon: const Icon(Icons.arrow_back),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                TeksKode(dokumen.judul, gaya: teks.titleMedium),
                Text(dokumen.keterangan, style: teks.bodySmall),
              ],
            ),
          ),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak12),
      TextField(
        key: const ValueKey('PindaiGudang'),
        controller: _pindai,
        focusNode: _fokusPindai,
        autofocus: true,
        onSubmitted: _Pindai,
        decoration: InputDecoration(
          labelText: 'Pindai atau ketik barcode / SKU',
          prefixIcon: const Icon(Icons.qr_code_scanner),
          suffixIcon: adaPemindaiKamera
              ? IconButton(
                  tooltip: 'Pindai barcode dengan kamera',
                  onPressed: _mengirim ? null : () => unawaited(_PindaiKamera()),
                  icon: const Icon(Icons.photo_camera_outlined),
                )
              : null,
          border: const OutlineInputBorder(),
        ),
      ),
      if (_pesanPindai != null)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak4),
          child: Text(_pesanPindai!, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
        ),
      const SizedBox(height: TokenJarak.jarak12),
      if (widget.jenis == JenisGudang.Penerimaan) ...[
        TextField(
          controller: _suratJalan,
          onChanged: (_) => _Ubah(),
          decoration: const InputDecoration(labelText: 'Nomor surat jalan (opsional)', border: OutlineInputBorder()),
        ),
        const SizedBox(height: TokenJarak.jarak12),
      ],
      if (widget.jenis != JenisGudang.Opname)
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: _mengirim ? null : _IsiSemuaSisa,
            icon: const Icon(Icons.done_all),
            label: const Text('Isi semua sisa'),
          ),
        ),
      for (final b in dokumen.baris) _BangunBaris(context, b),
      for (final e in _produkBaru.entries)
        _BarisIsian(
          nama: katalog.CariProduk(e.key)?.nama ?? 'Produk baru',
          info: 'Tidak ada di lembar hitung | ditambahkan dari pindai',
          pengendali: e.value,
          bolehDesimal: true,
          saatUbah: _Ubah,
          saatTambah: (langkah) {
            e.value.text = LayananGudang.TambahJumlah(e.value.text, langkah: langkah).replaceAll('.', ',');
            setState(() {});
            _Ubah();
          },
        ),
      const SizedBox(height: TokenJarak.jarak12),
      SizedBox(
        height: 56,
        child: FilledButton(
          onPressed: _mengirim ? null : () => unawaited(_Kirim()),
          child: Text(
            _mengirim
                ? 'Menyimpan…'
                : switch (widget.jenis) {
                    JenisGudang.Penerimaan => 'Terima barang',
                    JenisGudang.Transfer => 'Terima transfer',
                    JenisGudang.Opname => 'Simpan hitung',
                  },
          ),
        ),
      ),
      Padding(
        padding: const EdgeInsets.only(top: TokenJarak.jarak4),
        child: Text(
          'Isian tersimpan di perangkat sampai dikirim.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      ),
    ];
  }

  Widget _BangunBaris(BuildContext context, _Baris b) {
    final teks = Theme.of(context).textTheme;
    final seri = _draf?.pelacakan[b.urutan]?.nomorSeri ?? const <String>[];
    return _BarisIsian(
      kunciJumlah: ValueKey('Jumlah-${b.urutan}'),
      nama: b.nama,
      info: b.info,
      pengendali: _jumlah[b.urutan]!,
      bolehDesimal: b.bolehDesimal,
      hanyaBaca: b.pelacakan == 'Seri' && widget.jenis == JenisGudang.Penerimaan,
      saatUbah: _Ubah,
      saatTambah: (langkah) => _Tambah(b.urutan, langkah),
      tambahan: [
        if (_batch.containsKey(b.urutan))
          Row(
            children: [
              Expanded(
                child: TextField(
                  key: ValueKey('Batch-${b.urutan}'),
                  controller: _batch[b.urutan],
                  onChanged: (_) => _Ubah(),
                  decoration: const InputDecoration(labelText: 'Nomor batch', border: OutlineInputBorder()),
                ),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: TextField(
                  controller: _kedaluwarsa[b.urutan],
                  onChanged: (_) => _Ubah(),
                  keyboardType: TextInputType.datetime,
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9-]'))],
                  decoration: const InputDecoration(
                    labelText: 'Kedaluwarsa',
                    hintText: 'TTTT-BB-HH',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
            ],
          ),
        if (_seri.containsKey(b.urutan)) ...[
          TextField(
            key: ValueKey('Seri-${b.urutan}'),
            controller: _seri[b.urutan],
            onSubmitted: (nomor) {
              _TambahSeri(b.urutan, nomor);
              _seri[b.urutan]!.clear();
            },
            decoration: const InputDecoration(
              labelText: 'Pindai nomor seri',
              prefixIcon: Icon(Icons.qr_code_2),
              border: OutlineInputBorder(),
            ),
          ),
          if (seri.isNotEmpty)
            Wrap(
              spacing: TokenJarak.jarak4,
              runSpacing: TokenJarak.jarak4,
              children: [
                for (final s in seri)
                  InputChip(
                    label: Text(s, style: teks.bodySmall),
                    onDeleted: () => _HapusSeri(b.urutan, s),
                    deleteButtonTooltipMessage: 'Hapus nomor seri $s',
                  ),
              ],
            ),
        ],
      ],
    );
  }
}

/// Satu baris isian jumlah dengan tombol −/+ (target sentuh 48dp) dan keterangan berteks.
class _BarisIsian extends StatelessWidget {
  const _BarisIsian({
    required this.nama,
    required this.info,
    required this.pengendali,
    required this.bolehDesimal,
    required this.saatUbah,
    required this.saatTambah,
    this.kunciJumlah,
    this.hanyaBaca = false,
    this.tambahan = const [],
  });

  final Key? kunciJumlah;

  final String nama;
  final String info;
  final TextEditingController pengendali;
  final bool bolehDesimal;
  final bool hanyaBaca;
  final VoidCallback saatUbah;
  final ValueChanged<int> saatTambah;
  final List<Widget> tambahan;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(nama, maxLines: 2, overflow: TextOverflow.ellipsis, style: teks.labelLarge),
          Text(info, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
          const SizedBox(height: TokenJarak.jarak4),
          Row(
            children: [
              IconButton(
                tooltip: 'Kurangi',
                onPressed: hanyaBaca ? null : () => saatTambah(-1),
                icon: const Icon(Icons.remove),
              ),
              Expanded(
                child: TextField(
                  key: kunciJumlah,
                  controller: pengendali,
                  readOnly: hanyaBaca,
                  textAlign: TextAlign.end,
                  onChanged: (_) => saatUbah(),
                  keyboardType: TextInputType.numberWithOptions(decimal: bolehDesimal),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(bolehDesimal ? r'[0-9.,]' : r'[0-9]'))],
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: hanyaBaca ? 'dari nomor seri' : 'Jumlah',
                    border: const OutlineInputBorder(),
                  ),
                ),
              ),
              IconButton(
                tooltip: 'Tambah',
                onPressed: hanyaBaca ? null : () => saatTambah(1),
                icon: const Icon(Icons.add),
              ),
            ],
          ),
          for (final w in tambahan) ...[const SizedBox(height: TokenJarak.jarak8), w],
        ],
      ),
    );
  }
}
