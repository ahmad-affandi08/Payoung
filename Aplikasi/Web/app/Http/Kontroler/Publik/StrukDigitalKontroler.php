<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Aksi\KirimUmpanBalikTransaksi;
use App\Domain\Penjualan\Kueri\StrukDigitalPenjualan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Struk digital publik `/s/{kodeStruk}` (POS-11), tanpa login. Tenant diambil dari kode lalu dipasang sebagai konteks
 * (seperti device token) sehingga pencarian penjualan tetap lewat scope `MilikTenant`. Struk yang belum tersinkron,
 * dimatikan, atau tidak dikenal menampilkan halaman "belum tersedia" (404).
 */
final class StrukDigitalKontroler extends Kontroler
{
    public function Tampilkan(string $kodeStruk, KonteksTenant $konteks, ProfilTenant $profil, StrukDigitalPenjualan $kueri): SymfonyResponse
    {
        $kode = KodeStrukDigital::Urai($kodeStruk);
        $struk = null;

        if ($kode !== null && $profil->CekAda($kode['IdTenant'])) {
            $konteks->Atur($kode['IdTenant']);

            try {
                $struk = $kueri->Ambil($kode['IdTenant'], $kode['Uuid']);
            } finally {
                $konteks->Kosongkan();
            }
        }

        return Inertia::render('Publik/StrukDigital', ['Struk' => $struk])
            ->toResponse(request())
            ->setStatusCode($struk === null ? 404 : 200);
    }

    /**
     * F7 (CRM-08): penilaian 1–5 dan komentar opsional dari struk digital. Tenant dipasang dari kode seperti pada
     * `Tampilkan`; satu penilaian per transaksi, kiriman kedua dijawab dengan halaman yang sama (menampilkan penilaian
     * yang tersimpan) tanpa menimpa.
     */
    public function KirimUmpanBalik(string $kodeStruk, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, KirimUmpanBalikTransaksi $kirim): RedirectResponse
    {
        $kode = KodeStrukDigital::Urai($kodeStruk);

        if ($kode === null || ! $profil->CekAda($kode['IdTenant'])) {
            abort(404);
        }

        $data = $permintaan->validate([
            'Nilai' => ['required', 'integer', 'between:1,5'],
            'Komentar' => ['nullable', 'string', 'max:'.KirimUmpanBalikTransaksi::PANJANG_KOMENTAR],
        ]);
        $konteks->Atur($kode['IdTenant']);

        try {
            $kirim->Jalankan($kode['Uuid'], (int) $data['Nilai'], isset($data['Komentar']) ? (string) $data['Komentar'] : null);
        } catch (PelanggaranAturanBisnis $galat) {
            if ($galat->kode === 'StrukTidakDitemukan') {
                abort(404);
            }

            if ($galat->kode !== 'UmpanBalikSudahAda') {
                return redirect()->route('publik.struk-digital', $kodeStruk)->withErrors(['Umum' => $galat->getMessage()]);
            }
        } finally {
            $konteks->Kosongkan();
        }

        return redirect()->route('publik.struk-digital', $kodeStruk);
    }
}
