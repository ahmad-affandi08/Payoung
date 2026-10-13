<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use App\Domain\Penjualan\Data\DataBarisReturGrosir;
use App\Domain\Penjualan\Data\DataReturGrosir;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian retur grosir (BR-12.7). Baris menunjuk **Urutan** baris surat jalan; harga, HPP, dan pajaknya tidak dikirim
 * klien karena seluruhnya disalin dari snapshot penyerahan.
 */
final class BuatReturGrosirPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidSuratJalan' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'UuidAkunRefund' => ['nullable', 'string', 'ulid'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.SimpanPesananGrosirPermintaan::MAKS_BARIS],
            'Baris.*.Urutan' => ['required', 'integer', 'min:1'],
            'Baris.*.Jumlah' => ['required', 'string', AturanGrosir::JUMLAH],
            'Baris.*.Kondisi' => ['nullable', Rule::enum(KondisiBarangRetur::class)],
            'Baris.*.NomorSeri' => ['nullable', 'array', 'max:500'],
            'Baris.*.NomorSeri.*' => ['string', 'max:60'],
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
        return [
            'UuidSuratJalan' => 'surat jalan',
            'Tanggal' => 'tanggal',
            'Alasan' => 'alasan',
            'UuidAkunRefund' => 'akun pengembalian uang',
            'Baris' => 'barang',
            'Baris.*.Jumlah' => 'jumlah',
            'Baris.*.Kondisi' => 'kondisi barang',
        ];
    }

    public function AmbilData(): DataReturGrosir
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return new DataReturGrosir(
            uuidSuratJalan: (string) $this->validated('UuidSuratJalan'),
            tanggal: AturanGrosir::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            alasan: (string) $this->validated('Alasan'),
            baris: array_map(fn (array $b): DataBarisReturGrosir => new DataBarisReturGrosir(
                (int) $b['Urutan'],
                AturanGrosir::Jumlah($b['Jumlah']),
                KondisiBarangRetur::tryFrom(is_string($b['Kondisi'] ?? null) ? (string) $b['Kondisi'] : '') ?? KondisiBarangRetur::LayakJual,
                AturanGrosir::DaftarTeks($b['NomorSeri'] ?? null),
            ), $baris),
            catatan: AturanGrosir::Teks($this->validated('Catatan')),
            uuidAkunRefund: AturanGrosir::Teks($this->validated('UuidAkunRefund')),
        );
    }
}
