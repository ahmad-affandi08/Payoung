<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Menyimpan kampanye email prospek sebagai draf (D-216). Belum ada yang dikirim: kampanye baru berjalan setelah petugas
 * menjalankannya (`UbahStatusKampanyeProspek`), yang menetapkan penerimanya. Kampanye tanpa kontak aktif di sektor
 * terpilih ditolak.
 */
final class BuatKampanyeProspek
{
    public const MAKS_BATAS_PER_HARI = 500;

    public const BATAS_BAWAAN_PER_HARI = 40;

    public function __construct(
        private readonly SegmenKampanyeProspek $segmen,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @param  list<string>  $sektor
     */
    public function Jalankan(PenggunaPengelola $pelaku, string $nama, string $subjek, string $isi, array $sektor, int $batasPerHari): KampanyeProspek
    {
        $sektor = SegmenKampanyeProspek::BersihkanSektor($sektor);

        if ($sektor === []) {
            throw new PelanggaranAturanBisnis('P-12', 'Pilih sedikitnya satu sektor penerima.', 'Sektor');
        }

        if ($batasPerHari < 1 || $batasPerHari > self::MAKS_BATAS_PER_HARI) {
            throw new PelanggaranAturanBisnis('P-12', 'Batas kirim per hari harus antara 1 dan '.self::MAKS_BATAS_PER_HARI.'.', 'BatasPerHari');
        }

        if ($this->segmen->Hitung($sektor) === 0) {
            throw new PelanggaranAturanBisnis('P-12', 'Tidak ada kontak aktif di sektor yang dipilih. Impor kontak atau ganti sektornya.', 'Sektor');
        }

        $kampanye = KampanyeProspek::query()->create([
            'Nama' => trim($nama),
            'Subjek' => trim($subjek),
            'Isi' => trim($isi),
            'Segmen' => ['Sektor' => $sektor],
            'Status' => StatusKampanyeProspek::Draf,
            'BatasPerHari' => $batasPerHari,
            'IdPenggunaPengelola' => $pelaku->Id,
        ]);

        $this->audit->Catat(
            'prospek.kampanye.buat',
            $kampanye,
            nilaiBaru: ['Nama' => $kampanye->Nama, 'Sektor' => $sektor, 'BatasPerHari' => $batasPerHari],
            idPelaku: $pelaku->Id,
        );

        return $kampanye;
    }
}
