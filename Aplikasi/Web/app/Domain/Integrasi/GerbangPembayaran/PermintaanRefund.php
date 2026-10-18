<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

use App\Domain\Bersama\Nilai\Uang;

/** Permintaan pengembalian dana ke gerbang (`GerbangPembayaran::Refund`, hanya bila `KemampuanGerbang::Refund` didukung). */
final readonly class PermintaanRefund
{
    public function __construct(
        public string $nomorPesanan,
        public string $idReferensi,
        public Uang $jumlah,
        public string $alasan,
    ) {}
}
