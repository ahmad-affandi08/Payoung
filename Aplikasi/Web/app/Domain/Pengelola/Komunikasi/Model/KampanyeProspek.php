<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use Illuminate\Support\Carbon;

/**
 * Kampanye email ke kontak prospek (D-216). `Segmen` menyimpan aturan (`Sektor`: daftar kode), sedangkan penerimanya
 * ditetapkan saat kampanye dijalankan (`PenerimaKampanyeProspek`).
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Nama
 * @property string $Subjek
 * @property string $Isi
 * @property array{Sektor?: list<string>} $Segmen
 * @property StatusKampanyeProspek $Status
 * @property int $BatasPerHari
 * @property string|null $AlasanJeda
 * @property Carbon|null $MulaiKirimPada
 * @property Carbon|null $SelesaiPada
 * @property int $JumlahPenerima
 * @property int $JumlahTerkirim
 * @property int $JumlahGagal
 * @property int $JumlahDilewati
 * @property int $IdPenggunaPengelola
 */
final class KampanyeProspek extends ModelDasar
{
    protected $table = 'KampanyeProspek';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusKampanyeProspek::class,
            'Segmen' => 'array',
            'MulaiKirimPada' => 'datetime',
            'SelesaiPada' => 'datetime',
        ];
    }
}
