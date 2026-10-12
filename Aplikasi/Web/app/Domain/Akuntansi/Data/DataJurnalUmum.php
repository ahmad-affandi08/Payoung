<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

use App\Domain\Akuntansi\Enum\JenisJurnalUmum;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Masukan `CatatJurnalUmum` (FIN-05, D-131). `uuidKlien` dibuat formulir sekali per halaman supaya kiriman ganda
 * menghasilkan satu dokumen. `idOutletBoleh` = batas outlet pelaku (null = semua): pelaku berbatas outlet hanya boleh
 * menjurnal baris yang jelas di outlet aksesnya.
 */
final readonly class DataJurnalUmum
{
    /**
     * @param  list<DataBarisJurnalUmum>  $baris
     * @param  list<int>|null  $idOutletBoleh
     */
    public function __construct(
        public string $uuidKlien,
        public JenisJurnalUmum $jenis,
        public CarbonImmutable $tanggal,
        public ?string $referensi,
        public string $keterangan,
        public array $baris,
        public ?UploadedFile $lampiran,
        public ?int $idPengguna,
        public ?array $idOutletBoleh = null,
    ) {}
}
