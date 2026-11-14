<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Illuminate\Foundation\Http\FormRequest;

/** Harga per pesan broadcast WhatsApp dan minimal isi saldo, Rupiah penuh (D-300). */
final class UbahPengaturanSaldoWhatsappPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'HargaPerPesan' => ['required', 'string', 'regex:/^\d{1,6}$/'],
            'TopUpMinimal' => ['required', 'string', 'regex:/^\d{1,8}$/'],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['HargaPerPesan' => 'harga per pesan', 'TopUpMinimal' => 'minimal isi saldo', 'Alasan' => 'alasan perubahan'];
    }

    public function AmbilAlasan(): string
    {
        return $this->string('Alasan')->trim()->toString();
    }
}
