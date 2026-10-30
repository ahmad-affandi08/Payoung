<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant yang masih beroperasi (akunnya belum ditutup, UU PDP P-07), untuk domain lain yang menyapu semua tenant.
 * Dikembalikan sebagai subkueri `Id`, bukan daftar id: pada puluhan ribu tenant daftar id yang dimasukkan ke `whereIn`
 * melampaui batas parameter MySQL.
 */
final class TenantAktif
{
    /** @return Builder<Tenant> */
    public function KueriId(): Builder
    {
        return Tenant::query()->where('Status', '<>', StatusTenant::Ditutup->value)->select('Id');
    }
}
