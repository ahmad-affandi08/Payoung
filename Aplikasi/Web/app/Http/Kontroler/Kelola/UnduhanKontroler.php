<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Kueri\DaftarBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pusat Unduhan (D-124, `/kelola/unduhan`): ekspor laporan besar yang disiapkan di latar belakang. Setiap pengguna
 * hanya melihat dan mengunduh berkasnya sendiri (isi laporan mengikuti izin dan cakupan outlet peminta).
 */
final class UnduhanKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarBerkasUnduhan $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarBerkasUnduhan::KOLOM_URUT, DaftarBerkasUnduhan::URUT_BAWAAN, DaftarBerkasUnduhan::KOLOM_SARING);
        $idPengguna = $this->Pelaku()->Id;

        return ResponsTabel::Kirim($permintaan, 'Kelola/Unduhan/Daftar', 'Unduhan', fn (): array => $daftar->Ambil($tabel, $idPengguna), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusBerkasUnduhan $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusBerkasUnduhan::cases()),
            'HariBerlaku' => (int) config('tenant.HariBerlakuUnduhan'),
        ]);
    }

    public function Unduh(string $uuidBerkas, PencatatAudit $audit): StreamedResponse
    {
        $berkas = $this->AmbilBerkasMilikSaya($uuidBerkas);
        abort_unless($berkas->Status === StatusBerkasUnduhan::Siap && $berkas->PathBerkas !== null && $berkas->KedaluwarsaPada?->isFuture() === true, 404);
        $disk = Storage::disk((string) config('tenant.DiskUnduhan'));
        abort_unless($disk->exists($berkas->PathBerkas), 404);

        $berkas->update(['DiunduhPada' => now()]);
        $audit->Catat('unduhan.unduh', $berkas, idTenant: $berkas->IdTenant, idPengguna: $this->Pelaku()->Id);

        return $disk->download($berkas->PathBerkas, $berkas->NamaBerkas ?? "laporan.{$berkas->Format}", [
            'Content-Type' => $berkas->Format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Menghapus berkas dari Pusat Unduhan (berkas fisiknya ikut dihapus); proses yang masih berjalan tidak bisa dihapus. */
    public function Hapus(string $uuidBerkas, PencatatAudit $audit): RedirectResponse
    {
        $berkas = $this->AmbilBerkasMilikSaya($uuidBerkas);
        abort_if($berkas->Status->CekAktif(), 409, 'Berkas masih disiapkan.');

        if ($berkas->PathBerkas !== null) {
            Storage::disk((string) config('tenant.DiskUnduhan'))->delete($berkas->PathBerkas);
        }

        $audit->Catat('unduhan.hapus', $berkas, idTenant: $berkas->IdTenant, idPengguna: $this->Pelaku()->Id);
        $berkas->delete();

        return back()->with('Kilat', 'Berkas dihapus dari Pusat Unduhan.');
    }

    private function AmbilBerkasMilikSaya(string $uuidBerkas): BerkasUnduhan
    {
        return BerkasUnduhan::query()->where('Uuid', $uuidBerkas)->where('IdPengguna', $this->Pelaku()->Id)->firstOrFail();
    }
}
