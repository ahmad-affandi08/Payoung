<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tenant;

use App\Domain\Tenant\Model\Tenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Menghapus permanen satu tenant uji (D-213): tidak bisa dibalik, jadi pelaksana wajib menulis alasan dan mengetik ulang
 * slug tenant. Slug yang keliru ditolak sebelum aksi dipanggil; syarat penanda dan tagihan diperiksa aksinya.
 */
final class HapusPermanenTenantPermintaan extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'Alasan' => ['required', 'string', 'min:10', 'max:500'],
            'KonfirmasiSlug' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Alasan.min' => 'Tulis alasan minimal 10 karakter, misalnya "tenant percobaan fitur kasir, sudah tidak dipakai".',
            'KonfirmasiSlug.required' => 'Ketik slug tenant untuk memastikan Anda menghapus tenant yang benar.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tenant = $this->route('tenant');

            if ($tenant instanceof Tenant && ! $validator->errors()->has('KonfirmasiSlug') && trim($this->string('KonfirmasiSlug')->toString()) !== $tenant->Slug) {
                $validator->errors()->add('KonfirmasiSlug', 'Slug yang diketik tidak sama dengan slug tenant ini.');
            }
        });
    }

    public function AmbilAlasan(): string
    {
        return trim($this->string('Alasan')->toString());
    }

    public function AmbilKonfirmasiSlug(): string
    {
        return trim($this->string('KonfirmasiSlug')->toString());
    }
}
