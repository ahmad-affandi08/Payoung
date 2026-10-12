<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\HasilUjiLayanan;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uji koneksi WhatsApp tenant lewat `UjiKoneksi` adaptor, tanpa mengirim pesan. Panggilan jaringan di luar transaksi
 * DB; hasil dibuang bila isian berubah selama pengujian, supaya status `Berhasil` selalu milik isian yang benar-benar
 * diuji. Pesan tidak pernah memuat kredensial.
 */
final class UjiWhatsappTenant
{
    public function __construct(
        private readonly PembuatPengirimWhatsapp $pembuat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(): HasilUjiLayanan
    {
        $baris = WhatsappTenant::query()->first()
            ?? throw new PelanggaranAturanBisnis('WhatsappBelumDiatur', 'Simpan pengaturan WhatsApp dulu sebelum mengujinya.');
        $isiDiuji = self::AmbilIsi($baris);
        $hasil = $this->Uji($baris);

        return DB::transaction(function () use ($baris, $isiDiuji, $hasil): HasilUjiLayanan {
            $terkini = WhatsappTenant::query()->lockForUpdate()->findOrFail($baris->Id);

            if (self::AmbilIsi($terkini) !== $isiDiuji) {
                return HasilUjiLayanan::Gagal('Isian WhatsApp berubah saat diuji. Uji ulang.');
            }

            $terkini->update([
                'StatusUji' => $hasil->berhasil ? StatusUjiGerbang::Berhasil : StatusUjiGerbang::Gagal,
                'PesanUji' => mb_substr($hasil->pesan, 0, 300),
                'DiujiPada' => now(),
            ]);
            $this->audit->Catat('whatsapp-tenant.uji', $terkini, nilaiBaru: ['Penyedia' => $terkini->Penyedia->value, 'Berhasil' => $hasil->berhasil]);

            return $hasil;
        });
    }

    private function Uji(WhatsappTenant $baris): HasilUjiLayanan
    {
        $pengirim = $this->pembuat->BuatDariTenant($baris);

        if ($pengirim === null) {
            return HasilUjiLayanan::Gagal('Kredensial tersimpan tidak bisa dibaca. Isi ulang kredensial lalu simpan.');
        }

        try {
            return $pengirim->UjiKoneksi();
        } catch (Throwable $galat) {
            $pesan = $galat->getMessage();

            foreach ($baris->Kredensial as $rahasia) {
                $pesan = $rahasia === '' ? $pesan : str_replace($rahasia, '••••', $pesan);
            }

            return HasilUjiLayanan::Gagal('Uji gagal: '.Str::limit($pesan, 250));
        }
    }

    /**
     * @return array<int, mixed>
     */
    private static function AmbilIsi(WhatsappTenant $baris): array
    {
        return [$baris->Penyedia, $baris->Pengaturan, $baris->Kredensial];
    }
}
