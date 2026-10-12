<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Tenant\Aksi\MintaEksporDataTenant;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Kueri\DaftarEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pengaturan › Data saya (audit E5, UU PDP; `/kelola/pengaturan/data-saya`, izin `data.kelola` khusus Pemilik): ekspor
 * data lengkap usaha (ZIP berisi CSV per tabel) dan, di edisi SaaS, penutupan akun.
 */
final class DataSayaKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(DaftarEksporDataTenant $daftar): Response
    {
        return Inertia::render('Kelola/Pengaturan/DataSaya', [
            'Ekspor' => $daftar->Ambil(),
        ]);
    }

    public function Minta(MintaEksporDataTenant $minta): RedirectResponse
    {
        $minta->Jalankan($this->Pelaku()->Id);

        return back()->with('Kilat', 'Ekspor data sedang disiapkan. Halaman ini diperbarui otomatis; unduh begitu statusnya Siap.');
    }

    public function Unduh(string $uuidEkspor, PencatatAudit $audit): StreamedResponse
    {
        $ekspor = EksporDataTenant::query()->where('Uuid', $uuidEkspor)->firstOrFail();
        abort_unless($ekspor->Status === StatusEksporDataTenant::Siap && $ekspor->PathBerkas !== null && $ekspor->KedaluwarsaPada?->isFuture() === true, 404);
        $disk = Storage::disk((string) config('tenant.DiskEkspor'));
        abort_unless($disk->exists($ekspor->PathBerkas), 404);

        $ekspor->update(['DiunduhPada' => now()]);
        $audit->Catat('data.ekspor-unduh', $ekspor, idTenant: $ekspor->IdTenant, idPengguna: $this->Pelaku()->Id);

        return $disk->download($ekspor->PathBerkas, 'data-usaha-'.now('Asia/Jakarta')->format('Ymd-His').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
