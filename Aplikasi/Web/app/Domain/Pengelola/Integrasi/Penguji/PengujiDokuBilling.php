<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Penguji;

use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Integrasi\Snap\KlienSnap;
use App\Domain\Pengelola\Integrasi\Data\HasilUjiKoneksi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menguji Client ID dan secret key DOKU milik platform (P-05, P-08 langkah 3) dengan menanyakan status sebuah invoice
 * acak yang pasti tidak ada (`GET /orders/v1/status/{invoice}`): panggilan baca yang bertanda tangan, jadi tidak ada
 * transaksi palsu yang tercipta.
 *
 * DOKU memeriksa tanda tangan lebih dulu. Kredensial salah dijawab 401/403, sedangkan kredensial benar dengan invoice
 * tak dikenal dijawab 404 — jadi **404 justru bukti kredensial diterima**. Jawaban 5xx berarti DOKU sendiri bermasalah
 * dan kredensialnya belum terbukti, sehingga dilaporkan gagal.
 *
 * Akun ini milik Payoung untuk menagih tenant, terpisah dari gerbang QRIS milik toko yang akunnya milik tenant.
 * Pesan galat tidak pernah memuat secret key (BR-P05.6).
 */
final class PengujiDokuBilling implements PengujiKoneksi
{
    public function Uji(array $pengaturan, array $kredensial): HasilUjiKoneksi
    {
        $idKlien = trim((string) ($pengaturan['IdKlien'] ?? ''));
        $kunci = trim((string) ($kredensial['KunciRahasia'] ?? ''));

        if ($idKlien === '') {
            return HasilUjiKoneksi::Gagal('Client ID belum diisi.');
        }

        if ($kunci === '') {
            return HasilUjiKoneksi::Gagal('Secret key belum diisi.');
        }

        $produksi = ($pengaturan['Mode'] ?? 'Sandbox') === 'Produksi';
        $label = $produksi ? 'Produksi' : 'Sandbox';
        $target = '/orders/v1/status/UJI-'.Str::upper(Str::random(12));

        try {
            $respons = Http::acceptJson()->timeout(10)
                ->withHeaders(ProtokolDoku::BuatHeader($idKlien, $kunci, $target, null))
                ->get(ProtokolDoku::AmbilAlamatDasar(! $produksi).$target);
        } catch (Throwable $galat) {
            return HasilUjiKoneksi::Gagal('Tidak bisa menghubungi DOKU: '.PenyaringPesan::Saring($galat->getMessage(), ['KunciRahasia' => $kunci]));
        }

        if (in_array($respons->status(), [401, 403], true)) {
            return HasilUjiKoneksi::Gagal("Client ID atau secret key ditolak DOKU (tanda tangan tidak sah). Periksa apakah kuncinya disalin utuh dan sesuai lingkungan {$label}.");
        }

        if ($respons->serverError()) {
            return HasilUjiKoneksi::Gagal("DOKU menjawab status {$respons->status()}. Coba lagi sebentar lagi.");
        }

        // D-159: bila kunci SNAP sudah dibuat, tokennya ikut diuji. Hasilnya hanya keterangan: Checkout tidak boleh jadi
        // tak bisa diaktifkan hanya karena kunci publik SNAP belum sempat didaftarkan di DOKU.
        $kunciSnap = trim((string) ($kredensial['KunciPribadiSnap'] ?? ''));
        $keteranganSnap = $kunciSnap === ''
            ? ' SNAP: kunci belum dibuat.'
            : (($snap = KlienSnap::UjiTokenB2b($idKlien, $kunciSnap, ! $produksi))['Berhasil'] ? ' SNAP: '.$snap['Pesan'] : ' SNAP belum siap: '.$snap['Pesan']);

        return HasilUjiKoneksi::Berhasil("Client ID & secret key diterima DOKU (lingkungan {$label}).".$keteranganSnap);
    }
}
