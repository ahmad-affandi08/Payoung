<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Penawaran;

use App\Domain\Penjualan\Enum\KeputusanPenawaran;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Keputusan atas penawaran yang dikonfirmasi penagih (diterima/ditolak lewat telepon atau email), F-21 bagian 3. */
final class KeputusanPenawaranPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Keputusan' => ['required', 'string', Rule::in(array_map(fn (KeputusanPenawaran $k): string => $k->value, KeputusanPenawaran::cases()))],
            'Nama' => ['nullable', 'string', 'max:100'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Keputusan' => 'keputusan', 'Nama' => 'nama penyetuju', 'Catatan' => 'catatan'];
    }

    public function AmbilKeputusan(): KeputusanPenawaran
    {
        return KeputusanPenawaran::from((string) $this->validated('Keputusan'));
    }
}
