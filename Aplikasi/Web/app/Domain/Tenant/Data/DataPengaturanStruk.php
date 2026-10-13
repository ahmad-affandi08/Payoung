<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Data;

/**
 * Pengaturan struk tingkat tenant (PLT-06, POS-11; PRD v1.79), satu untuk semua outlet, disimpan di
 * `Tenant.Pengaturan.Struk`. Teks null/kosong = memakai bawaan aplikasi (nama usaha, kalimat penutup bawaan).
 * Tanda air paket Gratis tidak diatur di sini (fitur paket `struk.tanpa-watermark`). `TampilkanStrukDigital`: QR &
 * tautan struk digital `/s/{kodeStruk}` di bagian bawah struk cetak. `TampilkanUmpanBalik` (F7, CRM-08): struk digital
 * meminta penilaian 1–5 bintang dari pelanggan.
 */
final readonly class DataPengaturanStruk
{
    public const PANJANG_BARIS_MAKSIMAL = 48;

    public const JUMLAH_TEKS_KEPALA_MAKSIMAL = 3;

    public const PANJANG_CATATAN_KAKI_MAKSIMAL = 200;

    /**
     * @param  list<string>  $teksKepala  Baris tambahan di bawah nama usaha (jam buka, media sosial), maks 3.
     */
    public function __construct(
        public bool $tampilkanLogo = true,
        public ?string $namaDicetak = null,
        public array $teksKepala = [],
        public bool $tampilkanAlamat = true,
        public bool $tampilkanTelepon = true,
        public bool $tampilkanNpwp = true,
        public bool $tampilkanKasir = true,
        public bool $tampilkanPelanggan = true,
        public bool $tampilkanHemat = true,
        public ?string $catatanKaki = null,
        public ?string $teksPenutup = null,
        public bool $tampilkanStrukDigital = true,
        public bool $tampilkanUmpanBalik = true,
    ) {}

    /**
     * Bentuk JSON (penyimpanan, halaman pengaturan, audit).
     *
     * @return array{TampilkanLogo: bool, NamaDicetak: string|null, TeksKepala: list<string>, TampilkanAlamat: bool, TampilkanTelepon: bool, TampilkanNpwp: bool, TampilkanKasir: bool, TampilkanPelanggan: bool, TampilkanHemat: bool, CatatanKaki: string|null, TeksPenutup: string|null, TampilkanStrukDigital: bool, TampilkanUmpanBalik: bool}
     */
    public function KeLarik(): array
    {
        return [
            'TampilkanLogo' => $this->tampilkanLogo,
            'NamaDicetak' => $this->namaDicetak,
            'TeksKepala' => $this->teksKepala,
            'TampilkanAlamat' => $this->tampilkanAlamat,
            'TampilkanTelepon' => $this->tampilkanTelepon,
            'TampilkanNpwp' => $this->tampilkanNpwp,
            'TampilkanKasir' => $this->tampilkanKasir,
            'TampilkanPelanggan' => $this->tampilkanPelanggan,
            'TampilkanHemat' => $this->tampilkanHemat,
            'CatatanKaki' => $this->catatanKaki,
            'TeksPenutup' => $this->teksPenutup,
            'TampilkanStrukDigital' => $this->tampilkanStrukDigital,
            'TampilkanUmpanBalik' => $this->tampilkanUmpanBalik,
        ];
    }
}
