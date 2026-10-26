<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Komunikasi;

use App\Domain\Pengelola\Komunikasi\Aksi\HentikanKontakProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\TautanBerhentiProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Berhenti menerima kampanye email Payoung (D-216, UU PDP): `/berhenti-prospek/{kontak}` dari tautan bertanda tangan di
 * email. Rutenya publik, tetapi controllernya di namespace Pengelola karena domain Pengelola hanya boleh dipakai dari
 * sana (`PengelolaTes`). GET hanya menampilkan konfirmasi (pemindai tautan di klien email tidak mengubah apa pun); POST
 * dengan tanda tangan yang sama menjalankan `HentikanKontakProspek`. POST juga dipakai pengaduan satu klik klien email
 * (`List-Unsubscribe-Post`, RFC 8058) yang tidak membawa token CSRF, jadi dilindungi tanda tangan saja. Tautan rusak
 * atau tidak dikenal → 404 tanpa membedakan alasannya. Alamat email tidak pernah ditampilkan (tautan bisa diteruskan).
 */
final class BerhentiProspekKontroler extends Kontroler
{
    public function Tampilkan(Request $permintaan, string $kontak): SymfonyResponse
    {
        $data = $this->Cari($permintaan, $kontak);

        if ($data === null) {
            return $this->TidakDitemukan($permintaan);
        }

        return Inertia::render('Publik/BerhentiProspek', [
            'Ditemukan' => true,
            'SudahBerhenti' => $data->Status !== StatusKontakProspek::Aktif,
            'AlamatKirim' => $permintaan->getRequestUri(),
        ])->toResponse($permintaan);
    }

    public function Kirim(Request $permintaan, string $kontak, HentikanKontakProspek $hentikan): SymfonyResponse
    {
        $data = $this->Cari($permintaan, $kontak);

        if ($data === null) {
            return $this->TidakDitemukan($permintaan);
        }

        $hentikan->Jalankan($data, StatusKontakProspek::Berhenti);

        return Inertia::render('Publik/BerhentiProspek', ['Ditemukan' => true, 'SudahBerhenti' => true, 'AlamatKirim' => null])->toResponse($permintaan);
    }

    private function Cari(Request $permintaan, string $kontak): ?KontakProspek
    {
        if (preg_match('/^'.TautanBerhentiProspek::POLA.'$/', $kontak) !== 1 || ! $permintaan->hasValidRelativeSignature()) {
            return null;
        }

        return KontakProspek::query()->where('Uuid', $kontak)->first();
    }

    private function TidakDitemukan(Request $permintaan): SymfonyResponse
    {
        return Inertia::render('Publik/BerhentiProspek', ['Ditemukan' => false, 'SudahBerhenti' => false, 'AlamatKirim' => null])
            ->toResponse($permintaan)
            ->setStatusCode(404);
    }
}
