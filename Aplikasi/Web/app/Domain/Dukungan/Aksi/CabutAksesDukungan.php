<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use Illuminate\Support\Facades\DB;

/**
 * Mencabut izin akses dukungan (PGL-16, P-09 langkah 4): Owner kapan saja, atau Super Admin untuk akses darurat yang
 * ingin ia tutup lebih awal. Semua sesi petugas yang sedang berjalan berakhir seketika (dan sesi yang belum sempat
 * ditukar tidak bisa ditukar lagi, karena token tukar memeriksa izin induknya). Idempoten.
 */
final class CabutAksesDukungan
{
    public function __construct(
        private readonly AkhiriSesiAksesDukungan $akhiri,
        private readonly PencatatAudit $audit,
        private readonly GerbangPengelola $gerbang,
    ) {}

    /**
     * @param  int|null  $idPencabut  Id `Pengguna` Owner yang mencabut
     * @param  int|null  $idPencabutPengelola  Id petugas pengelola (Super Admin) yang mencabut akses darurat
     *
     * @throws PelanggaranAturanBisnis AksesTidakBerlaku
     */
    public function Jalankan(AksesDukungan $akses, ?int $idPencabut, ?int $idPencabutPengelola = null): AksesDukungan
    {
        return DB::transaction(function () use ($akses, $idPencabut, $idPencabutPengelola): AksesDukungan {
            $akses = AksesDukungan::query()->whereKey($akses->Id)->lockForUpdate()->firstOrFail();

            if ($akses->DicabutPada !== null) {
                return $akses;
            }

            if ($akses->BerakhirPada->lte(now())) {
                throw new PelanggaranAturanBisnis('AksesTidakBerlaku', 'Izin akses ini sudah berakhir dengan sendirinya.');
            }

            $akses->DicabutPada = now();
            $akses->DicabutOleh = $idPencabut;
            $akses->DicabutOlehPengelola = $idPencabutPengelola;
            $akses->save();

            $sesiBerjalan = SesiAksesDukungan::query()->where('IdAksesDukungan', $akses->Id)->whereNull('DiakhiriPada')->get();

            foreach ($sesiBerjalan as $sesi) {
                $this->akhiri->Jalankan($sesi, AlasanAkhirSesiDukungan::Dicabut);
            }

            $this->audit->Catat('dukungan.akses.cabut', $akses, nilaiBaru: [
                'Jenis' => $akses->Jenis->value,
                'SesiDiakhiri' => $sesiBerjalan->count(),
            ], idPengguna: $idPencabut);

            if ($idPencabutPengelola !== null) {
                $this->gerbang->CatatJejak('dukungan.akses.cabut', $idPencabutPengelola, $akses->IdTenant, ['UuidAkses' => $akses->Uuid]);
            }

            return $akses;
        });
    }
}
