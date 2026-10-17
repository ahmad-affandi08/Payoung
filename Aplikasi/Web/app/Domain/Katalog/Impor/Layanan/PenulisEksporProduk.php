<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataSaringProduk;
use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Impor\Kueri\DataEksporProduk;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor daftar produk (F-03) ke xlsx/csv dengan saringan aktif, dialirkan per 500 produk; kolom = templat impor
 * (bisa diimpor kembali). Audit `produk.ekspor` (format & saringan). Juga templat impor kosong (judul kolom saja; kolom wajib berakhiran ` *`, merah di xlsx).
 */
final class PenulisEksporProduk
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly DataEksporProduk $data,
        private readonly PencatatAudit $audit,
    ) {}

    public function Alirkan(DataSaringProduk $saring, string $format): StreamedResponse
    {
        $idTenant = $this->konteks->Wajib();
        $this->audit->Catat('produk.ekspor', null, null, [
            'Format' => $format,
            'Kata' => $saring->kata,
            'IdKategori' => $saring->idKategori,
            'Jenis' => $saring->jenis?->value,
            'Status' => $saring->status === null ? 'Semua' : $saring->status->value,
        ]);

        return PenulisTabel::Alirkan($format, 'produk-'.now()->format('Ymd-His'), DataEksporProduk::AmbilJudul(), function () use ($idTenant, $saring): Generator {
            // Respons dialirkan setelah kontroler selesai: pastikan scope tenant tetap tenant pengekspor.
            $this->konteks->Atur($idTenant);
            // Perpanjang batas waktu ekspor besar hanya bila proses memang dibatasi (web); proses tanpa batas
            // (CLI, antrean) tidak boleh diberi batas baru yang mematikannya di tengah jalan.
            $batas = (int) ini_get('max_execution_time');

            if ($batas > 0) {
                set_time_limit(max(120, $batas));
            }

            yield from $this->data->AmbilBaris($saring);
        });
    }

    public function AlirkanTemplat(string $format): StreamedResponse
    {
        $kolomWajib = [];

        foreach (BidangImpor::AmbilDiekspor() as $indeks => $bidang) {
            if ($bidang->CekWajib()) {
                $kolomWajib[] = $indeks;
            }
        }

        return PenulisTabel::Alirkan($format, 'templat-impor-produk', DataEksporProduk::AmbilJudul(), fn (): array => [], $kolomWajib);
    }
}
