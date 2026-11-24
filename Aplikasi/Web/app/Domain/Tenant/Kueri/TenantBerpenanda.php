<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Model\Tenant;

/**
 * Apakah tenant non-komersial (berpenanda Uji, Demo, atau Internal, P-07). Dipakai domain lain untuk membatasi fitur yang
 * hanya boleh dipakai tenant uji, misalnya memakai akun DOKU Payoung sebagai gerbang QRIS (D-316).
 */
final class TenantBerpenanda
{
    public function Cek(int $idTenant): bool
    {
        return Tenant::query()->whereKey($idTenant)->whereNotNull('Penanda')->exists();
    }
}
