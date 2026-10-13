<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Dukungan\Layanan\PelaksanaAlatDukungan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Alat bantu (baca): status tiap perangkat tenant beserta antrean kirim (outbox) yang tertunda (PGL-16 bagian 2, P-09).
 * Hanya metadata perangkat (nama, kode, versi, waktu terakhir terhubung, jumlah tertunda); tidak ada isi transaksi.
 */
final class LihatStatusPerangkatDukungan
{
    /** Antrean tertunda lebih lama dari ini (jam) ditandai perlu perhatian. */
    public const JAM_PERHATIAN = 2;

    public function __construct(private readonly PelaksanaAlatDukungan $pelaksana) {}

    public function Jalankan(PenggunaPengelola $petugas, int $idTenant): HasilKerjaAlat
    {
        return $this->pelaksana->Jalankan($petugas, $idTenant, AlatDukungan::StatusPerangkat, [], function (): HasilKerjaAlat {
            $outlet = Outlet::query()->pluck('Nama', 'Id')->all();
            $batas = now()->subHours(self::JAM_PERHATIAN);
            $daftar = [];

            foreach (Perangkat::query()->orderByRaw('DicabutPada IS NULL DESC')->orderBy('IdOutlet')->orderBy('Nama')->get() as $p) {
                $status = $p->AmbilStatus();
                $perhatian = null;

                if ($status === 'Aktif' && $p->JumlahOutboxTertunda > 0 && ($p->TerakhirAktifPada === null || $p->TerakhirAktifPada->lt($batas))) {
                    $perhatian = "{$p->JumlahOutboxTertunda} item belum terkirim dan perangkat belum terhubung lebih dari ".self::JAM_PERHATIAN.' jam.';
                } elseif ($status === 'Aktif' && $p->TerakhirAktifPada === null) {
                    $perhatian = 'Perangkat aktif tetapi belum pernah menghubungi server.';
                }

                $daftar[] = [
                    'Uuid' => $p->Uuid,
                    'Kode' => $p->Kode,
                    'Nama' => $p->Nama,
                    'Outlet' => $outlet[$p->IdOutlet] ?? '-',
                    'Jenis' => $p->Jenis->value,
                    'Platform' => $p->Platform?->value,
                    'VersiAplikasi' => $p->VersiAplikasi,
                    'VersiSkemaSinkron' => $p->VersiSkemaSinkron,
                    'Status' => $status,
                    'TerakhirAktifPada' => $p->TerakhirAktifPada?->toIso8601String(),
                    'JumlahOutboxTertunda' => $p->JumlahOutboxTertunda,
                    'MintaLogPada' => $p->MintaLogPada?->toIso8601String(),
                    'PermintaanLogBerjalan' => $p->CekPermintaanLogBerjalan(),
                    'LogDiunggahPada' => $p->LogDiunggahPada?->toIso8601String(),
                    'Perhatian' => $perhatian,
                ];
            }

            return new HasilKerjaAlat(StatusHasilAlatDukungan::Berhasil, count($daftar).' perangkat dibaca.', data: ['Perangkat' => $daftar]);
        });
    }
}
