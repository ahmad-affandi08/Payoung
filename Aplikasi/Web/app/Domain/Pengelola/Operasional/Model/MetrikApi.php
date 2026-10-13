<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Metrik API per menit per kelompok (P-11, PGL-20): jumlah permintaan, galat server (5xx), total durasi, dan histogram
 * latensi untuk p95. Catatan platform: tidak memuat data usaha tenant dan tidak memuat jalur atau pengguna.
 *
 * @property int $Id
 * @property string $Kelompok
 * @property Carbon $Menit
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
final class MetrikApi extends ModelDasar
{
    protected $table = 'MetrikApi';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Menit' => 'datetime'];
    }
}
