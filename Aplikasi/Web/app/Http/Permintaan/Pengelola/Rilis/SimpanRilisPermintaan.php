<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Rilis;

use App\Domain\Organisasi\Enum\PlatformPerangkat;
use App\Domain\Pengelola\Rilis\Data\DataRilis;
use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Enum\KanalRilis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Draf rilis aplikasi (P-10). */
final class SimpanRilisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Aplikasi' => ['required', Rule::enum(AplikasiRilis::class)],
            'Platform' => ['required', Rule::enum(PlatformPerangkat::class)],
            'Kanal' => ['required', Rule::enum(KanalRilis::class)],
            'Versi' => ['required', 'string', 'max:30'],
            'Build' => ['nullable', 'integer', 'min:1', 'max:4000000000'],
            'UrlUnduh' => ['nullable', 'url:https', 'max:500'],
            // POS-23: checksum berkas unduhan (hex 64 karakter). Wajib untuk pemasang Windows yang punya tautan unduh,
            // karena aplikasi kasir Windows hanya memasang sendiri bila unduhannya cocok dengan checksum ini.
            'Sha256Unduhan' => [
                Rule::requiredIf(fn (): bool => $this->input('Platform') === PlatformPerangkat::Windows->value && $this->filled('UrlUnduh')),
                'nullable',
                'string',
                'regex:/^[0-9a-fA-F]{64}$/',
            ],
            'CatatanRilis' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Aplikasi.*' => 'Pilih aplikasi.',
            'Platform.*' => 'Pilih platform.',
            'Kanal.*' => 'Pilih kanal Beta atau Stabil.',
            'Versi.*' => 'Isi versi, misal 1.4.0.',
            'Build.*' => 'Nomor build berupa angka positif.',
            'UrlUnduh.*' => 'Tautan unduh harus alamat https.',
            'Sha256Unduhan.*' => 'Checksum SHA-256 berupa 64 karakter heksadesimal dan wajib untuk pemasang Windows.',
            'CatatanRilis.*' => 'Catatan rilis paling panjang 5000 karakter.',
        ];
    }

    public function AmbilData(): DataRilis
    {
        return new DataRilis(
            aplikasi: AplikasiRilis::from($this->string('Aplikasi')->toString()),
            platform: $this->string('Platform')->toString(),
            kanal: KanalRilis::from($this->string('Kanal')->toString()),
            versi: trim($this->string('Versi')->toString()),
            build: $this->filled('Build') ? $this->integer('Build') : null,
            urlUnduh: $this->filled('UrlUnduh') ? trim($this->string('UrlUnduh')->toString()) : null,
            catatanRilis: $this->filled('CatatanRilis') ? trim($this->string('CatatanRilis')->toString()) : null,
            sha256Unduhan: $this->filled('Sha256Unduhan') ? strtolower(trim($this->string('Sha256Unduhan')->toString())) : null,
        );
    }
}
