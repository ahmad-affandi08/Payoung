<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu kali masuk petugas ke back-office tenant (PGL-16). Token tukar hanya disimpan sebagai hash dan hanya sekali
 * pakai; setelah dipakai, sesi berlaku sampai `BerakhirPada`, diakhiri, atau izin induknya dicabut/kedaluwarsa.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdAksesDukungan
 * @property int $IdPenggunaPengelola
 * @property string $NamaPetugas
 * @property string $HashToken
 * @property Carbon $TokenKedaluwarsaPada
 * @property Carbon|null $TokenDipakaiPada
 * @property Carbon|null $MulaiPada
 * @property Carbon $BerakhirPada
 * @property Carbon|null $DiakhiriPada
 * @property AlasanAkhirSesiDukungan|null $AlasanAkhir
 * @property string|null $Ip
 * @property string|null $AgenPengguna
 * @property-read AksesDukungan $Akses
 */
final class SesiAksesDukungan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'SesiAksesDukungan';

    /** @var list<string> */
    protected $hidden = ['HashToken'];

    /** @var array<string, mixed> */
    protected $attributes = ['TokenDipakaiPada' => null, 'MulaiPada' => null, 'DiakhiriPada' => null, 'AlasanAkhir' => null, 'Ip' => null, 'AgenPengguna' => null];

    /**
     * @return BelongsTo<AksesDukungan, $this>
     */
    public function Akses(): BelongsTo
    {
        return $this->belongsTo(AksesDukungan::class, 'IdAksesDukungan', 'Id');
    }

    /** Sudah ditukar, belum diakhiri, belum lewat batas waktunya. */
    public function CekBerjalan(?CarbonInterface $saat = null): bool
    {
        $saat ??= now();

        return $this->TokenDipakaiPada !== null && $this->DiakhiriPada === null && $this->BerakhirPada->gt($saat);
    }

    protected static function booted(): void
    {
        self::updating(static function (SesiAksesDukungan $sesi): void {
            $diubah = array_keys($sesi->getDirty());
            $terlarang = array_diff($diubah, ['TokenDipakaiPada', 'MulaiPada', 'BerakhirPada', 'DiakhiriPada', 'AlasanAkhir', 'Ip', 'AgenPengguna', 'DiubahPada']);

            // Batas waktu hanya dihitung ulang saat token ditukar (sejak itu umur sesi dihitung dari penukaran, dibatasi
            // sisa umur izin); sesudahnya tidak boleh diperpanjang atau diubah.
            if (in_array('BerakhirPada', $diubah, true) && ($sesi->getOriginal('TokenDipakaiPada') !== null || ! in_array('TokenDipakaiPada', $diubah, true))) {
                $terlarang[] = 'BerakhirPada';
            }

            if ($terlarang !== []) {
                throw new \LogicException('Sesi akses dukungan tidak bisa diubah selain dipakai atau diakhiri: '.implode(', ', $terlarang).'.');
            }
        });
        self::deleting(static function (): never {
            throw new \LogicException('Sesi akses dukungan tidak pernah dihapus. Jejaknya dipertahankan.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'TokenKedaluwarsaPada' => 'datetime',
            'TokenDipakaiPada' => 'datetime',
            'MulaiPada' => 'datetime',
            'BerakhirPada' => 'datetime',
            'DiakhiriPada' => 'datetime',
            'AlasanAkhir' => AlasanAkhirSesiDukungan::class,
        ];
    }
}
