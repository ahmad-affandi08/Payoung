<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\SimpanAnggaran;
use App\Domain\Akuntansi\Data\DataAnggaran;
use App\Domain\Akuntansi\Data\DataBarisAnggaran;
use App\Domain\Bersama\Nilai\Uang;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian halaman "Susun anggaran" (F8): tahun, outlet (kosong = seluruh usaha), dan tiap akun dengan dua belas angka
 * bulanan sebagai string desimal; angka kosong dianggap 0 (menghapus anggaran bulan itu).
 */
final class SimpanAnggaranPermintaan extends FormRequest
{
    private const POLA_UANG = '/^\d{1,16}(\.\d{1,2})?$/';

    /** Batas baris akun per penyimpanan. */
    public const MAKS_BARIS = 500;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Tahun' => ['required', 'integer', 'between:'.SimpanAnggaran::TAHUN_MINIMAL.','.SimpanAnggaran::TAHUN_MAKSIMAL],
            'Outlet' => ['nullable', 'string', 'ulid'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.self::MAKS_BARIS],
            'Baris.*.UuidAkun' => ['required', 'string', 'ulid'],
            'Baris.*.PerBulan' => ['required', 'array', 'size:12'],
            'Baris.*.PerBulan.*' => ['nullable', 'string', 'regex:'.self::POLA_UANG],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Baris.*.PerBulan.*.regex' => 'Anggaran harus angka Rupiah, maksimal 2 angka di belakang koma.',
            'Baris.*.PerBulan.size' => 'Anggaran harus berisi dua belas bulan.',
        ];
    }

    public function AmbilUuidOutlet(): ?string
    {
        $outlet = $this->validated('Outlet');

        return is_string($outlet) && $outlet !== '' ? $outlet : null;
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function AmbilData(?int $idOutlet, ?array $idOutletBoleh): DataAnggaran
    {
        $baris = [];

        foreach ((array) $this->validated('Baris') as $satu) {
            $isi = is_array($satu) ? $satu : [];
            $perBulan = [];

            foreach (array_values((array) ($isi['PerBulan'] ?? [])) as $nilai) {
                $perBulan[] = is_string($nilai) && $nilai !== '' ? Uang::Dari($nilai) : Uang::Nol();
            }

            $baris[] = new DataBarisAnggaran((string) ($isi['UuidAkun'] ?? ''), $perBulan);
        }

        return new DataAnggaran((int) $this->validated('Tahun'), $idOutlet, $baris, $idOutletBoleh);
    }
}
