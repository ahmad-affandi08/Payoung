<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Organisasi\Aksi\PastikanKataSandiPengguna;
use App\Domain\Tenant\Aksi\AjukanPenghapusanData;
use App\Domain\Tenant\Aksi\BatalkanPenghapusanData;
use App\Domain\Tenant\Aksi\MintaEksporDataTenant;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Kueri\DaftarEksporDataTenant;
use App\Domain\Tenant\Kueri\StatusPenghapusanDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use App\Http\Permintaan\Kelola\AjukanPenghapusanDataPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pengaturan › Data saya (audit E5, UU PDP; `/kelola/pengaturan/data-saya`, izin `data.kelola` khusus Pemilik): ekspor
 * data lengkap usaha (ZIP berisi CSV per tabel) dan, di edisi SaaS, penutupan akun dengan penghapusan data.
 */
final class DataSayaKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(DaftarEksporDataTenant $daftar, StatusPenghapusanDataTenant $penghapusan): Response
    {
        return Inertia::render('Kelola/Pengaturan/DataSaya', [
            'Ekspor' => $daftar->Ambil(),
            'Penghapusan' => $penghapusan->Ambil(),
            'PerluKataSandi' => ! $this->Pelaku()->KataSandiOtomatis,
        ]);
    }

    /**
     * Pengajuan penghapusan data dan penutupan akun (UU PDP, P-07). Kata sandi diminta ulang (akun Google tanpa kata sandi
     * dikecualikan), lalu `AjukanPenghapusanData` memeriksa Pemilik, ekspor yang sudah diunduh, dan masa tunggu 14 hari.
     */
    public function Ajukan(AjukanPenghapusanDataPermintaan $isian, PastikanKataSandiPengguna $verifikasi, AjukanPenghapusanData $ajukan): RedirectResponse
    {
        $pelaku = $this->Pelaku();
        $verifikasi->Jalankan($pelaku, $isian->AmbilKataSandi());
        $permintaan = $ajukan->Jalankan($pelaku->Id, $isian->AmbilAlasan());
        $tanggal = $permintaan->BerlakuPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y');

        return back()->with('Kilat', "Permintaan penghapusan data diterima. Paling cepat dilaksanakan {$tanggal}; Anda bisa membatalkannya kapan saja sebelum itu.");
    }

    public function Batalkan(BatalkanPenghapusanData $batalkan): RedirectResponse
    {
        $batalkan->Jalankan($this->Pelaku()->Id);

        return back()->with('Kilat', 'Permintaan penghapusan data dibatalkan. Akun dan data Anda tetap berjalan.');
    }

    public function Minta(MintaEksporDataTenant $minta): RedirectResponse
    {
        $minta->Jalankan($this->Pelaku()->Id);

        return back()->with('Kilat', 'Ekspor data sedang disiapkan. Halaman ini diperbarui otomatis. Unduh begitu statusnya Siap.');
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
