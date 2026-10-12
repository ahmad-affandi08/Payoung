<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Tugas;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Layanan\PenyusunEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menyusun ekspor data lengkap usaha di antrean (audit E5): bisa makan waktu pada usaha besar, jadi tidak di permintaan
 * web. Payload hanya Id. Satu percobaan: bila gagal, Pemilik cukup meminta ulang (status `Gagal` mengizinkan permintaan baru).
 */
final class EksporDataTenantTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idEkspor,
    ) {}

    public function handle(KonteksTenant $konteks, PenyusunEksporDataTenant $penyusun, PencatatAudit $audit): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $ekspor = EksporDataTenant::query()->find($this->idEkspor);

            if ($ekspor === null || $ekspor->Status !== StatusEksporDataTenant::Antre) {
                return;
            }

            $ekspor->update(['Status' => StatusEksporDataTenant::Berjalan]);

            try {
                $hasil = $penyusun->Susun($ekspor, $this->idTenant);
                $ekspor->update([
                    'Status' => StatusEksporDataTenant::Siap,
                    'PathBerkas' => $hasil['Path'],
                    'UkuranByte' => $hasil['UkuranByte'],
                    'JumlahTabel' => $hasil['JumlahTabel'],
                    'JumlahBaris' => $hasil['JumlahBaris'],
                    'SelesaiPada' => now(),
                    'KedaluwarsaPada' => now()->addDays((int) config('tenant.HariBerlakuEkspor')),
                ]);
                $audit->Catat('data.ekspor-siap', $ekspor, nilaiBaru: ['JumlahTabel' => $hasil['JumlahTabel'], 'JumlahBaris' => $hasil['JumlahBaris'], 'UkuranByte' => $hasil['UkuranByte']], idTenant: $this->idTenant, idPengguna: $ekspor->IdPenggunaPeminta);
            } catch (Throwable $galat) {
                Log::error('Ekspor data usaha gagal.', ['IdTenant' => $this->idTenant, 'Galat' => $galat->getMessage()]);
                $ekspor->update(['Status' => StatusEksporDataTenant::Gagal, 'Galat' => mb_substr($galat->getMessage(), 0, 300)]);
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
