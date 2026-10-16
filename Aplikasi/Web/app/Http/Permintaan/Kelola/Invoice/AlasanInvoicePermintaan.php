<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Invoice;

use Illuminate\Foundation\Http\FormRequest;

/** Alasan pembatalan invoice (F-21), 5–255 karakter. */
final class AlasanInvoicePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['Alasan' => ['required', 'string', 'min:5', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Alasan' => 'alasan'];
    }

    public function AmbilAlasan(): string
    {
        return (string) $this->validated('Alasan');
    }
}
