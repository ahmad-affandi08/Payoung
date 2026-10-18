<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use RuntimeException;

/**
 * Galat pendaftaran merchant ke penyedia pembayaran (colokan `PendaftarMerchantPembayaran`, D-179). `tidakPasti` = false
 * hanya untuk penolakan pasti atas isi permintaan kita (4xx); selain itu (jaringan, 5xx, kredensial platform ditolak,
 * batas laju) hasilnya belum pasti dan boleh dicoba lagi. Pesan sudah disaring dari rahasia sebelum dilempar.
 * Adaptor melempar turunannya (DOKU: `GalatPartnerDoku`); kode di luar adaptor menangkap kelas ini.
 */
class GalatPendaftaranMerchant extends RuntimeException
{
    public function __construct(string $pesan, public readonly bool $tidakPasti = false, public readonly ?int $statusHttp = null)
    {
        parent::__construct($pesan);
    }
}
