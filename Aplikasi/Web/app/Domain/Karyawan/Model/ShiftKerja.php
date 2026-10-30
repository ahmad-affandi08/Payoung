<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Shift bernama milik usaha (F-18, D-251): Pagi, Siang, Malam, dst. dengan jam mulai/selesai `HH:mm` (`JamSelesai` ≤
 * `JamMulai` = lewat tengah malam). Menerapkan shift ke jadwal menyalin jamnya, jadi mengubah shift tidak mengubah jadwal
 * yang sudah ada. Shift yang tidak aktif tidak ditawarkan lagi tetapi tetap terbaca di jadwal lama.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nama
 * @property string $JamMulai
 * @property string $JamSelesai
 * @property bool $Aktif
 * @property int $Urutan
 */
final class ShiftKerja extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ShiftKerja';

    /** @var array<string, mixed> */
    protected $attributes = ['Aktif' => true, 'Urutan' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Aktif' => 'boolean', 'Urutan' => 'integer'];
    }
}
