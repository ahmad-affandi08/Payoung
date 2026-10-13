import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import 'FormatTampilan.dart';
import 'KeadaanData.dart';

/// Teks batas respons SLA: "Respons paling lambat 24 Sep 17.00 (jam kerja)"; tiket yang sudah direspons atau lewat SLA
/// diberi kalimat sendiri supaya tidak berjanji dua kali.
String TeksBatasSla(TiketBantuan t) {
  if (!t.terbuka) {
    return t.labelStatus;
  }
  if (t.responsPertamaPada != null) {
    return '${t.labelStatus} | sudah direspons ${FormatTampilan.TanggalJam(t.responsPertamaPada!)}';
  }
  final batas = t.batasSlaPada;
  if (batas == null) {
    return t.labelStatus;
  }
  final jenis = t.slaJamKerja ? 'jam kerja' : 'jam kalender';
  return t.lewatSla
      ? '${t.labelStatus} | batas respons ${FormatTampilan.TanggalJam(batas)} terlewati, tim sedang dikejar'
      : '${t.labelStatus} | respons paling lambat ${FormatTampilan.TanggalJam(batas)} ($jenis)';
}

/// P-09: Bantuan di Aplikasi Owner. Daftar tiket tenant, buat tiket (konteks aplikasi, platform, dan versi ikut
/// otomatis), dan buka percakapan. Tarik layar untuk menyegarkan.
class LayarBantuan extends ConsumerWidget {
  const LayarBantuan({required this.layarAsal, super.key});

