import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Perangkat/IsiAktivasi.dart';
import 'Komponen/BingkaiLogin.dart';

/// F-02 langkah 5: tukar kode aktivasi dari back-office (menu Perangkat) menjadi token perangkat. Butuh internet.
class LayarAktivasi extends ConsumerStatefulWidget {
  const LayarAktivasi({super.key, this.pesan});

  final String? pesan;

  @override
  ConsumerState<LayarAktivasi> createState() => _LayarAktivasiState();
}

class _LayarAktivasiState extends ConsumerState<LayarAktivasi> {
  final _kode = TextEditingController();
  final _alamat = TextEditingController();
  bool _sibuk = false;
  String? _galat;
  String? _galatAlamat;

  /// D-35: isian alamat server toko sendiri (edisi Lisensi) terbuka bila sudah pernah diisi atau dibuka kasir.
  late bool _pakaiServerSendiri;

  @override
  void initState() {
    super.initState();
    final tersimpan = ref.read(penyediaAlamatServer);
    _pakaiServerSendiri = tersimpan != null;
    _alamat.text = tersimpan?.toString() ?? '';
  }

  @override
  void dispose() {
    _kode.dispose();
    _alamat.dispose();
    super.dispose();
  }

  Future<void> _Pindai() async {
    final pemindai = ref.read(penyediaPemindaiQr);
    final hasil = await pemindai.Pindai(context);
    if (hasil == null || !mounted) {
      return;
    }
    // Isi QR = kode aktivasi apa adanya (PembuatQrKodeAktivasi); edisi Lisensi menyertakan alamat server toko.
    final isi = IsiAktivasi.Uraikan(hasil);
    _kode.text = isi.kode;
    if (isi.alamatServer case final Uri alamat) {
      setState(() => _pakaiServerSendiri = true);
      _alamat.text = alamat.toString();
    }
    await _Aktifkan();
  }

  Future<void> _Aktifkan() async {
    Uri? alamatServer;
    if (_pakaiServerSendiri) {
      alamatServer = IsiAktivasi.NormalkanAlamat(_alamat.text);
      if (alamatServer == null) {
        setState(() => _galatAlamat = 'Isi alamat server toko, misal https://kasir.tokoanda.com');
        return;
      }
    }
    setState(() {
      _sibuk = true;
      _galat = null;
      _galatAlamat = null;
    });
    try {
      await ref.read(penyediaSesi.notifier).Aktifkan(_kode.text, alamatServer: alamatServer);
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    // Windows & perangkat tanpa kamera: isian manual saja (mobile_scanner tidak mendukung Windows).
    final adaPemindai = ref.watch(penyediaPemindaiQr).CekTersedia();
    final keterangan = adaPemindai
        ? 'Buka back-office, menu Perangkat, lalu buat kode aktivasi untuk perangkat ini. Pindai QR-nya atau ketik kodenya.'
        : 'Buka back-office, menu Perangkat, lalu buat kode aktivasi untuk perangkat ini.';
    return BingkaiLogin(
      isi: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Aktifkan perangkat kasir', style: teks.headlineMedium),
          const SizedBox(height: TokenJarak.jarak8),
          Text(keterangan, style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
          const SizedBox(height: TokenJarak.jarak24),
          if (widget.pesan case final String pesan) ...[
            _Peringatan(pesan: pesan),
            const SizedBox(height: TokenJarak.jarak16),
          ],
          if (adaPemindai) ...[
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton.icon(
                onPressed: _sibuk ? null : _Pindai,
                icon: const Icon(Icons.qr_code_scanner_outlined),
                label: const Text('Pindai kode QR'),
              ),
            ),
            const SizedBox(height: TokenJarak.jarak16),
            Row(
              children: [
                Expanded(child: Divider(color: warna.garis)),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
                  child: Text('atau ketik kodenya', style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                ),
                Expanded(child: Divider(color: warna.garis)),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak16),
          ],
          TextField(
            controller: _kode,
            textCapitalization: TextCapitalization.characters,
            autofocus: !adaPemindai,
            decoration: InputDecoration(labelText: 'Kode aktivasi', errorText: _galat),
            onSubmitted: (_) => _Aktifkan(),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          if (_pakaiServerSendiri)
            TextField(
              key: const ValueKey('AlamatServer'),
              controller: _alamat,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: InputDecoration(
                labelText: 'Alamat server toko',
                hintText: 'https://kasir.tokoanda.com',
                helperText: 'Untuk toko yang memasang Payoung di server sendiri.',
                errorText: _galatAlamat,
              ),
              onSubmitted: (_) => _Aktifkan(),
            )
          else
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _sibuk ? null : () => setState(() => _pakaiServerSendiri = true),
                child: const Text('Toko memakai server sendiri?'),
              ),
            ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: FilledButton(
              onPressed: _sibuk ? null : _Aktifkan,
              child: Text(_sibuk ? 'Mengaktifkan…' : 'Aktifkan perangkat'),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak16),
          Text(
            'Aktivasi butuh internet satu kali. Setelah aktif, kasir bisa berjualan tanpa internet.',
            textAlign: TextAlign.center,
            style: teks.bodySmall,
          ),
          // Orang yang mengunduh aplikasi lebih dulu belum punya akun atau kode aktivasi: arahkan ke situs pemasaran.
          // Toko berserver sendiri tidak mendaftar lewat situs Payoung, jadi tautannya disembunyikan.
          if (!_pakaiServerSendiri) ...[const SizedBox(height: TokenJarak.jarak8), const TautanSitusPemasaran()],
        ],
      ),
    );
  }
}

/// Pesan mengapa perangkat kembali ke layar aktivasi (mis. token dicabut): ditandai warna **dan** ikon, supaya
/// tetap terbaca tanpa warna (PRD §17.6.11).
class _Peringatan extends StatelessWidget {
  const _Peringatan({required this.pesan});

  final String pesan;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.all(TokenJarak.jarak12),
      decoration: BoxDecoration(
        color: warna.bahaya.withValues(alpha: 0.08),
        border: Border.all(color: warna.bahaya),
        borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.error_outline, size: TokenJarak.ikonSedang, color: warna.bahaya),
          const SizedBox(width: TokenJarak.jarak8),
          Expanded(
            child: Text(pesan, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
          ),
        ],
      ),
    );
  }
}
