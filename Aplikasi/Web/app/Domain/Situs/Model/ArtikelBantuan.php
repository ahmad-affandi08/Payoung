<?php

declare(strict_types=1);

namespace App\Domain\Situs\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Situs\Enum\StatusArtikel;
use Illuminate\Support\Carbon;

/**
 * Artikel Pusat Bantuan (PLT-12, data platform). `Isi` memakai format teks ringan situs (tanpa HTML). Tampil publik di
 * `/pusat-bantuan/{Slug}` dan di bantuan kontekstual back-office hanya bila `Terbit`. `Konteks` = awalan jalur
 * back-office tempat artikel relevan (misal `/kelola/produk`); `UrlVideo` = tautan video (https).
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Slug
 * @property string $Judul
 * @property string|null $Ringkasan
 * @property string $Isi
 * @property string|null $Kategori
 * @property string|null $UrlVideo
 * @property list<string> $Konteks
 * @property int $Urutan
 * @property StatusArtikel $Status
 * @property Carbon|null $DiterbitkanPada
 * @property int|null $IdPenggunaPengelolaPengubah
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class ArtikelBantuan extends ModelDasar
{
    /** Slug artikel: satu segmen huruf kecil, angka, tanda hubung. */
    public const POLA_SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Awalan jalur back-office yang sah untuk `Konteks`. */
    public const POLA_KONTEKS = '/kelola(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    protected $table = 'ArtikelBantuan';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Draf', 'Urutan' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusArtikel::class, 'DiterbitkanPada' => 'datetime', 'Konteks' => 'array', 'Urutan' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'Uuid';
    }

    public function CekTerbit(): bool
    {
        return $this->Status === StatusArtikel::Terbit;
    }
}
