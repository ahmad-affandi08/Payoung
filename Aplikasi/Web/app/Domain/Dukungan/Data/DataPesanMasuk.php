<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Data;

use App\Domain\Dukungan\Enum\KanalTiketDukungan;

/**
 * Satu pesan masuk dari penyedia email/WhatsApp, sudah diseragamkan oleh pintu masuk (PGL-15). `terverifikasi` = penyedia
 * menyatakan pengirimnya lolos SPF/DKIM (email); WhatsApp tidak memakainya karena nomor pengirim sudah dijamin WhatsApp.
 */
final readonly class DataPesanMasuk
{
    public function __construct(
        public KanalTiketDukungan $kanal,
        public string $dari,
        public string $idPesan,
        public string $subjek,
        public string $isi,
        public bool $terverifikasi,
        public ?string $nomorTiket = null,
    ) {}
}
