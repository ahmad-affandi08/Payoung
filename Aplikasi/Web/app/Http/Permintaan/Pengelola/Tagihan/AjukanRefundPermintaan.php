<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Pengajuan refund penuh tagihan lunas (P-08, BR-P08.2, D-112). */
final class AjukanRefundPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Alasan' => ['required', 'string', 'min:10', 'max:500'],
            'CatatanPengembalian' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Alasan' => 'alasan refund', 'CatatanPengembalian' => 'catatan pengembalian dana'];
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }

    public function AmbilCatatanPengembalian(): ?string
    {
        $catatan = $this->string('CatatanPengembalian')->trim()->toString();

        return $catatan === '' ? null : $catatan;
    }
}
