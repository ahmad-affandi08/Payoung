<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Piutang;

use App\Domain\Pelanggan\Aksi\CatatBuktiPotongPelunasan;
use Illuminate\Foundation\Http\FormRequest;

/** Nomor bukti potong PPh yang diterima dari pelanggan untuk satu pelunasan (F-21 bagian 5). */
final class BuktiPotongPelunasanPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['NomorBuktiPotong' => ['required', 'string', 'max:'.CatatBuktiPotongPelunasan::PANJANG_MAKSIMAL]];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['NomorBuktiPotong' => 'nomor bukti potong'];
    }

    public function AmbilNomor(): string
    {
        return (string) $this->validated('NomorBuktiPotong');
    }
}
