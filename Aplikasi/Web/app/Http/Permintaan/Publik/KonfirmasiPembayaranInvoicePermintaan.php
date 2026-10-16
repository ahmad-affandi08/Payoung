<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Publik;

use App\Domain\Bersama\Nilai\Uang;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian "sudah bayar" di halaman publik invoice (F-21 bagian 4, D-169): nama pengirim, tanggal bayar, jumlah, dan catatan.
 * Tanpa login; aturan bisnisnya (sisa tagihan, rentang tanggal, batas klaim menunggu) ditegakkan oleh aksinya.
 */
final class KonfirmasiPembayaranInvoicePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'min:2', 'max:100'],
            'TanggalBayar' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => ['required', 'string', AturanGrosir::UANG],
            'Catatan' => ['nullable', 'string', 'max:300'],
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
        return ['Nama' => 'nama pengirim', 'TanggalBayar' => 'tanggal bayar', 'Jumlah' => 'jumlah', 'Catatan' => 'catatan'];
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return AturanGrosir::Tanggal($this->validated('TanggalBayar')) ?? CarbonImmutable::today();
    }

    public function AmbilJumlah(): Uang
    {
        return AturanGrosir::Uang($this->validated('Jumlah'));
    }
}
