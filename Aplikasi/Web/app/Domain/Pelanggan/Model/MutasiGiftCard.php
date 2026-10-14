<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use Illuminate\Support\Carbon;

/**
 * Buku gift card (CRM-06, §15 `MutasiGiftCard`), append-only: saldo kartu = Σ `Jumlah` (bertanda); `SaldoSetelah` =
 * saldo kartu tepat setelah baris ini (diisi di bawah kunci baris kartu).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdGiftCard
 * @property JenisMutasiGiftCard $Jenis
 * @property string $Jumlah
 * @property string $SaldoSetelah
 * @property SumberMutasiGiftCard $JenisSumber
 * @property int|null $IdSumber
 * @property string|null $NomorSumber
 * @property Carbon $Tanggal
 * @property int|null $IdAkunKasBank
 * @property int|null $IdJurnal
 * @property string|null $Keterangan
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatPada
 */
final class MutasiGiftCard extends ModelDasar
{
    use MilikTenant;

    protected $table = 'MutasiGiftCard';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisMutasiGiftCard::class,
            'JenisSumber' => SumberMutasiGiftCard::class,
            'Jumlah' => 'decimal:2',
            'SaldoSetelah' => 'decimal:2',
            'Tanggal' => 'date',
        ];
    }
}
