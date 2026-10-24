<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\SektorOutletTenant;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-01 langkah 7: panduan awal selesai; checklist "Langkah Berikutnya" di beranda mengambil alih. Idempoten: waktu &
 * penyelesai hanya diisi sekali.
 */
final class SelesaikanPanduanAwal
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PencatatAudit $audit,
        private readonly SektorOutletTenant $sektor,
    ) {}

    public function Jalankan(int $idPengguna, ?int $idOutlet = null): void
    {
        $idTenant = $this->konteks->Wajib();

        DB::transaction(function () use ($idTenant, $idPengguna, $idOutlet): void {
            $this->penguncian->Kunci($idTenant);
            $progres = ProgresPanduanAwal::query()->firstOrCreate([], ['IdOutlet' => $idOutlet]);

            if ($progres->SelesaiPada !== null) {
                return;
            }

            $kurang = $progres->Wajib ? $progres->AmbilLangkahWajibBelumSelesai($this->sektor->CekHanyaPerusahaan()) : [];

            if ($kurang !== []) {
                $daftar = implode(', ', array_map(fn (LangkahPanduan $l): string => $l->AmbilJudul(), $kurang));

                throw new PelanggaranAturanBisnis('LangkahWajibBelumSelesai', "Selesaikan dulu: {$daftar}.");
            }

            $progres->fill(['SelesaiPada' => now(), 'IdPenggunaPenyelesai' => $idPengguna])->save();
            $this->audit->Catat('panduan-awal.selesai', $progres, nilaiBaru: ['StatusLangkah' => $progres->StatusLangkah]);
        });
    }
}
