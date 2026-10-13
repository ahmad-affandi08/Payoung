import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Domain/Diagnostik/LogLokal.dart';
import 'Komponen/FormatWaktu.dart';

/// Teks batas respons SLA satu tiket; tiket yang sudah direspons atau lewat SLA diberi kalimat sendiri.
String TeksSlaTiket(TiketBantuan t) {
  if (!t.terbuka) {
    return t.labelStatus;
  }
  if (t.responsPertamaPada != null) {
    return '${t.labelStatus} | sudah direspons ${FormatWaktu.FormatTanggalJam(t.responsPertamaPada!)}';
  }
  final batas = t.batasSlaPada;
  if (batas == null) {
    return t.labelStatus;
  }
  final jenis = t.slaJamKerja ? 'jam kerja' : 'jam kalender';
  return t.lewatSla
      ? '${t.labelStatus} | batas respons ${FormatWaktu.FormatTanggalJam(batas)} terlewati, tim sedang dikejar'
      : '${t.labelStatus} | respons paling lambat ${FormatWaktu.FormatTanggalJam(batas)} ($jenis)';
}

/// P-09: tombol Bantuan di Pengaturan Aplikasi Kasir. Staf yang sedang masuk menghubungi tim Payoung: konteks perangkat
/// (outlet, versi, outbox tertunda) dilengkapi server, log perangkat terbaru boleh ikut (sudah disaring dari data
/// pribadi di perangkat dan disaring lagi di server). Staf hanya melihat tiketnya sendiri. Perlu internet.
class BagianBantuan extends ConsumerStatefulWidget {
  const BagianBantuan({super.key});

  @override
  ConsumerState<BagianBantuan> createState() => _KeadaanBagianBantuan();
}

class _KeadaanBagianBantuan extends ConsumerState<BagianBantuan> {
  List<TiketBantuan>? _tiket;
  String? _pesan;
  bool _sibuk = false;

  String? get _uuidStaf => ref.read(penyediaSesi).kasir?.uuid;

  String _PesanGalat(Object galat) =>
      galat is GalatApi ? galat.pesan : 'Perlu internet. Periksa koneksi lalu coba lagi.';

  Future<void> _Muat() async {
    final uuid = _uuidStaf;
    if (uuid == null) {
      return;
    }
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    try {
      final daftar = await ref.read(penyediaKlienPos).DaftarTiketBantuan(uuid);
      if (mounted) setState(() => _tiket = daftar);
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _pesan = _PesanGalat(galat));
    } on GalatJaringan catch (galat) {
      if (mounted) setState(() => _pesan = _PesanGalat(galat));
    } finally {
      if (mounted) setState(() => _sibuk = false);
    }
  }

  Future<void> _Buat() async {
    final tiket = await showDialog<TiketBantuan>(context: context, builder: (_) => const _DialogTiketBaru());
    if (tiket != null) {
      unawaited(_Muat());
    }
  }

  Future<void> _Buka(TiketBantuan tiket) async {
    await showDialog<void>(
      context: context,
      builder: (_) => _DialogPercakapan(ringkas: tiket),
    );
    unawaited(_Muat());
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final daftar = _tiket;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            FilledButton.icon(
              key: const ValueKey('BukaTiketBantuan'),
              onPressed: _uuidStaf == null ? null : () => unawaited(_Buat()),
              icon: const Icon(Icons.support_agent_outlined),
              label: const Text('Hubungi bantuan'),
            ),
            OutlinedButton(
              onPressed: _sibuk ? null : () => unawaited(_Muat()),
              child: Text(_sibuk ? 'Memuat…' : 'Segarkan'),
            ),
          ],
        ),
        if (_pesan case final pesan?) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Text(pesan, style: teks.bodySmall?.copyWith(color: warna.bahaya)),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        if (daftar == null)
          Text('Ketuk Segarkan untuk melihat tiket Anda.', style: teks.bodySmall)
        else if (daftar.isEmpty)
          Text('Belum ada tiket dari Anda.', style: teks.bodySmall)
        else
          for (final t in daftar)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('${t.nomor} | ${t.judul}'),
              subtitle: Text(
                TeksSlaTiket(t),
                style: TextStyle(color: t.lewatSla && t.terbuka ? warna.bahaya : warna.teksSekunder),
              ),
              trailing: t.menungguBalasan
                  ? const Icon(Icons.mark_chat_unread_outlined)
                  : const Icon(Icons.chevron_right),
              onTap: () => unawaited(_Buka(t)),
            ),
      ],
    );
  }
}

