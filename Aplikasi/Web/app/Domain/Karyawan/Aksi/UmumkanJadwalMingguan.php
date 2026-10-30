<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Karyawan\Layanan\PengirimJadwalMingguan;
use App\Domain\Karyawan\Tugas\KirimJadwalMingguanTugas;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonImmutable;

/**
 * Umumkan jadwal mingguan satu outlet ke karyawan lewat WhatsApp (F-18, D-251, izin `karyawan.kelola`). Pengiriman lewat
 * antrean. Aksi ini memeriksa dulu bahwa WhatsApp siap dan ada penerima, lalu mengembalikan jumlah penerima. Audit
 * `jadwal-kerja.umumkan`.
 */
final class UmumkanJadwalMingguan
{
    public function __construct(
        private readonly PengirimJadwalMingguan $pengirim,
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly KonteksTenant $konteks,
        private readonly PencatatAudit $audit,
    ) {}

    /** @return int jumlah karyawan yang akan menerima pesan */
    public function Jalankan(int $idOutlet, CarbonImmutable $senin, int $idPengguna): int
    {
        $idTenant = $this->konteks->Wajib();

        if ($this->whatsapp->AmbilAktif() === null || ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            throw new PelanggaranAturanBisnis('WhatsappBelumSiap', 'WhatsApp belum aktif untuk usaha ini. Aktifkan dulu di Pengaturan › WhatsApp.', 'Umum');
        }

        $jumlah = count($this->pengirim->AmbilPenerima($idTenant, $idOutlet, $senin));

        if ($jumlah === 0) {
            throw new PelanggaranAturanBisnis('TanpaPenerima', 'Belum ada karyawan berjadwal yang punya akun dengan nomor WhatsApp pada minggu ini.', 'Umum');
        }

        KirimJadwalMingguanTugas::dispatch($idTenant, $idOutlet, $senin->toDateString());
        $this->audit->Catat('jadwal-kerja.umumkan', null, nilaiBaru: ['IdOutlet' => $idOutlet, 'Senin' => $senin->toDateString(), 'Penerima' => $jumlah], idPengguna: $idPengguna);

        return $jumlah;
    }
}
