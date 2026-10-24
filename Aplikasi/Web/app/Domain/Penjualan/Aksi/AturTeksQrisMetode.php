<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Layanan\PemeriksaQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use Illuminate\Support\Facades\DB;

/**
 * F-08 (D-189): mengaktifkan atau mematikan nominal otomatis untuk metode QRIS statis yang sudah ada. Teks payload
 * (hasil baca gambar QR di peramban) diperiksa lebih dulu; `null` mematikan nominal otomatis dan kasir kembali
 * menampilkan gambar. Gambar yang tersimpan tidak berubah.
 */
final class AturTeksQrisMetode
{
    public function __construct(
        private readonly PemeriksaQris $pemeriksa,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(MetodePembayaran $metode, ?string $teksQris): MetodePembayaran
    {
        if ($metode->Jenis !== JenisMetodePembayaran::QrisStatis) {
            throw new PelanggaranAturanBisnis('BukanQrisStatis', 'Nominal otomatis hanya untuk metode QRIS statis.', 'TeksQris');
        }

        $teks = $teksQris === null || trim($teksQris) === '' ? null : trim($teksQris);

        if ($teks !== null) {
            $this->pemeriksa->Periksa($teks);
        }

        return DB::transaction(function () use ($metode, $teks): MetodePembayaran {
            $metode = MetodePembayaran::query()->lockForUpdate()->findOrFail($metode->Id);

            if ($metode->TeksQris === $teks) {
                return $metode;
            }

            $lama = $metode->TeksQris !== null;
            $metode->TeksQris = $teks;
            $metode->save();
            $this->audit->Catat('metode-pembayaran.teks-qris', $metode, nilaiLama: ['NominalOtomatis' => $lama], nilaiBaru: ['NominalOtomatis' => $teks !== null]);

            return $metode;
        });
    }
}
