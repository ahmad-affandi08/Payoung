<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Enum\StatusRiwayatPesanWhatsapp;
use Illuminate\Support\Carbon;

/**
 * Riwayat pesan otomatis (D-299). `Tujuan` hanya berisi nomor tersamar (`••••7890`), isi pesan tidak disimpan.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int|null $IdOutlet
 * @property JenisPesanWhatsapp $Jenis
 * @property string $Tujuan
 * @property StatusRiwayatPesanWhatsapp $Status
 * @property string|null $PesanGalat
 * @property Carbon|null $DibuatPada
 */
final class RiwayatPesanWhatsapp extends ModelDasar
{
    use MilikTenant;

    protected $table = 'RiwayatPesanWhatsapp';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jenis' => JenisPesanWhatsapp::class, 'Status' => StatusRiwayatPesanWhatsapp::class];
    }
}
