<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use Illuminate\Support\Facades\Storage;

/**
 * Menghapus berkas ekspor data yang sudah kedaluwarsa (audit E5): berisi data pelanggan dan karyawan, jadi tidak boleh
 * menumpuk. Status menjadi `Kedaluwarsa`, jejak permintaannya tetap ada. Dipanggil dalam konteks tenant.
 */
final class BersihkanEksporDataKedaluwarsa
{
    public function Jalankan(): int
    {
        $jumlah = 0;

        foreach (EksporDataTenant::query()->where('Status', StatusEksporDataTenant::Siap->value)->where('KedaluwarsaPada', '<=', now())->get() as $ekspor) {
            if ($ekspor->PathBerkas !== null) {
                Storage::disk((string) config('tenant.DiskEkspor'))->delete($ekspor->PathBerkas);
            }

            $ekspor->update(['Status' => StatusEksporDataTenant::Kedaluwarsa, 'PathBerkas' => null]);
            $jumlah++;
        }

        return $jumlah;
    }
}
