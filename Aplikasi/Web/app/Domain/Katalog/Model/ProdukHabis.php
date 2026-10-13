<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Enum\KanalKetersediaan;
use Illuminate\Support\Carbon;

/**
 * Produk yang ditandai habis di satu outlet (F-17 BR-17.2, "86"). Ada baris = habis; dihapus = tersedia lagi. `Kanal`
 * `Semua` = habis di semua kanal; kanal tunggal (`Kasir`, `TokoOnline`, `SelfOrder`) = habis hanya di kanal itu (PRD-11).
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdProduk
 * @property int $IdOutlet
 * @property KanalKetersediaan $Kanal
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class ProdukHabis extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ProdukHabis';

    /** Tabel ini tidak punya kolom `Uuid`; produk & outlet dirujuk lewat kunci angka di dalam tenant. */
    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Kanal' => 'Semua'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Kanal' => KanalKetersediaan::class];
    }
}
