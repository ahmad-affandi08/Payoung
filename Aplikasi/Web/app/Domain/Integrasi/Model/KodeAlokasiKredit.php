<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Kode OTP alokasi kredit broadcast (D-300): hanya hash, berlaku 10 menit, tiga kali salah = mati.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property int $IdOutlet
 * @property string $Jumlah
 * @property string $KodeHash
 * @property int $Percobaan
 * @property Carbon $KedaluwarsaPada
 * @property Carbon|null $DigunakanPada
 */
final class KodeAlokasiKredit extends ModelDasar
{
    use MilikTenant;

    public const MAKS_PERCOBAAN = 3;

    public const MENIT_BERLAKU = 10;

    protected $table = 'KodeAlokasiKredit';

    protected bool $pakaiUuid = false;

    /** @var list<string> */
    protected $hidden = ['KodeHash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['KedaluwarsaPada' => 'datetime', 'DigunakanPada' => 'datetime'];
    }
}
