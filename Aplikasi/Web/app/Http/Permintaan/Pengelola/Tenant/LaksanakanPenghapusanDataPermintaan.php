<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Tenant;

use App\Domain\Tenant\Model\Tenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Melaksanakan penghapusan data (UU PDP, P-07): tindakan yang tidak bisa dibalik, jadi pelaksana wajib menulis catatan
 * verifikasi identitas Owner dan mengetik ulang slug tenant. Tulisan slug yang keliru ditolak sebelum aksi dipanggil.
 */
final class LaksanakanPenghapusanDataPermintaan extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'Catatan' => ['required', 'string', 'min:10', 'max:500'],
            'KonfirmasiSlug' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Catatan.min' => 'Tulis catatan minimal 10 karakter, misalnya cara identitas Owner diverifikasi.',
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

    public function AmbilCatatan(): string
    {
        return trim($this->string('Catatan')->toString());
    }
}
