<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tagihan;

use App\Domain\Tenant\Enum\SiklusTagihan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tagihan manual paket harga negosiasi (P-08, D-112): paket, siklus, harga kesepakatan sebelum PPN, keterangan kontrak. */
final class TerbitkanTagihanManualPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'KodePaket' => ['required', 'string', 'max:30'],
            'Siklus' => ['required', 'string', Rule::enum(SiklusTagihan::class)],
            'Subtotal' => ['required', 'string', 'regex:/^[1-9]\d{0,13}(\.\d{1,2})?$/'],
            'Keterangan' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['KodePaket' => 'paket', 'Siklus' => 'siklus tagihan', 'Subtotal' => 'harga kesepakatan', 'Keterangan' => 'keterangan'];
    }

    public function AmbilSiklus(): SiklusTagihan
    {
        return SiklusTagihan::from($this->string('Siklus')->toString());
    }

    public function AmbilKeterangan(): string
    {
        return $this->string('Keterangan')->trim()->toString();
    }
}
