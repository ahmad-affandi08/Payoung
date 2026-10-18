<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

/** Pengembalian dana yang diterima gerbang. `idRefund` = pengenal refund di gerbang, untuk pencocokan nanti. */
final readonly class HasilRefund
{
    public function __construct(public string $idRefund) {}
}
