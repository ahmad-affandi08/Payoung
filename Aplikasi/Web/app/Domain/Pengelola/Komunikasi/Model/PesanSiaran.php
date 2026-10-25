<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusSiaran;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Siaran Payoung ke pemilik tenant terpilih (D-210). `Segmen` menyimpan aturan penerima, bukan daftar: daftar penerima
 * ditetapkan saat pengiriman dimulai (`PenerimaSiaran`).
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Judul
 * @property string $Isi
 * @property KanalSiaran $Kanal
 * @property array{Status?: list<string>, Paket?: list<string>, Kesehatan?: list<string>, Tenant?: list<string>} $Segmen
 * @property StatusSiaran $Status
 * @property Carbon $JadwalKirimPada
 * @property Carbon|null $MulaiKirimPada
 * @property Carbon|null $SelesaiPada
 * @property int $JumlahPenerima
 * @property int $JumlahTerkirim
 * @property int $JumlahGagal
 * @property int $JumlahDilewati
 * @property int $IdPenggunaPengelola
 */
final class PesanSiaran extends ModelDasar
{
    protected $table = 'PesanSiaran';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'Kanal' => KanalSiaran::class,
            'Status' => StatusSiaran::class,
            'Segmen' => 'array',
            'JadwalKirimPada' => 'datetime',
            'MulaiKirimPada' => 'datetime',
            'SelesaiPada' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PenerimaSiaran, $this>
     */
    public function Penerima(): HasMany
    {
        return $this->hasMany(PenerimaSiaran::class, 'IdPesanSiaran', 'Id');
    }
}
