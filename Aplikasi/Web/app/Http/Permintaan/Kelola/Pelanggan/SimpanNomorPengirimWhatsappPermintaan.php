<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pelanggan;

use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah atau ubah nomor pengirim WhatsApp (D-299). `UuidOutlet` kosong = nomor tingkat usaha. */
final class SimpanNomorPengirimWhatsappPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Label' => ['required', 'string', 'min:2', 'max:60'],
            'Fungsi' => ['required', 'string', Rule::enum(FungsiNomorWhatsapp::class)],
            'UuidOutlet' => ['nullable', 'string', 'size:26'],
            'NomorTelepon' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'max:20'],
            'Aktif' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Label' => 'label', 'Fungsi' => 'fungsi', 'UuidOutlet' => 'outlet', 'NomorTelepon' => 'nomor WhatsApp'];
    }
}
