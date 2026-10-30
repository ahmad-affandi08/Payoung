<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Karyawan\Enum\StatusTukarShift;
use Illuminate\Support\Carbon;

/**
 * Permintaan tukar shift (F-18, D-251). Pemohon menyerahkan `IdJadwalPemohon` kepada rekannya. Bila `IdJadwalRekan`
 * terisi, keduanya bertukar shift. Bila kosong, rekan hanya mengambil alih shift pemohon. Jadwal berubah baru saat
 * pengelola menyetujui, dan permintaan yang jadwalnya berubah/terhapus lebih dulu ditolak otomatis saat diputuskan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdJadwalPemohon
 * @property int $IdKaryawanPemohon
 * @property int $IdKaryawanRekan
 * @property int|null $IdJadwalRekan
 * @property StatusTukarShift $Status
 * @property string $Alasan
 * @property string|null $AlasanKeputusan
 * @property int|null $DiputuskanOleh
 * @property Carbon|null $RekanMenanggapiPada
 * @property Carbon|null $DiputuskanPada
 * @property Carbon|null $DibuatPada
 */
final class PermintaanTukarShift extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PermintaanTukarShift';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'MenungguRekan'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusTukarShift::class, 'RekanMenanggapiPada' => 'datetime', 'DiputuskanPada' => 'datetime'];
    }
}
