<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Integrasi\Billing\GerbangBillingPlatform;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Tenant\Aksi\TerimaNotifikasiBillingLangganan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * P-08 langkah 3 (PRD v4.06): rekonsiliasi pembayaran langganan lewat gerbang yang masih `Menunggu` karena notifikasi
 * webhook tidak pernah tiba (misal URL webhook salah atau gerbang sempat gagal mengirim). Tanpa ini pembayaran
 * tersangkut `Menunggu` selamanya dan pembatalan tagihan ikut terblokir (celah yang tercatat di BR-P08.11).
 *
 * Status ditanyakan ke API status gerbang, lalu hasilnya diproses **jalur yang sama dengan webhook**
 * (`TerimaNotifikasiBillingLangganan`): lunas memperpanjang langganan lewat `PelunasTagihanLangganan`, gagal/kedaluwarsa
 * menolak pembayaran sehingga tagihan bisa dibayar ulang atau dibatalkan, masih menunggu dibiarkan. Idempoten karena
 * jalur itu sendiri idempoten. Hanya pembayaran yang lebih tua dari `MENIT_TUNGGU_WEBHOOK` yang ditanyakan (webhook
 * diberi kesempatan lebih dulu) dan tidak lebih tua dari `HARI_JENDELA` (pembayaran berjumlah beda yang menunggu
 * verifikasi manual tidak ditanyakan selamanya), paling banyak `BATAS_PER_PUTARAN` per putaran.
 *
 * @phpstan-type Hasil array{Diperiksa: int, Selesai: int, Menunggu: int, Gagal: int}
 */
final class RekonsiliasiPembayaranGerbangLangganan
{
    public const MENIT_TUNGGU_WEBHOOK = 15;

    public const BATAS_PER_PUTARAN = 100;

    /** Pembayaran yang lebih tua dari ini (misal jumlah berbeda, menunggu verifikasi manual) tidak ditanyakan lagi. */
    public const HARI_JENDELA = 7;

    /** Putaran harian untuk pembayaran lama: yang lebih tua dari ini tidak ditanyakan lagi sama sekali. */
    public const HARI_ARSIP = 90;

    public function __construct(
        private readonly GerbangBillingPlatform $gerbang,
        private readonly TerimaNotifikasiBillingLangganan $terima,
    ) {}

    /**
     * @return Hasil
     */
    public function Jalankan(): array
    {
        $hasil = ['Diperiksa' => 0, 'Selesai' => 0, 'Menunggu' => 0, 'Gagal' => 0];

        if (! $this->gerbang->CekAktif()) {
            return $hasil;
        }

        $daftar = DaftarTagihanPlatform::KueriPembayaran()
            ->where('Metode', MetodePembayaranLangganan::Gateway->value)
            ->where('Status', StatusPembayaranLangganan::Menunggu->value)
            ->whereNotNull('RefGateway')
            ->where('DibuatPada', '<=', CarbonImmutable::now()->subMinutes(self::MENIT_TUNGGU_WEBHOOK))
            ->where('DibuatPada', '>=', CarbonImmutable::now()->subDays(self::HARI_JENDELA))
            ->orderBy('DibuatPada')
            ->limit(self::BATAS_PER_PUTARAN)
            ->get(['Id', 'IdTenant', 'RefGateway', 'DibuatPada']);

        foreach ($daftar as $pembayaran) {
            $hasil['Diperiksa']++;
            $notifikasi = $this->gerbang->CekStatus((string) $pembayaran->RefGateway, $pembayaran->DibuatPada ?? CarbonImmutable::now());

            if ($notifikasi === null) {
                $hasil['Gagal']++;

                continue;
            }

            if ($notifikasi->status === StatusPembayaranGerbang::Menunggu) {
                $hasil['Menunggu']++;

                continue;
            }

            try {
                $this->terima->Jalankan($notifikasi) ? $hasil['Selesai']++ : $hasil['Gagal']++;
            } catch (Throwable $galat) {
                report($galat);
                $hasil['Gagal']++;
            }
        }

        return $hasil;
    }

    /**
     * Putaran harian untuk pembayaran yang sudah lewat `HARI_JENDELA` tetapi masih `Menunggu` (misal API status gerbang
     * tidak terjangkau sepanjang jendela). Tanpa ini pembayaran itu tersangkut selamanya: tagihannya tidak bisa dibatalkan
     * atau dibayar ulang, dan penangguhan tunggakan tertunda. Yang ditutup hanya yang gerbang nyatakan **tidak dibayar**
     * (kedaluwarsa/gagal). Yang gerbang nyatakan lunas tetapi tidak bisa dilunasi otomatis (misal jumlah berbeda) dan
     * yang masih menunggu dibiarkan: itu antrean verifikasi manual, bukan pembayaran yang boleh ditutup diam-diam.
     *
     * @return Hasil
     */
    public function JalankanArsip(): array
    {
        $hasil = ['Diperiksa' => 0, 'Selesai' => 0, 'Menunggu' => 0, 'Gagal' => 0];

        if (! $this->gerbang->CekAktif()) {
            return $hasil;
        }

        $daftar = DaftarTagihanPlatform::KueriPembayaran()
            ->where('Metode', MetodePembayaranLangganan::Gateway->value)
            ->where('Status', StatusPembayaranLangganan::Menunggu->value)
            ->whereNotNull('RefGateway')
            ->where('DibuatPada', '<', CarbonImmutable::now()->subDays(self::HARI_JENDELA))
            ->where('DibuatPada', '>=', CarbonImmutable::now()->subDays(self::HARI_ARSIP))
            ->orderBy('DibuatPada')
            ->limit(self::BATAS_PER_PUTARAN)
            ->get(['Id', 'IdTenant', 'RefGateway', 'DibuatPada']);

        foreach ($daftar as $pembayaran) {
            $hasil['Diperiksa']++;
            $notifikasi = $this->gerbang->CekStatus((string) $pembayaran->RefGateway, $pembayaran->DibuatPada ?? CarbonImmutable::now());

            if ($notifikasi === null) {
                $hasil['Gagal']++;

                continue;
            }

            if (in_array($notifikasi->status, [StatusPembayaranGerbang::Menunggu, StatusPembayaranGerbang::Lunas], true)) {
                $hasil['Menunggu']++;

                continue;
            }

            try {
                $this->terima->Jalankan($notifikasi) ? $hasil['Selesai']++ : $hasil['Gagal']++;
            } catch (Throwable $galat) {
                report($galat);
                $hasil['Gagal']++;
            }
        }

        return $hasil;
    }
}
