<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Enum\KanalRilis;
use App\Domain\Tenant\Enum\StatusRilis;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Rilis aplikasi Flutter per platform & kanal (P-10, §14.6). Data platform (tanpa `MilikTenant`); dikelola dari
 * Platform Pengelola, dibaca `VersiAplikasiPerangkat` untuk `konfigurasi-aplikasi`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property AplikasiRilis $Aplikasi
 * @property string $Platform
 * @property KanalRilis $Kanal
 * @property string $Versi
 * @property int|null $Build
 * @property StatusRilis $Status
 * @property int $PersenRollout
 * @property string|null $UrlUnduh
 * @property string|null $Sha256Unduhan SHA-256 (hex 64) berkas di UrlUnduh; syarat pembaruan otomatis Windows (POS-23)
 * @property string|null $CatatanRilis
 * @property string|null $VersiMinimum
 * @property Carbon|null $VersiMinimumBerlakuPada
 * @property bool $PerbaikanKeamanan
 * @property Carbon|null $DiterbitkanPada
 * @property Carbon|null $DihentikanPada
 * @property string|null $AlasanDihentikan
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class RilisAplikasi extends ModelDasar
{
    protected $table = 'RilisAplikasi';

    /** Kunci cache daftar rilis per aplikasi & platform (dipakai `VersiAplikasiPerangkat`). */
    public static function KunciCache(string $aplikasi, string $platform): string
    {
        return "rilis:{$aplikasi}:{$platform}";
    }

    protected static function booted(): void
    {
        // Batalkan cache pasangan (aplikasi, platform) yang lama dan yang baru, kalau salah satunya berubah.
        $lupakan = static function (self $rilis): void {
            foreach ([$rilis->getAttributes(), $rilis->getRawOriginal()] as $nilai) {
                $aplikasi = $nilai['Aplikasi'] ?? null;
                $platform = $nilai['Platform'] ?? null;

                if ($aplikasi instanceof BackedEnum) {
                    $aplikasi = $aplikasi->value;
                }

                if (is_string($aplikasi) && is_string($platform)) {
                    Cache::forget(self::KunciCache($aplikasi, $platform));
                }
            }
        };
        self::saved($lupakan);
        self::deleted($lupakan);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'Build' => null,
        'PersenRollout' => 0,
        'UrlUnduh' => null,
        'Sha256Unduhan' => null,
        'CatatanRilis' => null,
        'VersiMinimum' => null,
        'VersiMinimumBerlakuPada' => null,
        'PerbaikanKeamanan' => false,
        'DiterbitkanPada' => null,
        'DihentikanPada' => null,
        'AlasanDihentikan' => null,
        'DibuatOleh' => null,
    ];

    /** Versi semantik tanpa `+BUILD` (§14.6): < 0 bila `$a` lebih lama dari `$b`. */
    public static function BandingkanVersi(string $a, string $b): int
    {
        return version_compare(explode('+', $a)[0], explode('+', $b)[0]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Aplikasi' => AplikasiRilis::class,
            'Kanal' => KanalRilis::class,
            'Status' => StatusRilis::class,
            'Build' => 'integer',
            'PersenRollout' => 'integer',
            'PerbaikanKeamanan' => 'boolean',
            'VersiMinimumBerlakuPada' => 'datetime',
            'DiterbitkanPada' => 'datetime',
            'DihentikanPada' => 'datetime',
        ];
    }
}
