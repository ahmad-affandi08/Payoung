<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Invoice;

use App\Domain\Penjualan\Aksi\SimpanInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian draf invoice (F-21, D-166). **Angka turunan tidak ada di sini**: total, pajak, PPh, dan potongan DP
 * dihitung server dari baris, jadi tidak ada yang bisa dikarang dari peramban. Harga baris memang dikirim klien karena
 * invoice berisi kesepakatan dengan pelanggan (jasa, proyek), bukan harga katalog. Outlet diubah ke Id oleh kontroler
 * (batas outlet pelaku). Aturan isian dipakai bersama templat invoice berulang lewat `AturanInvoice`.
 */
final class SimpanInvoicePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...AturanInvoice::Aturan(),
            'Jenis' => ['required', 'string', Rule::in(array_map(fn (JenisInvoice $j): string => $j->value, JenisInvoice::cases()))],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'UuidUangMuka' => ['nullable', 'array', 'max:'.SimpanInvoice::MAKS_UANG_MUKA],
            'UuidUangMuka.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AturanGrosir::Pesan();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...AturanInvoice::Atribut(), 'Tanggal' => 'tanggal'];
    }

    public function AmbilData(int $idOutlet): DataInvoice
    {
        return AturanInvoice::BuatData(
            $this->validated(),
            JenisInvoice::from((string) $this->validated('Jenis')),
            $idOutlet,
            AturanGrosir::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            array_values(array_map('strval', (array) ($this->validated('UuidUangMuka') ?? []))),
        );
    }
}
