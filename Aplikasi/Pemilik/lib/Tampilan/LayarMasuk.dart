import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import 'package:klien_api/KlienApi.dart';

import '../Aplikasi/Penyedia.dart';

/// Masuk OWN-01: email + kata sandi akun back-office; bila 2FA aktif, lanjut ke kode dari aplikasi autentikator.
class LayarMasuk extends ConsumerStatefulWidget {
  const LayarMasuk({super.key});

  @override
  ConsumerState<LayarMasuk> createState() => _LayarMasukState();
}

class _LayarMasukState extends ConsumerState<LayarMasuk> {
  final _email = TextEditingController();
  final _sandi = TextEditingController();
  final _kode = TextEditingController();
  final _alamat = TextEditingController();
  bool _sandiTerlihat = false;
  bool _mengirimLupaSandi = false;
  String? _galatAlamat;

  /// D-35: isian alamat server toko sendiri (edisi Lisensi) terbuka bila sudah pernah diisi atau dibuka pemilik.
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
    _email.dispose();
    _sandi.dispose();
    _kode.dispose();
    _alamat.dispose();
    super.dispose();
  }

  Future<void> _Masuk() async {
    Uri? alamatServer;
    if (_pakaiServerSendiri) {
      alamatServer = NormalkanAlamatServer(_alamat.text);
      if (alamatServer == null) {
        setState(() => _galatAlamat = 'Isi alamat server toko, misal https://kasir.tokoanda.com');
        return;
      }
    }
    setState(() => _galatAlamat = null);
    await ref.read(penyediaSesi.notifier).Masuk(_email.text, _sandi.text, alamatServer: alamatServer);
  }

  /// Kirim tautan atur ulang kata sandi ke email yang sudah diketik; jawaban server tampil apa adanya.
  Future<void> _LupaKataSandi() async {
    final email = _email.text.trim();
    final pesan = ScaffoldMessenger.of(context);
    if (email.isEmpty) {
      pesan.showSnackBar(const SnackBar(content: Text('Isi email akun Anda dulu, lalu ketuk Lupa kata sandi.')));
      return;
    }
    setState(() => _mengirimLupaSandi = true);
    try {
      final jawaban = await ref.read(penyediaKlien).LupaKataSandi(email);
      pesan.showSnackBar(SnackBar(content: Text(jawaban)));
    } on GalatApi catch (galat) {
      pesan.showSnackBar(SnackBar(content: Text(galat.pesan)));
    } on GalatJaringan {
      pesan.showSnackBar(const SnackBar(content: Text('Tidak ada koneksi internet. Coba lagi saat perangkat online.')));
    } finally {
      if (mounted) {
        setState(() => _mengirimLupaSandi = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesi = ref.watch(penyediaSesi);
    final notifier = ref.read(penyediaSesi.notifier);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final duaFaktor = sesi.tahap == TahapSesi.DuaFaktor;
    final clientIdGoogle = ref.watch(penyediaClientIdGoogle).asData?.value;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(TokenJarak.jarak24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Center(child: LogoMerek.lengkap()),
                    const SizedBox(height: TokenJarak.jarak24),
                    Text(duaFaktor ? 'Verifikasi dua langkah' : 'Masuk ke Payoung Owner', style: teks.headlineSmall),
                    const SizedBox(height: TokenJarak.jarak8),
                    Text(
                      duaFaktor
                          ? 'Masukkan 6 angka dari aplikasi autentikator Anda, atau kode pemulihan.'
                          : 'Pakai email dan kata sandi akun back-office.',
                      style: teks.bodyMedium?.copyWith(color: warna.teksSekunder),
                    ),
                    const SizedBox(height: TokenJarak.jarak16),
                    if (duaFaktor)
                      TextField(
                        controller: _kode,
                        keyboardType: TextInputType.number,
                        autofillHints: const [AutofillHints.oneTimeCode],
                        decoration: const InputDecoration(labelText: 'Kode verifikasi'),
                        onSubmitted: (_) => unawaited(notifier.KonfirmasiDuaFaktor(_kode.text)),
                      )
                    else ...[
                      TextField(
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.email],
                        decoration: const InputDecoration(labelText: 'Email'),
                      ),
                      const SizedBox(height: TokenJarak.jarak12),
                      TextField(
                        controller: _sandi,
                        obscureText: !_sandiTerlihat,
                        autofillHints: const [AutofillHints.password],
                        decoration: InputDecoration(
                          labelText: 'Kata sandi',
                          // Tombol mata: target sentuh IconButton sudah 48dp (§17.6.4).
                          suffixIcon: IconButton(
                            onPressed: () => setState(() => _sandiTerlihat = !_sandiTerlihat),
                            icon: Icon(_sandiTerlihat ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                            tooltip: _sandiTerlihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi',
                          ),
                        ),
                        onSubmitted: (_) => unawaited(_Masuk()),
                      ),
                      Align(
                        alignment: Alignment.centerRight,
                        child: TextButton(
                          key: const ValueKey('LupaKataSandi'),
                          onPressed: sesi.sibuk || _mengirimLupaSandi ? null : () => unawaited(_LupaKataSandi()),
                          child: Text(_mengirimLupaSandi ? 'Mengirim…' : 'Lupa kata sandi?'),
                        ),
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
                          onSubmitted: (_) => unawaited(_Masuk()),
                        )
                      else
                        Align(
                          alignment: Alignment.centerLeft,
                          child: TextButton(
                            onPressed: sesi.sibuk ? null : () => setState(() => _pakaiServerSendiri = true),
                            child: const Text('Toko memakai server sendiri?'),
                          ),
                        ),
                    ],
                    if (sesi.pesan != null) ...[
                      const SizedBox(height: TokenJarak.jarak12),
                      Text(sesi.pesan!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
                    ],
                    const SizedBox(height: TokenJarak.jarak16),
                    SizedBox(
                      height: TokenJarak.targetSentuh,
                      child: FilledButton(
                        onPressed: sesi.sibuk
                            ? null
                            : () => unawaited(duaFaktor ? notifier.KonfirmasiDuaFaktor(_kode.text) : _Masuk()),
                        child: Text(sesi.sibuk ? 'Memproses…' : (duaFaktor ? 'Verifikasi' : 'Masuk')),
                      ),
                    ),
                    if (!duaFaktor && clientIdGoogle != null) ...[
                      const SizedBox(height: TokenJarak.jarak12),
                      SizedBox(
                        height: TokenJarak.targetSentuh,
                        child: OutlinedButton.icon(
                          key: const ValueKey('MasukGoogle'),
                          onPressed: sesi.sibuk ? null : () => unawaited(notifier.MasukGoogle(clientIdGoogle)),
                          icon: Image.asset('assets/Google.png', width: 20, height: 20, excludeFromSemantics: true),
                          label: const Text('Masuk dengan Google'),
                        ),
                      ),
                      const SizedBox(height: TokenJarak.jarak8),
                      Text(
                        'Akun Google menggantikan kode verifikasi dua langkah.',
                        textAlign: TextAlign.center,
                        style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
