<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

/**
 * Galat panggilan DOKU Partner API. `tidakPasti` = false hanya untuk penolakan 4xx pasti atas isi permintaan kita;
 * selain itu (jaringan, 5xx, kredensial platform ditolak, batas laju) hasilnya belum pasti dan boleh dicoba lagi.
 * Pesan sudah disaring dari rahasia sebelum dilempar.
 */
final class GalatPartnerDoku extends GalatPendaftaranMerchant {}
