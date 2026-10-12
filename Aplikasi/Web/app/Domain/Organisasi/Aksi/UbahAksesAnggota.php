<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Organisasi\Data\DataAksesAnggota;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Layanan\PemeriksaAksesAnggota;
use App\Domain\Organisasi\Layanan\PenjagaAnggota;
use App\Domain\Organisasi\Layanan\PenugasanOutlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\DB;

/**
 * F-02 langkah 3: mengubah peran & outlet yang ditugaskan kepada anggota aktif. Aturan anti-eskalasi mengikuti
 * `PemeriksaAksesAnggota`, aturan Pemilik mengikuti `PenjagaAnggota`.
 */
final class UbahAksesAnggota
{
    public function __construct(
        private readonly PemeriksaAksesAnggota $pemeriksaAkses,
        private readonly PenjagaAnggota $penjaga,
        private readonly PenugasanOutlet $penugasan,
        private readonly AksesPengguna $akses,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idPelaku, TenantPengguna $anggota, DataAksesAnggota $data): void
    {
        DB::transaction(function () use ($idPelaku, $anggota, $data): void {
            $anggota = TenantPengguna::query()->lockForUpdate()->findOrFail($anggota->Id);
            $this->penjaga->PastikanBolehMengubah($idPelaku, $anggota);

            // Anggota nonaktif boleh diganti perannya: akses masuknya tetap terblokir oleh status keanggotaan, dan tanpa ini
            // peran yang dipakai anggota nonaktif tidak bisa dihapus sementara reaktivasi butuh kursi pengguna yang mungkin penuh.
            $hasil = $this->pemeriksaAkses->Periksa($idPelaku, $data);

            if ($anggota->Pemilik && ! $hasil['Peran']->CekPemilik()) {
                $this->penjaga->PastikanBukanPemilikTerakhir($anggota);
            }

            $lama = $this->AmbilRingkasan($anggota);
            $anggota->update([
                'Pemilik' => $hasil['Peran']->CekPemilik(),
                'IdPeran' => $hasil['Peran']->Id,
                'SemuaOutlet' => $hasil['SemuaOutlet'],
            ]);
            $this->penugasan->Sinkronkan($anggota->IdPengguna, $hasil['Peran']->Id, $hasil['SemuaOutlet'], $hasil['IdOutlet']);
            $this->akses->Lupakan();
            $baru = $this->AmbilRingkasan($anggota);

            if ($lama != $baru) {
                $this->audit->Catat('pengguna.ubah-akses', $anggota, nilaiLama: $lama, nilaiBaru: $baru);
            }
        });
    }

    /**
     * @return array{IdPengguna: int, Peran: string|null, SemuaOutlet: bool, IdOutlet: list<int>}
     */
    private function AmbilRingkasan(TenantPengguna $anggota): array
    {
        $idOutlet = array_map('intval', OutletPengguna::query()->where('IdPengguna', $anggota->IdPengguna)->orderBy('IdOutlet')->pluck('IdOutlet')->all());

        return [
            'IdPengguna' => $anggota->IdPengguna,
            'Peran' => $anggota->IdPeran === null ? null : Peran::query()->whereKey($anggota->IdPeran)->value('Nama'),
            'SemuaOutlet' => $anggota->SemuaOutlet,
            'IdOutlet' => array_values($idOutlet),
        ];
    }
}
