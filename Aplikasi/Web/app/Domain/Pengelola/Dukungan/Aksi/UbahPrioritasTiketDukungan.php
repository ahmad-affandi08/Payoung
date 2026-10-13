<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Layanan\PenghitungSlaTiket;
use App\Domain\Dukungan\Layanan\PenulisPesanTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Kueri\PaketTenant;
use Illuminate\Support\Facades\DB;

/**
 * Triase: tim internal mengubah prioritas tiket terbuka (P-09). Selama belum ada respons pertama, batas SLA dihitung
 * ulang dari waktu tiket dibuat dengan prioritas baru; setelah direspons, SLA respons pertama tidak berubah.
 */
final class UbahPrioritasTiketDukungan
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenulisPesanTiket $penulisPesan,
        private readonly PenghitungSlaTiket $penghitungSla,
        private readonly PaketTenant $paketTenant,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $uuidTiket, PrioritasTiketDukungan $prioritas): TiketDukungan
    {
        return $this->konteks->JalankanLintasTenant("Mengubah prioritas tiket dukungan {$uuidTiket}", fn (): TiketDukungan => DB::transaction(
            function () use ($pelaku, $uuidTiket, $prioritas): TiketDukungan {
                $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->where('Uuid', $uuidTiket)->lockForUpdate()->firstOrFail();

                if (! $tiket->Status->CekTerbuka()) {
                    throw new PelanggaranAturanBisnis('P-09', 'Prioritas hanya bisa diubah untuk tiket yang masih terbuka.', 'Prioritas');
                }

                $asal = $tiket->Prioritas;

                if ($asal === $prioritas) {
                    return $tiket;
                }

                $lama = ['Prioritas' => $asal->value, 'BatasSlaPada' => $tiket->BatasSlaPada->toIso8601String()];
                $tiket->Prioritas = $prioritas;

                if ($tiket->ResponsPertamaPada === null) {
                    $kodePaket = $this->paketTenant->AmbilKode($tiket->IdTenant);
                    $tiket->JamSla = $this->penghitungSla->HitungJam($kodePaket, $prioritas);
                    $tiket->SlaJamKalender = $this->penghitungSla->CekKalender($kodePaket, $prioritas);
                    $tiket->BatasSlaPada = $this->penghitungSla->HitungBatas($tiket->DibuatPada, $tiket->JamSla, $tiket->SlaJamKalender);
                }

                $tiket->save();

                $this->penulisPesan->TulisSistem(
                    $tiket,
                    "{$pelaku->Nama} mengubah prioritas dari {$asal->AmbilLabel()} menjadi {$prioritas->AmbilLabel()}.",
                    catatanInternal: true,
                );

                $this->audit->Catat(
                    'dukungan.tiket.ubah-prioritas',
                    $tiket,
                    nilaiLama: $lama,
                    nilaiBaru: ['Prioritas' => $prioritas->value, 'BatasSlaPada' => $tiket->BatasSlaPada->toIso8601String()],
                    idPelaku: $pelaku->Id,
                    idTenant: $tiket->IdTenant,
                );

                return $tiket;
            },
        ));
    }
}
