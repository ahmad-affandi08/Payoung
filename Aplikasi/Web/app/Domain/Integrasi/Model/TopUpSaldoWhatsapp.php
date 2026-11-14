<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use Illuminate\Support\Carbon;

/**
 * Pembayaran isi saldo WhatsApp lewat gerbang billing platform (D-300). Lunas hanya dari notifikasi atau status
 * yang dikonfirmasi ke penyedia, tidak pernah dari peramban.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Jumlah
 * @property StatusTopUpSaldoWhatsapp $Status
 * @property string|null $RefGateway
 * @property string|null $UrlBayar
 * @property int|null $IdPengguna
 * @property Carbon|null $DibayarPada
 * @property Carbon|null $DibuatPada
 */
final class TopUpSaldoWhatsapp extends ModelDasar
{
    use MilikTenant;

    /** Top-up yang belum dibayar setelah ini dianggap kedaluwarsa (jam). */
    public const JAM_KEDALUWARSA = 24;

    protected $table = 'TopUpSaldoWhatsapp';

    /** @var list<string> */
    protected $hidden = ['UrlBayar'];

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Menunggu'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusTopUpSaldoWhatsapp::class, 'DibayarPada' => 'datetime'];
    }
}
