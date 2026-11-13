<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;

/**
 * Sakelar satu jenis pesan WhatsApp otomatis (D-299). Tanpa baris = nilai bawaan jenis itu.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property JenisPesanWhatsapp $Jenis
 * @property bool $Aktif
 */
final class PengaturanPesanWhatsapp extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PengaturanPesanWhatsapp';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jenis' => JenisPesanWhatsapp::class, 'Aktif' => 'boolean'];
    }
}
