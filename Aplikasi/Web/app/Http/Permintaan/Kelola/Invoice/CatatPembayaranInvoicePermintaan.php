<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Invoice;

use App\Domain\Bersama\Nilai\Uang;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian pembayaran satu invoice (F-21): akun kas/bank tujuan, tanggal, jumlah yang dilunasi (kosong = seluruh sisa), PPh
 * yang dipotong pelanggan beserta nomor bukti potongnya, dan catatan.
 */
final class CatatPembayaranInvoicePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidAkun' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => ['nullable', 'string', AturanGrosir::UANG],
            'PotonganPph' => ['nullable', 'string', AturanGrosir::UANG],
            'NomorBuktiPotong' => ['nullable', 'string', 'max:40'],
            'Catatan' => ['nullable', 'string', 'max:500'],
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
        return ['UuidAkun' => 'akun kas/bank', 'Tanggal' => 'tanggal', 'Jumlah' => 'jumlah', 'PotonganPph' => 'potongan PPh', 'NomorBuktiPotong' => 'nomor bukti potong'];
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return AturanGrosir::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today();
    }

    public function AmbilJumlah(): ?Uang
    {
        $jumlah = AturanGrosir::Teks($this->validated('Jumlah'));

        return $jumlah === null ? null : Uang::Dari($jumlah);
    }

    public function AmbilPotonganPph(): Uang
    {
        return AturanGrosir::Uang($this->validated('PotonganPph'));
    }
}
