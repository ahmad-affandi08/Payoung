<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Integrasi\Whatsapp\MeterKuotaWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Penjualan\Model\NotifikasiPesananOnline;

/**
 * F-17 bagian 3: sakelar "kabari pembeli lewat WhatsApp" menyala bawaan, tetapi pesan hanya terkirim bila toko punya
 * nomor WhatsApp sendiri atau kuota nomor Payoung. Tanpa keduanya tugas kirim gagal diam-diam: pembeli tidak tahu
 * pesanannya ditolak atau siap diambil, dan toko tidak tahu pesannya tidak terkirim. Halaman Toko online memakai
 * jawaban ini untuk memperingatkan pemilik dan menghitung pesan yang gagal.
 */
final class KesiapanNotifikasiPesananOnline
{
    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly MeterKuotaWhatsapp $meter,
    ) {}

    /**
     * @return array{Siap: bool, Alasan: string|null, GagalBulanIni: int}
     */
    public function Ambil(int $idTenant): array
    {
        $gagal = NotifikasiPesananOnline::query()
            ->whereNull('TerkirimPada')
            ->whereNotNull('Galat')
            ->where('DibuatPada', '>=', now()->subDays(30))
            ->count();

        if ($this->whatsapp->AmbilMilikTenant() !== null) {
            return ['Siap' => true, 'Alasan' => null, 'GagalBulanIni' => $gagal];
        }

        if ($this->whatsapp->AmbilAktifPlatform() === null) {
            return ['Siap' => false, 'Alasan' => 'WhatsApp belum tersambung, jadi pembeli tidak menerima kabar pesanan.', 'GagalBulanIni' => $gagal];
        }

        $ringkasan = $this->meter->AmbilRingkasan($idTenant);

        if ($ringkasan['Sisa'] !== null && $ringkasan['Sisa'] <= 0) {
            $alasan = ($ringkasan['Batas'] ?? 0) <= 0
                ? 'Paket Anda tidak menyertakan kuota pesan WhatsApp. Sambungkan nomor WhatsApp toko sendiri agar pembeli menerima kabar pesanan.'
                : 'Kuota pesan WhatsApp bulan ini sudah habis, jadi pembeli tidak menerima kabar pesanan.';

            return ['Siap' => false, 'Alasan' => $alasan, 'GagalBulanIni' => $gagal];
        }

        return ['Siap' => true, 'Alasan' => null, 'GagalBulanIni' => $gagal];
    }
}
