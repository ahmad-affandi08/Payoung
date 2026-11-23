<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use Illuminate\Support\Carbon;

/**
 * Stasiun dapur tingkat tenant, misal "Dapur", "Bar", "Pastry" (F-10a, PRD F-10, §15 `StasiunDapur`). Kategori
 * produk merujuknya (`Kategori.IdStasiunDapur`) untuk merutekan item pesanan ke KDS/printer dapur; perangkat KDS
 * dan printer dapur di tiap outlet memilih stasiun yang dilayaninya. Diarsipkan, tidak dihapus.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nama
 * @property int $Urutan
 * @property bool $Salinan D-310: stasiun checker, hanya menerima salinan semua tiket dan tidak dipilih sebagai stasiun kategori/produk
 * @property StatusOrganisasi $Status
 * @property Carbon|null $DiarsipkanPada
 */
final class StasiunDapur extends ModelDasar
{
    use MilikTenant;

    protected $table = 'StasiunDapur';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Aktif', 'DiarsipkanPada' => null, 'Urutan' => 0, 'Salinan' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusOrganisasi::class, 'DiarsipkanPada' => 'datetime', 'Urutan' => 'integer', 'Salinan' => 'boolean'];
    }
}
