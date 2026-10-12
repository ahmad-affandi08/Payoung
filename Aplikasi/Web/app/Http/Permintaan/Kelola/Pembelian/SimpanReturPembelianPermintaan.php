<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembelian;

use App\Domain\Pembelian\Data\DataBarisReturPembelian;
use App\Domain\Pembelian\Data\DataReturPembelian;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Isian retur pembelian (F-04 fase 1): GRN, alasan, dan jumlah (satuan dasar) / nomor seri per baris GRN. */
final class SimpanReturPembelianPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPenerimaan' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
            'UuidAkunRefund' => ['nullable', 'string', 'ulid'],
            'Baris' => ['required', 'array', 'min:1', 'max:500'],
            'Baris.*.IdBarisPenerimaan' => ['required', 'integer', 'min:1'],
            'Baris.*.Jumlah' => ['required', 'string', AturanPembelian::JUMLAH],
            'Baris.*.NomorSeri' => ['nullable', 'array', 'max:1000'],
            'Baris.*.NomorSeri.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AturanPembelian::Pesan();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidPenerimaan' => 'penerimaan barang', 'Tanggal' => 'tanggal', 'Alasan' => 'alasan', 'UuidAkunRefund' => 'akun penerima pengembalian uang', 'Baris' => 'barang', 'Baris.*.Jumlah' => 'jumlah retur'];
    }

    public function AmbilData(int $idPengguna): DataReturPembelian
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return new DataReturPembelian(
            (string) $this->validated('UuidPenerimaan'),
            AturanPembelian::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            (string) $this->validated('Alasan'),
            array_map(fn (array $b): DataBarisReturPembelian => new DataBarisReturPembelian(
                (int) $b['IdBarisPenerimaan'],
                AturanPembelian::Jumlah($b['Jumlah']),
                array_values(array_map('strval', (array) ($b['NomorSeri'] ?? []))),
            ), $baris),
            $idPengguna,
            is_string($this->validated('UuidAkunRefund')) ? (string) $this->validated('UuidAkunRefund') : null,
        );
    }
}