/// Formulir tiket baru. Log perangkat terbaru (maks. 15 baris) ikut bila dicentang.
class _DialogTiketBaru extends ConsumerStatefulWidget {
  const _DialogTiketBaru();

  @override
  ConsumerState<_DialogTiketBaru> createState() => _KeadaanDialogTiketBaru();
}

class _KeadaanDialogTiketBaru extends ConsumerState<_DialogTiketBaru> {
  final TextEditingController _judul = TextEditingController();
  final TextEditingController _isi = TextEditingController();
  KategoriBantuan _kategori = KategoriBantuan.Perangkat;
  bool _sertakanLog = true;
  bool _mengirim = false;
  String? _galat;

  @override
  void dispose() {
    _judul.dispose();
    _isi.dispose();
    super.dispose();
  }

  Future<List<String>> _AmbilLog() async {
    if (!_sertakanLog) {
      return const [];
    }
    final entri = await ref.read(penyediaLogLokal)?.AmbilTerbaru(batas: 15) ?? const <EntriLog>[];
    return [
      for (final e in entri.reversed)
        '${FormatWaktu.FormatTanggalJam(e.waktu.toLocal())} ${e.tingkat.nilai} ${e.sumber}: ${e.pesan}',
    ];
  }

  Future<void> _Kirim() async {
    final uuid = ref.read(penyediaSesi).kasir?.uuid;
    final judul = _judul.text.trim();
    final isi = _isi.text.trim();
    if (uuid == null) {
      setState(() => _galat = 'Masuk sebagai kasir dulu.');
      return;
    }
    if (judul.length < 5) {
      setState(() => _galat = 'Judul minimal 5 huruf.');
      return;
    }
    if (isi.length < 10) {
      setState(() => _galat = 'Ceritakan masalahnya minimal 10 huruf supaya tim bisa membantu.');
      return;
    }
    setState(() {
      _mengirim = true;
      _galat = null;
    });
    try {
      final tiket = await ref
          .read(penyediaKlienPos)
          .BuatTiketBantuan(
            uuidPengguna: uuid,
            kategori: _kategori.kunci,
            judul: judul,
            isi: isi,
            layarAsal: 'Pengaturan',
            logSingkat: await _AmbilLog(),
          );
      if (mounted) Navigator.of(context).pop(tiket);
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _galat = galat.pesan);
    } on GalatJaringan {
      if (mounted) setState(() => _galat = 'Perlu internet. Periksa koneksi lalu coba lagi.');
    } finally {
      if (mounted) setState(() => _mengirim = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return AlertDialog(
      title: const Text('Hubungi bantuan'),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                spacing: TokenJarak.jarak8,
                runSpacing: TokenJarak.jarak8,
                children: [
                  for (final k in KategoriBantuan.values)
                    ChoiceChip(
                      label: Text(k.label),
                      selected: _kategori == k,
                      onSelected: (_) => setState(() => _kategori = k),
                    ),
                ],
              ),
              const SizedBox(height: TokenJarak.jarak12),
              TextField(
                controller: _judul,
                maxLength: 150,
                decoration: const InputDecoration(labelText: 'Judul', border: OutlineInputBorder()),
              ),
              const SizedBox(height: TokenJarak.jarak8),
              TextField(
                controller: _isi,
                maxLength: 10000,
                minLines: 3,
                maxLines: 6,
                decoration: const InputDecoration(
                  labelText: 'Apa yang terjadi?',
                  alignLabelWithHint: true,
                  border: OutlineInputBorder(),
                ),
              ),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: _sertakanLog,
                onChanged: (nilai) => setState(() => _sertakanLog = nilai ?? false),
                title: const Text('Sertakan log perangkat terbaru'),
                subtitle: const Text('Tanpa data pribadi. Membantu tim menemukan penyebabnya lebih cepat.'),
              ),
              if (_galat != null)
                Padding(
                  padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                  child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
                ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _mengirim ? null : () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(onPressed: _mengirim ? null : _Kirim, child: Text(_mengirim ? 'Mengirim…' : 'Kirim tiket')),
      ],
    );
  }
}

