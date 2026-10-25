<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Satu kali jalan perintah terjadwal (D-209), dari scheduler atau dari tombol "Jalankan sekarang" di konsol.
 *
 * @property int $Id
 * @property string $Perintah
 * @property string $Sumber Jadwal|Manual
 * @property Carbon $MulaiPada
 * @property Carbon|null $SelesaiPada
 * @property bool|null $Berhasil Null selama masih berjalan.
 * @property int|null $DurasiMs
 * @property string|null $Keterangan
 * @property int|null $IdPenggunaPengelola
 */
final class RiwayatJadwal extends ModelDasar
{
    public const SUMBER_JADWAL = 'Jadwal';

    public const SUMBER_MANUAL = 'Manual';

    protected $table = 'RiwayatJadwal';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['MulaiPada' => 'datetime', 'SelesaiPada' => 'datetime', 'Berhasil' => 'boolean', 'DurasiMs' => 'integer'];
    }
}
