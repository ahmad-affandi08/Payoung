<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Layanan\PenerapKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\DB;

/**
 * Membuat (atau mengganti) pasangan kunci RSA SNAP untuk integrasi DOKU Payoung (D-159). Kunci pribadi masuk ke
 * kredensial terenkripsi; kunci publik dikembalikan dan juga tersimpan agar bisa disalin lagi untuk didaftarkan di DOKU.
 *
 * Status dan keaktifan integrasi **tidak diubah**: kunci ini hanya menambah kemampuan SNAP dan tidak menyentuh akun
 * DOKU Checkout yang sedang dipakai (BR-P05.4 berlaku untuk perubahan pengaturan/kredensial yang dipakai ulang oleh
 * Checkout, bukan untuk kunci baru yang belum didaftarkan). Mengganti kunci memutus SNAP sampai kunci publik barunya
 * didaftarkan ulang di DOKU, jadi tindakan ini dicatat di audit dengan sidik jarinya.
 */
final class BuatKunciSnapIntegrasi
{
    public function __construct(
        private readonly PembuatKunciSnap $pembuat,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, KonfigurasiIntegrasi $konfigurasi): string
    {
        if ($konfigurasi->Penyedia !== PenyediaIntegrasi::DokuBilling) {
            throw new PelanggaranAturanBisnis('BukanDokuBilling', 'Kunci SNAP hanya untuk integrasi akun DOKU Payoung (induk).');
        }

        $pasangan = $this->pembuat->Buat();
        $sudahAda = isset($konfigurasi->Kredensial['KunciPublikSnap']);

        DB::transaction(function () use ($konfigurasi, $pasangan, $pelaku, $sudahAda): void {
            $konfigurasi->Kredensial = [
                ...$konfigurasi->Kredensial,
                'KunciPribadiSnap' => $pasangan['Pribadi'],
                'KunciPublikSnap' => $pasangan['Publik'],
            ];
            $konfigurasi->KredensialDiubahPada = now();
            $konfigurasi->save();

            $this->audit->Catat(
                $sudahAda ? 'integrasi.kunci-snap-diganti' : 'integrasi.kunci-snap-dibuat',
                $konfigurasi,
                nilaiBaru: ['SidikJariKunciPublik' => PembuatKunciSnap::BuatSidikJari($pasangan['Publik']), 'Pelaku' => $pelaku->Id],
            );
        });

        PenerapKonfigurasiIntegrasi::LupakanCache();

        return $pasangan['Publik'];
    }
}
