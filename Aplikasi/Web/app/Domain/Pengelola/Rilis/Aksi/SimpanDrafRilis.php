<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Rilis\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Rilis\Data\DataRilis;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusRilis;
use App\Domain\Tenant\Model\RilisAplikasi;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * P-10 langkah 1: mencatat build baru sebagai rilis `Draf` atau mengubah draf (rilis yang sudah diterbitkan tidak bisa
 * diubah). Versi semantik `MAJOR.MINOR.PATCH` (§14.6), unik per aplikasi, platform, dan kanal. Audit `rilis.draf.simpan`.
 */
final class SimpanDrafRilis
{
    public const POLA_VERSI = '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/';

    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, DataRilis $data, ?RilisAplikasi $rilis = null): RilisAplikasi
    {
        if (preg_match(self::POLA_VERSI, $data->versi) !== 1) {
            throw new PelanggaranAturanBisnis('VersiTidakValid', 'Versi berformat MAJOR.MINOR.PATCH, misal 1.4.0.', 'Versi');
        }

        if ($rilis !== null && $rilis->Status !== StatusRilis::Draf) {
            throw new PelanggaranAturanBisnis('RilisBukanDraf', 'Rilis yang sudah diterbitkan tidak bisa diubah. Catat build baru sebagai rilis lain.');
        }

        try {
            return DB::transaction(function () use ($pelaku, $data, $rilis): RilisAplikasi {
                $nilaiLama = $rilis?->only(['Aplikasi', 'Platform', 'Kanal', 'Versi', 'Build', 'UrlUnduh']);
                $rilis ??= new RilisAplikasi(['Status' => StatusRilis::Draf, 'DibuatOleh' => $pelaku->Id]);
                $rilis->fill([
                    'Aplikasi' => $data->aplikasi,
                    'Platform' => $data->platform,
                    'Kanal' => $data->kanal,
                    'Versi' => $data->versi,
                    'Build' => $data->build,
                    'UrlUnduh' => $data->urlUnduh,
                    'Sha256Unduhan' => $data->urlUnduh === null ? null : $data->sha256Unduhan,
                    'CatatanRilis' => $data->catatanRilis,
                ])->save();

                $this->audit->Catat('rilis.draf.simpan', $rilis, nilaiLama: $nilaiLama, nilaiBaru: [
                    'Aplikasi' => $data->aplikasi->value,
                    'Platform' => $data->platform,
                    'Kanal' => $data->kanal->value,
                    'Versi' => $data->versi,
                    'Build' => $data->build,
                ], idPelaku: $pelaku->Id);

                return $rilis;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('VersiSudahAda', "Versi {$data->versi} untuk aplikasi, platform, dan kanal ini sudah tercatat.", 'Versi');
        }
    }
}
