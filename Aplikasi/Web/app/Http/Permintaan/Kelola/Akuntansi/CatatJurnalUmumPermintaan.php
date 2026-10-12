<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\CatatJurnalUmum;
use App\Domain\Akuntansi\Data\DataBarisJurnalUmum;
use App\Domain\Akuntansi\Data\DataJurnalUmum;
use App\Domain\Akuntansi\Enum\JenisJurnalUmum;
use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Isian jurnal umum manual (FIN-05). Uang sebagai string desimal (bukan float); sisi yang kosong dianggap 0. Outlet baris
 * diubah ke Id oleh kontroler (batas outlet pelaku). `UuidKlien` dibuat formulir sekali per halaman (idempotensi).
 */
final class CatatJurnalUmumPermintaan extends FormRequest
{
    private const POLA_UANG = '/^\d{1,16}(\.\d{1,2})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidKlien' => ['required', 'string', 'ulid'],
            'Jenis' => ['required', 'string', Rule::enum(JenisJurnalUmum::class)],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Referensi' => ['nullable', 'string', 'max:100'],
            'Keterangan' => ['required', 'string', 'max:255'],
            'Baris' => ['required', 'array', 'min:2', 'max:'.CatatJurnalUmum::MAKS_BARIS],
            'Baris.*.UuidAkun' => ['required', 'string', 'ulid'],
            'Baris.*.UuidOutlet' => ['nullable', 'string', 'ulid'],
            'Baris.*.Debit' => ['nullable', 'string', 'regex:'.self::POLA_UANG],
            'Baris.*.Kredit' => ['nullable', 'string', 'regex:'.self::POLA_UANG],
            'Baris.*.Memo' => ['nullable', 'string', 'max:255'],
            'Lampiran' => [
                'nullable',
                'file',
                'mimes:'.implode(',', (array) config('akuntansi.EkstensiLampiran')),
                'max:'.(int) config('akuntansi.UkuranMaksimalLampiranKb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Baris.min' => 'Jurnal minimal dua baris: sekurangnya satu debit dan satu kredit.',
            'Baris.*.Debit.regex' => 'Debit harus angka Rupiah, maksimal 2 angka di belakang koma.',
            'Baris.*.Kredit.regex' => 'Kredit harus angka Rupiah, maksimal 2 angka di belakang koma.',
            'Baris.*.UuidAkun.required' => 'Pilih akun untuk baris ini.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'Jenis' => 'jenis jurnal',
            'Tanggal' => 'tanggal',
            'Referensi' => 'referensi',
            'Keterangan' => 'keterangan',
            'Baris' => 'baris jurnal',
            'Lampiran' => 'lampiran',
        ];
    }

    /**
     * Semua Uuid outlet yang disebut baris (kontroler memetakannya ke Id sesuai batas akses).
     *
     * @return list<string>
     */
    public function AmbilUuidOutlet(): array
    {
        $uuid = [];

        foreach ((array) $this->validated('Baris') as $baris) {
            $outlet = is_array($baris) ? ($baris['UuidOutlet'] ?? null) : null;

            if (is_string($outlet) && $outlet !== '') {
                $uuid[$outlet] = $outlet;
            }
        }

        return array_values($uuid);
    }

    /**
     * @param  Closure(string): int  $petaOutlet  Uuid outlet → Id (abort 404 bila di luar akses)
     * @param  list<int>|null  $idOutletBoleh
     */
    public function AmbilData(Closure $petaOutlet, ?int $idPengguna, ?array $idOutletBoleh): DataJurnalUmum
    {
        $baris = [];

        foreach ((array) $this->validated('Baris') as $satu) {
            $satu = (array) $satu;
            $outlet = $satu['UuidOutlet'] ?? null;
            $memo = $satu['Memo'] ?? null;
            $baris[] = new DataBarisJurnalUmum(
                (string) $satu['UuidAkun'],
                is_string($outlet) && $outlet !== '' ? $petaOutlet($outlet) : null,
                Uang::Dari(is_string($satu['Debit'] ?? null) && $satu['Debit'] !== '' ? $satu['Debit'] : '0'),
                Uang::Dari(is_string($satu['Kredit'] ?? null) && $satu['Kredit'] !== '' ? $satu['Kredit'] : '0'),
                is_string($memo) ? $memo : null,
            );
        }

        $lampiran = $this->file('Lampiran');
        $referensi = $this->validated('Referensi');

        return new DataJurnalUmum(
            (string) $this->validated('UuidKlien'),
            JenisJurnalUmum::from((string) $this->validated('Jenis')),
            CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('Tanggal')) ?: CarbonImmutable::today(),
            is_string($referensi) ? $referensi : null,
            (string) $this->validated('Keterangan'),
            $baris,
            $lampiran instanceof UploadedFile ? $lampiran : null,
            $idPengguna,
            $idOutletBoleh,
        );
    }
}