  /// Nama layar tempat tombol Bantuan ditekan; dikirim sebagai konteks tiket.
  final String layarAsal;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Bantuan')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => unawaited(_Buat(context, ref)),
        icon: const Icon(Icons.add),
        label: const Text('Buat tiket'),
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(penyediaTiketBantuan.future),
        child: KeadaanData(
          nilai: ref.watch(penyediaTiketBantuan),
          saatCobaLagi: () => ref.invalidate(penyediaTiketBantuan),
          isi: (daftar) => ListView(
            padding: const EdgeInsets.all(TokenJarak.jarak16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              if (daftar.isEmpty)
                const KotakPanel(
                  anak: KeadaanKosong(
                    ilustrasi: IlustrasiKosong.Umum,
                    ikon: Icons.support_agent_outlined,
                    judul: 'Belum ada tiket bantuan.',
                    keterangan: 'Ketuk Buat tiket untuk menghubungi tim Payoung. Balasan muncul di sini.',
                  ),
                ),
              for (final t in daftar)
                Card(
                  margin: const EdgeInsets.only(bottom: TokenJarak.jarak12),
                  child: ListTile(
                    title: Text('${t.nomor} | ${t.judul}'),
                    subtitle: Text(
                      TeksBatasSla(t),
                      style: TextStyle(color: t.lewatSla && t.terbuka ? warna.bahaya : warna.teksSekunder),
                    ),
                    trailing: t.menungguBalasan
                        ? const Icon(Icons.mark_chat_unread_outlined)
                        : const Icon(Icons.chevron_right),
                    onTap: () => unawaited(
                      Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => LayarTiketBantuan(uuid: t.uuid, nomor: t.nomor),
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _Buat(BuildContext context, WidgetRef ref) async {
    final tiket = await showModalBottomSheet<TiketBantuan>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FormTiketBantuan(layarAsal: layarAsal),
    );
    if (tiket != null) {
      ref.invalidate(penyediaTiketBantuan);
    }
  }
}

/// Formulir tiket baru: kategori, judul, dan uraian. Konteks (aplikasi, platform, versi, layar asal) dilengkapi sendiri.
class FormTiketBantuan extends ConsumerStatefulWidget {
  const FormTiketBantuan({required this.layarAsal, super.key});

  final String layarAsal;

  @override
  ConsumerState<FormTiketBantuan> createState() => _KeadaanFormTiketBantuan();
}

class _KeadaanFormTiketBantuan extends ConsumerState<FormTiketBantuan> {
  final TextEditingController _judul = TextEditingController();
  final TextEditingController _isi = TextEditingController();
  KategoriBantuan _kategori = KategoriBantuan.Lainnya;
  bool _mengirim = false;
  String? _galat;

  @override
  void dispose() {
    _judul.dispose();
    _isi.dispose();
    super.dispose();
  }

  Future<void> _Kirim() async {
    final judul = _judul.text.trim();
    final isi = _isi.text.trim();
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
          .read(penyediaKlien)
          .BuatTiketBantuan(kategori: _kategori.kunci, judul: judul, isi: isi, layarAsal: widget.layarAsal);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Tiket ${tiket.nomor} terkirim.')));
        Navigator.of(context).pop(tiket);
      }
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _galat = galat.pesan);
    } on GalatJaringan {
      if (mounted) setState(() => _galat = 'Tidak tersambung ke server. Periksa internet lalu coba lagi.');
    } finally {
      if (mounted) setState(() => _mengirim = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(
        TokenJarak.jarak16,
        TokenJarak.jarak16,
        TokenJarak.jarak16,
        MediaQuery.viewInsetsOf(context).bottom + TokenJarak.jarak16,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Buat tiket bantuan', style: teks.titleLarge),
            const SizedBox(height: TokenJarak.jarak12),
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
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(labelText: 'Judul', border: OutlineInputBorder()),
            ),
            const SizedBox(height: TokenJarak.jarak8),
            TextField(
              controller: _isi,
              maxLength: 10000,
              minLines: 4,
              maxLines: 8,
              decoration: const InputDecoration(
                labelText: 'Apa yang terjadi?',
                alignLabelWithHint: true,
                border: OutlineInputBorder(),
              ),
            ),
            if (_galat != null)
              Padding(
                padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
                child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
              ),
            SizedBox(
              height: 56,
              child: FilledButton(
                onPressed: _mengirim ? null : _Kirim,
                child: Text(_mengirim ? 'Mengirim...' : 'Kirim tiket'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Percakapan satu tiket dan kotak balasan. Catatan internal tim tidak pernah sampai ke aplikasi.
class LayarTiketBantuan extends ConsumerStatefulWidget {
  const LayarTiketBantuan({required this.uuid, required this.nomor, super.key});

  final String uuid;
  final String nomor;

  @override
  ConsumerState<LayarTiketBantuan> createState() => _KeadaanLayarTiketBantuan();
}

class _KeadaanLayarTiketBantuan extends ConsumerState<LayarTiketBantuan> {
  final TextEditingController _balasan = TextEditingController();
  bool _mengirim = false;
  String? _galat;

  @override
  void dispose() {
    _balasan.dispose();
    super.dispose();
  }

  Future<void> _Balas() async {
    final isi = _balasan.text.trim();
    if (isi.length < 2) {
      setState(() => _galat = 'Tulis balasan dulu.');
      return;
    }
    setState(() {
      _mengirim = true;
      _galat = null;
    });
    try {
      await ref.read(penyediaKlien).BalasTiketBantuan(widget.uuid, isi);
      _balasan.clear();
      ref
        ..invalidate(penyediaDetailTiketBantuan(widget.uuid))
        ..invalidate(penyediaTiketBantuan);
    } on GalatApi catch (galat) {
      if (mounted) setState(() => _galat = galat.pesan);
    } on GalatJaringan {
      if (mounted) setState(() => _galat = 'Tidak tersambung ke server. Periksa internet lalu coba lagi.');
    } finally {
      if (mounted) setState(() => _mengirim = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(title: Text(widget.nomor)),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(penyediaDetailTiketBantuan(widget.uuid).future),
        child: KeadaanData(
          nilai: ref.watch(penyediaDetailTiketBantuan(widget.uuid)),
          saatCobaLagi: () => ref.invalidate(penyediaDetailTiketBantuan(widget.uuid)),
          isi: (tiket) => ListView(
            padding: const EdgeInsets.all(TokenJarak.jarak16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              Text(tiket.judul, style: teks.titleMedium),
              const SizedBox(height: TokenJarak.jarak4),
              Text(TeksBatasSla(tiket), style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
              const SizedBox(height: TokenJarak.jarak16),
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
                          if (p.dibuatPada != null) FormatTampilan.TanggalJam(p.dibuatPada!),
                        ].join(' | '),
                        style: teks.labelMedium?.copyWith(color: warna.teksSekunder),
                      ),
                      const SizedBox(height: TokenJarak.jarak4),
                      SelectableText(p.isi),
                    ],
                  ),
                ),
              if (tiket.bisaDibalas) ...[
                const SizedBox(height: TokenJarak.jarak8),
                TextField(
                  controller: _balasan,
                  minLines: 2,
                  maxLines: 6,
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
                const SizedBox(height: TokenJarak.jarak8),
                SizedBox(
                  height: 56,
                  child: FilledButton(
                    onPressed: _mengirim ? null : _Balas,
                    child: Text(_mengirim ? 'Mengirim...' : 'Kirim balasan'),
                  ),
                ),
              ] else
                Text('Tiket ini sudah ditutup. Buat tiket baru bila masalahnya muncul lagi.', style: teks.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
