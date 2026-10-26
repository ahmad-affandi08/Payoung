<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Enum\KategoriAksesDarurat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Izin akses dukungan satu tenant (PGL-16, PRD §15.3). Hanya dua perubahan sesudah dibuat: dicabut dan penanda
 * pemilik sudah diberi tahu; selain itu tidak diubah (rentang waktu dan cakupan tetap seperti yang disetujui).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property JenisAksesDukungan $Jenis
 * @property int|null $IdPenggunaPengelola
 * @property int|null $DiizinkanOleh
 * @property string|null $NamaPemberi
 * @property int|null $IdTiketDukungan
 * @property CakupanAksesDukungan $Cakupan
 * @property bool $DataPribadiTerbuka
 * @property string|null $Alasan
 * @property KategoriAksesDarurat|null $KategoriDarurat
 * @property Carbon $MulaiPada
 * @property Carbon $BerakhirPada
 * @property Carbon|null $DicabutPada
 * @property int|null $DicabutOleh
 * @property int|null $DicabutOlehPengelola
 * @property Carbon|null $PemilikDiberitahuPada
 */
final class AksesDukungan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'AksesDukungan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdPenggunaPengelola' => null,
        'DiizinkanOleh' => null,
        'NamaPemberi' => null,
        'IdTiketDukungan' => null,
        'DataPribadiTerbuka' => false,
        'Alasan' => null,
        'KategoriDarurat' => null,
        'DicabutPada' => null,
        'DicabutOleh' => null,
        'DicabutOlehPengelola' => null,
        'PemilikDiberitahuPada' => null,
    ];

    /** Berlaku sekarang: sudah mulai, belum berakhir, belum dicabut. */
    public function CekAktif(?CarbonInterface $saat = null): bool
    {
        $saat ??= now();

        return $this->DicabutPada === null && $this->MulaiPada->lte($saat) && $this->BerakhirPada->gt($saat);
    }

    protected static function booted(): void
    {
        self::updating(static function (AksesDukungan $akses): void {
            $terlarang = array_diff(array_keys($akses->getDirty()), ['DicabutPada', 'DicabutOleh', 'DicabutOlehPengelola', 'PemilikDiberitahuPada', 'DiubahPada']);

            if ($terlarang !== []) {
                throw new \LogicException('Izin akses dukungan tidak bisa diubah selain dicabut: '.implode(', ', $terlarang).'.');
            }

            if ($akses->getOriginal('DicabutPada') !== null && $akses->isDirty('DicabutPada')) {
                throw new \LogicException('Izin akses dukungan yang sudah dicabut tidak bisa diubah lagi.');
            }
        });
        self::deleting(static function (): never {
            throw new \LogicException('Izin akses dukungan tidak pernah dihapus. Jejaknya dipertahankan.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisAksesDukungan::class,
            'Cakupan' => CakupanAksesDukungan::class,
            'KategoriDarurat' => KategoriAksesDarurat::class,
            'DataPribadiTerbuka' => 'boolean',
            'MulaiPada' => 'datetime',
            'BerakhirPada' => 'datetime',
            'DicabutPada' => 'datetime',
            'PemilikDiberitahuPada' => 'datetime',
        ];
    }
}
