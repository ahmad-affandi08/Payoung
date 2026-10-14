<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use Illuminate\Support\Carbon;

/**
 * Gift card (CRM-06, D-153, §15 `GiftCard`): kartu bernilai berkode yang bisa dipakai membayar di semua outlet tenant.
 * `Saldo` adalah cache Σ `MutasiGiftCard.Jumlah` (dibangun ulang dari buku). `Kode` rahasia pemegang kartu: tidak
 * pernah ditulis ke log atau audit, dan di daftar back-office hanya sebagian yang tampak.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Kode
 * @property string $NilaiAwal
 * @property string $Saldo
 * @property Carbon|null $BerlakuSampai
 * @property StatusGiftCard $Status
 * @property int|null $IdPelanggan
 * @property string|null $NamaPemegang
 * @property string|null $Catatan
 * @property int|null $IdPenerbit
 * @property Carbon $DiterbitkanPada
 */
final class GiftCard extends ModelDasar
{
    use MilikTenant;

    protected $table = 'GiftCard';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusGiftCard::class,
            'NilaiAwal' => 'decimal:2',
            'Saldo' => 'decimal:2',
            'BerlakuSampai' => 'date',
            'DiterbitkanPada' => 'datetime',
        ];
    }
}
