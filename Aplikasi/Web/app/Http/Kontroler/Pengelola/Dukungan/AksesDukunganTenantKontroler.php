<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Dukungan;

use App\Domain\Pengelola\Dukungan\Aksi\BukaAksesDarurat;
use App\Domain\Pengelola\Dukungan\Aksi\CabutAksesDaruratPengelola;
use App\Domain\Pengelola\Dukungan\Aksi\MulaiSesiAksesDukungan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Dukungan\BukaAksesDaruratPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Akses dukungan ke satu tenant dari konsol (PGL-16, P-09): "Masuk sebagai tenant" (izin `dukungan.akses.masuk`, hanya
 * bila Owner memberi izin), akses darurat dan penutupannya (izin `dukungan.akses.darurat`, Super Admin + 2FA baru).
 * Izin Owner tidak bisa dicabut dari sini.
 */
final class AksesDukunganTenantKontroler extends Kontroler
{
    use PelakuPengelola;

    /**
     * Mengembalikan alamat sekali pakai (60 detik); halaman membukanya di tab baru supaya konsol tetap terbuka.
     */
    public function Masuk(Request $permintaan, Tenant $tenant, MulaiSesiAksesDukungan $mulai): JsonResponse
    {
        $hasil = $mulai->Jalankan($this->AmbilPelaku(), $tenant->Id, $permintaan->ip());

        return response()->json($hasil);
    }

    public function Darurat(Tenant $tenant, BukaAksesDaruratPermintaan $isian, BukaAksesDarurat $buka): RedirectResponse
    {
        $buka->Jalankan(
            $this->AmbilPelaku(),
            $tenant->Id,
            $isian->AmbilKategori(),
            $isian->AmbilAlasan(),
            $isian->AmbilDurasiJam(),
            $isian->AmbilCakupan(),
        );

        return back()->with('Kilat', 'Akses darurat dibuka. Owner diberi tahu lewat email dan semua tindakan Anda tercatat.');
    }

    public function Cabut(Tenant $tenant, string $akses, CabutAksesDaruratPengelola $cabut): RedirectResponse
    {
        $cabut->Jalankan($this->AmbilPelaku(), $tenant->Id, $akses);

        return back()->with('Kilat', 'Akses darurat ditutup. Sesi yang berjalan berakhir seketika.');
    }
}
