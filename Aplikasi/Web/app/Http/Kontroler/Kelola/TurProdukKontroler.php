<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Organisasi\Aksi\CatatTurProduk;
use App\Domain\Organisasi\Aksi\UlangiTurProduk;
use App\Domain\Organisasi\Enum\KodeTurProduk;
use App\Domain\Organisasi\Enum\StatusTurProduk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tur produk interaktif (PLT-12): mencatat hasil tur bagi pengguna yang sedang masuk. Hanya data pribadi pelaku sendiri,
 * jadi tanpa izin tenant khusus. Kode tur di luar daftar `KodeTurProduk` dijawab 404.
 */
final class TurProdukKontroler extends DasarKelolaKontroler
{
    public function Catat(Request $permintaan, string $kodeTur, CatatTurProduk $catat): RedirectResponse
    {
        $kode = KodeTurProduk::tryFrom($kodeTur) ?? abort(404);
        $data = $permintaan->validate(['Status' => ['required', 'string', Rule::enum(StatusTurProduk::class)]]);
        $catat->Jalankan($this->Pelaku()->Id, $kode, StatusTurProduk::from((string) $data['Status']));

        return back();
    }

    public function Ulangi(string $kodeTur, UlangiTurProduk $ulangi): RedirectResponse
    {
        $kode = KodeTurProduk::tryFrom($kodeTur) ?? abort(404);
        $ulangi->Jalankan($this->Pelaku()->Id, $kode);

        return back();
    }
}
