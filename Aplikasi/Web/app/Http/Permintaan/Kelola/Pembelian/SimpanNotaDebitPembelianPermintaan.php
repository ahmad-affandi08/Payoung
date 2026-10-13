<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembelian;

use App\Domain\Pembelian\Enum\JenisNotaDebit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Isian formulir nota debit pembelian (v6.07). Aturan bisnis (sisa nilai faktur, batas persetujuan) di aksinya. */
final class SimpanNotaDebitPembelianPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidFaktur' => ['required', 'string', 'size:26'],
            'Jenis' => ['required', Rule::enum(JenisNotaDebit::class)],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'NilaiKlaim' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'Baris' => ['nullable', 'array', 'max:200'],
            'Baris.*' => ['integer', 'min:1'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
            'NomorNotaPemasok' => ['nullable', 'string', 'max:60'],
            'UuidAkunRefund' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['NilaiKlaim.*' => 'Isi nilai nota debit dalam rupiah, misal 150000.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Alasan' => 'alasan', 'Jenis' => 'jenis', 'Tanggal' => 'tanggal', 'NomorNotaPemasok' => 'nomor nota pemasok'];
    }

    public function AmbilUuidFaktur(): string
    {
        return strtoupper((string) $this->validated('UuidFaktur'));
    }

    public function AmbilJenis(): JenisNotaDebit
    {
        return JenisNotaDebit::from((string) $this->validated('Jenis'));
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('Tanggal')) ?: CarbonImmutable::today();
    }

    public function AmbilNilaiKlaim(): string
    {
        return (string) $this->validated('NilaiKlaim');
    }

    /**
     * @return list<int>
     */
    public function AmbilIdBaris(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('Baris') ?? [])));
    }

    public function AmbilAlasan(): string
    {
        return (string) $this->validated('Alasan');
    }

    public function AmbilNomorNotaPemasok(): ?string
    {
        $nilai = $this->validated('NomorNotaPemasok');

        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }

    public function AmbilUuidAkunRefund(): ?string
    {
        $nilai = $this->validated('UuidAkunRefund');

        return is_string($nilai) && $nilai !== '' ? strtoupper($nilai) : null;
    }
}
