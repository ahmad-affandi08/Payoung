<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembayaran;

use App\Domain\Integrasi\Aksi\AjukanPendaftaranMerchant;
use App\Domain\Integrasi\Aksi\BatalkanPendaftaranMerchant;
use App\Domain\Integrasi\Aksi\SimpanDraftPendaftaranMerchant;
use App\Domain\Integrasi\Kueri\HalamanAktivasiQris;
use App\Domain\Organisasi\Model\Pengguna;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pembayaran\SimpanPendaftaranMerchantPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aktivasi QRIS otomatis (DOKU Partner API): tenant memberi foto KTP, selfie, foto tempat usaha, dan rekening; Payoung
 * mendaftarkannya sebagai merchant di DOKU. Izin `pembayaran.gerbang.atur`. Foto tidak disimpan permanen.
 */
final class AktivasiQrisKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(Request $permintaan, HalamanAktivasiQris $kueri): Response
    {
        $pengguna = $permintaan->user();

        return Inertia::render('Kelola/Pembayaran/AktivasiQris', $kueri->Ambil(
            $pengguna instanceof Pengguna ? $pengguna->Nama : null,
            $pengguna instanceof Pengguna ? $pengguna->Email : null,
        ));
    }

    public function SimpanDraf(SimpanPendaftaranMerchantPermintaan $permintaan, SimpanDraftPendaftaranMerchant $simpan): RedirectResponse
    {
        $simpan->Jalankan($permintaan->AmbilData());

        return to_route('kelola.pembayaran.aktivasi-qris')->with('Kilat', 'Draf pendaftaran disimpan. Foto dihapus otomatis dalam 24 jam bila belum dikirim.');
    }

    public function Kirim(SimpanPendaftaranMerchantPermintaan $permintaan, SimpanDraftPendaftaranMerchant $simpan, AjukanPendaftaranMerchant $ajukan): RedirectResponse
    {
        $simpan->Jalankan($permintaan->AmbilData());
        $ajukan->Jalankan();

        return to_route('kelola.pembayaran.aktivasi-qris')->with('Kilat', 'Pendaftaran dikirim. Peninjauan DOKU biasanya 1 sampai 2 hari kerja. Statusnya tampil di halaman ini.');
    }

    public function Batal(BatalkanPendaftaranMerchant $batalkan): RedirectResponse
    {
        $batalkan->Jalankan();

        return to_route('kelola.pembayaran.aktivasi-qris')->with('Kilat', 'Pendaftaran dibatalkan dan data serta foto sementara dihapus.');
    }
}
