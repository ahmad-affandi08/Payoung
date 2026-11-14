<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\JenisMutasiSaldoWhatsapp;
use Illuminate\Support\Carbon;

/**
 * Mutasi saldo WhatsApp (D-300), append-only. `IdOutlet` kosong = saldo utama, terisi = kredit broadcast outlet.
 * `(IdTenant, Jenis, Referensi)` unik, jadi pemakaian per pesan dan top-up idempoten.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int|null $IdOutlet
 * @property JenisMutasiSaldoWhatsapp $Jenis
 * @property string $Jumlah
 * @property string $SaldoSetelah
 * @property string|null $Referensi
 * @property string|null $Keterangan
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatPada
 */
final class MutasiSaldoWhatsapp extends ModelDasar
{
    use MilikTenant;

    protected $table = 'MutasiSaldoWhatsapp';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jenis' => JenisMutasiSaldoWhatsapp::class];
    }
}
