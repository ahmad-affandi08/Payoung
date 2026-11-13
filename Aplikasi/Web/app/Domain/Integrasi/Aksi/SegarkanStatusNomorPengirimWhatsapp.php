<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;

/**
 * D-299: menanyakan status sambungan nomor ke penyedia dan menyimpannya. Nomor yang sudah pernah terhubung lalu
 * putus menjadi `Terputus` dan otomatis dimatikan supaya tidak dipilih sebagai pengirim. Kegagalan menghubungi
 * penyedia tidak mengubah status (tidak menuduh nomor putus karena jaringan).
 */
final class SegarkanStatusNomorPengirimWhatsapp
{
    public function __construct(
        private readonly PembuatGerbangSesiWhatsapp $pembuatGerbang,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(NomorPengirimWhatsapp $nomor): NomorPengirimWhatsapp
    {
        $gerbang = $this->pembuatGerbang->Ambil();

        if ($gerbang === null || $nomor->TokenPerangkat === null) {
            return $nomor;
        }

        $hasil = $gerbang->PeriksaStatus($nomor->TokenPerangkat);

        if (! $hasil->berhasil) {
            $nomor->update(['DiperiksaPada' => now(), 'PesanStatus' => $hasil->pesan]);

            return $nomor;
        }

        return $this->Terapkan($nomor, $hasil->terhubung);
    }

    public function Terapkan(NomorPengirimWhatsapp $nomor, bool $terhubung): NomorPengirimWhatsapp
    {
        $lama = $nomor->Status;

        if ($terhubung) {
            $nomor->update([
                'Status' => StatusNomorWhatsapp::Terhubung, 'PesanStatus' => null, 'DiperiksaPada' => now(),
                'TerhubungPada' => $lama === StatusNomorWhatsapp::Terhubung ? $nomor->TerhubungPada : now(),
                'PeringatanTerputusPada' => null,
                'Aktif' => $lama === StatusNomorWhatsapp::MenungguPindai ? true : $nomor->Aktif,
            ]);
        } elseif ($lama === StatusNomorWhatsapp::MenungguPindai) {
            $nomor->update(['DiperiksaPada' => now()]);
        } else {
            $nomor->update(['Status' => StatusNomorWhatsapp::Terputus, 'Aktif' => false, 'PesanStatus' => 'Nomor terputus dari WhatsApp. Pindai QR lagi.', 'DiperiksaPada' => now()]);
        }

        if ($lama !== $nomor->Status) {
            $this->audit->Catat('nomor-whatsapp.status', $nomor, ['Status' => $lama->value], ['Status' => $nomor->Status->value]);
        }

        return $nomor;
    }
}