/// Percakapan satu tiket milik staf yang sedang masuk, dengan kotak balasan.
class _DialogPercakapan extends ConsumerStatefulWidget {
  const _DialogPercakapan({required this.ringkas});

  final TiketBantuan ringkas;

  @override
  ConsumerState<_DialogPercakapan> createState() => _KeadaanDialogPercakapan();
}

class _KeadaanDialogPercakapan extends ConsumerState<_DialogPercakapan> {
  final TextEditingController _balasan = TextEditingController();
  TiketBantuan? _tiket;
  String? _galat;
  bool _sibuk = false;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  @override
  void dispose() {
    _balasan.dispose();
    super.dispose();
  }

  Future<void> _Muat() async {
    final uuid = ref.read(penyediaSesi).kasir?.uuid;
    if (uuid == null) {
      return;
    }
    try {
      final tiket = await ref.read(penyediaKlienPos).AmbilTiketBantuan(widget.ringkas.uuid, uuid);
      if (mounted) setState(() => _tiket = tiket);
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _galat = galat.pesan);
    } on GalatJaringan {
      if (mounted) setState(() => _galat = 'Perlu internet. Periksa koneksi lalu coba lagi.');
    }
  }

  Future<void> _Balas() async {
    final uuid = ref.read(penyediaSesi).kasir?.uuid;
    final isi = _balasan.text.trim();
    if (uuid == null || isi.length < 2) {
      setState(() => _galat = 'Tulis balasan dulu.');
      return;
    }
    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final tiket = await ref.read(penyediaKlienPos).BalasTiketBantuan(widget.ringkas.uuid, uuid, isi);
      _balasan.clear();
      if (mounted) setState(() => _tiket = tiket);
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _galat = galat.pesan);
    } on GalatJaringan {
      if (mounted) setState(() => _galat = 'Perlu internet. Periksa koneksi lalu coba lagi.');
    } finally {
      if (mounted) setState(() => _sibuk = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tiket = _tiket ?? widget.ringkas;
    return AlertDialog(
      title: Text('${tiket.nomor} | ${tiket.judul}'),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(TeksSlaTiket(tiket), style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
              const SizedBox(height: TokenJarak.jarak12),
              if (_tiket == null && _galat == null) const Center(child: TandaMuat(ukuran: 40)),
              for (final p in tiket.pesan)
                Container(
                  margin: const EdgeInsets.only(bottom: TokenJarak.jarak8),
                  padding: const EdgeInsets.all(TokenJarak.jarak12),
                  decoration: BoxDecoration(
                    color: p.dariTim ? warna.permukaan : warna.latar,
                    border: Border.all(color: warna.garis),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        [
                          p.namaPengirim,
                          if (p.dibuatPada != null) FormatWaktu.FormatTanggalJam(p.dibuatPada!),
                        ].join(' | '),
                        style: teks.labelMedium?.copyWith(color: warna.teksSekunder),
                      ),
                      Text(p.isi),
                    ],
                  ),
                ),
              if (_tiket?.bisaDibalas ?? false)
                TextField(
                  controller: _balasan,
                  minLines: 2,
                  maxLines: 5,
                  decoration: const InputDecoration(
                    labelText: 'Balas',
                    alignLabelWithHint: true,
                    border: OutlineInputBorder(),
                  ),
                ),
              if (_galat != null)
                Padding(
                  padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                  child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
                ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Tutup')),
        if (_tiket?.bisaDibalas ?? false)
          FilledButton(onPressed: _sibuk ? null : _Balas, child: Text(_sibuk ? 'Mengirim…' : 'Kirim balasan')),
      ],
    );
  }
}
