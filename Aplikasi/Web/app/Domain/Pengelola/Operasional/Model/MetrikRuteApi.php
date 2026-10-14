<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Metrik API per rute per jam (PGL-20 lanjutan): jumlah, galat server, total durasi, dan histogram latensi untuk p95
 * per rute. Catatan platform: tidak memuat data tenant; kuncinya nama rute, bukan jalur.
 *
 * @property int $Id
 * @property string $Kelompok
 * @property string $NamaRute
 * @property Carbon $Jam
 * @property int $JumlahPermintaan
 * @property int $JumlahGalatServer
 * @property int $TotalDurasiMs
 * @property int $Hingga100Ms
 * @property int $Hingga250Ms
 * @property int $Hingga400Ms
 * @property int $Hingga800Ms
 * @property int $Hingga1600Ms
 * @property int $Lebih1600Ms
 */
final class MetrikRuteApi extends ModelDasar
{
    protected $table = 'MetrikRuteApi';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jam' => 'datetime'];
    }
}
