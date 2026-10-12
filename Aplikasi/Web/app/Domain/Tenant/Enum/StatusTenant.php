<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Status tenant (F-00). `Ditutup` = akun ditutup lewat penghapusan data (UU PDP, P-07): data pribadi sudah dihapus,
 * data transaksi tinggal teranonimkan, dan tidak ada yang bisa masuk lagi. Status ini final.
 */
enum StatusTenant: string
{
    case Aktif = 'Aktif';
    case Ditutup = 'Ditutup';
}
