<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Satu shift dalam pola jadwal mingguan berulang seorang karyawan (F-18, D-251): "Rina selalu Pagi Senin sampai Jumat".
 * `HariKe` 1 = Senin … 7 = Minggu. Dipakai `TerapkanPolaJadwal` untuk mengisi jadwal hari yang masih kosong.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdKaryawan
 * @property int $IdOutlet
 * @property int|null $IdShiftKerja
 * @property int $HariKe
 * @property string $JamMulai
 * @property string $JamSelesai
 */
final class PolaJadwalKerja extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PolaJadwalKerja';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['HariKe' => 'integer'];
    }
}
