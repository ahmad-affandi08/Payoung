<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Pembayaran tagihan manual yang uangnya sudah masuk ke rekening (P-08, D-112). */
final class CatatPembayaranManualPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'JumlahDiterima' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'TanggalTransfer' => ['required', 'date_format:Y-m-d'],
            'NamaPengirim' => ['required', 'string', 'max:150'],
            'BankPengirim' => ['nullable', 'string', 'max:100'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['JumlahDiterima' => 'jumlah diterima', 'TanggalTransfer' => 'tanggal transfer', 'NamaPengirim' => 'nama pengirim', 'BankPengirim' => 'bank pengirim', 'Catatan' => 'catatan'];
    }

    public function AmbilTanggalTransfer(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('TanggalTransfer')->toString(), 'Asia/Jakarta') ?: CarbonImmutable::now('Asia/Jakarta');
    }

    public function AmbilBankPengirim(): ?string
    {
        $bank = $this->string('BankPengirim')->trim()->toString();

        return $bank === '' ? null : $bank;
    }

    public function AmbilCatatan(): ?string
    {
        $catatan = $this->string('Catatan')->trim()->toString();

        return $catatan === '' ? null : $catatan;
    }
}
