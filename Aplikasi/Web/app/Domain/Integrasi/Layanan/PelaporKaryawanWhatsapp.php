<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Layanan;

use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;

/**
 * D-299 tahap 3: kirim laporan otomatis ke semua penerima outlet (maksimal 6) lewat nomor pengirim berfungsi
 * Karyawan atau Umum. Sakelar jenisnya diperiksa di `PengirimPesanOtomatisWhatsapp`. Dipanggil dengan konteks tenant.
 */
final class PelaporKaryawanWhatsapp
{
    public function __construct(private readonly PengirimPesanOtomatisWhatsapp $pengirim, private readonly PengaturPesanWhatsapp $pengatur) {}

    /** @return int jumlah penerima yang berhasil dikirimi */
    public function Kirim(JenisPesanWhatsapp $jenis, int $idOutlet, string $teks): int
    {
        if (! $this->pengatur->Aktif($jenis)) {
            return 0;
        }

        $terkirim = 0;

        foreach (PenerimaLaporanWhatsapp::query()->where('IdOutlet', $idOutlet)->orderBy('Id')->get() as $penerima) {
            if ($this->pengirim->Kirim($jenis, $idOutlet, $penerima->NomorTelepon, $teks)) {
                $terkirim++;
            }
        }

        return $terkirim;
    }

    public function SakelarAktif(JenisPesanWhatsapp $jenis): bool
    {
        return $this->pengatur->Aktif($jenis);
    }

    /** @return list<int> Id outlet yang punya penerima laporan. */
    public function AmbilIdOutletPenerima(): array
    {
        return array_values(array_map(intval(...), PenerimaLaporanWhatsapp::query()->distinct()->pluck('IdOutlet')->all()));
    }

    public function AdaPenerima(int $idOutlet): bool
    {
        return PenerimaLaporanWhatsapp::query()->where('IdOutlet', $idOutlet)->exists();
    }
}
