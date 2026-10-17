<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola;

use App\Domain\Organisasi\Data\DataOutlet;
use App\Domain\Referensi\Enum\ZonaWaktu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SimpanOutletPermintaan extends FormRequest
{
    /** F-02 langkah 1: kode 3–5 karakter untuk penomoran dokumen, diawali huruf (misal UTAMA, JKT1). */
    public const POLA_KODE = '/^[A-Za-z][A-Za-z0-9]{2,4}$/';

    /** D-177: telepon outlet di kepala struk; angka, spasi, +, tanda kurung, strip, dan titik. */
    public const POLA_TELEPON = '/^[0-9+()\-\s.]{6,30}$/';

    /** Modul Salesman bagian 3: plat nomor kendaraan kanvas, huruf/angka/spasi/strip, misal "AD 1234 XY". */
    public const POLA_NOMOR_KENDARAAN = '/^[A-Za-z0-9][A-Za-z0-9 \-]*$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Nama' => ['required', 'string', 'max:150'],
            'Kode' => ['required', 'string', 'regex:'.self::POLA_KODE],
            'Merek' => ['required', 'string', 'size:26'],
            'Alamat' => ['nullable', 'string', 'max:500'],
            'Telepon' => ['nullable', 'string', 'max:30', 'regex:'.self::POLA_TELEPON],
            'KodeKota' => ['nullable', 'string', 'max:20'],
            'ZonaWaktu' => ['required', Rule::enum(ZonaWaktu::class)],
            'JamTutupBuku' => ['required', 'date_format:H:i'],
            'Pkp' => ['boolean'],
            'Nitku' => ['nullable', 'string', 'digits:22'],
            'PungutPbjt' => ['boolean'],
            'Kanvas' => ['sometimes', 'boolean'],
            'NomorKendaraan' => ['nullable', 'string', 'max:20', 'regex:'.self::POLA_NOMOR_KENDARAAN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Kode.regex' => 'Kode 3–5 karakter, diawali huruf, hanya huruf dan angka. Misal: RSY atau JKT1.',
            'Telepon.regex' => 'Telepon hanya angka, spasi, +, tanda kurung, strip, dan titik (6–30 karakter). Misal: 0271-123456 atau 0812-3456-7890.',
            'Merek.required' => 'Pilih merek.',
            'Merek.size' => 'Pilih merek yang tersedia.',
            'JamTutupBuku.date_format' => 'Jam tutup buku memakai format JJ:MM, misal 04:00.',
            'Nitku.digits' => 'NITKU terdiri dari 22 angka.',
            'NomorKendaraan.regex' => 'Nomor kendaraan hanya huruf, angka, dan spasi. Misal: AD 1234 XY.',
            'NomorKendaraan.max' => 'Nomor kendaraan paling banyak 20 karakter.',
        ];
    }

    public function AmbilData(): DataOutlet
    {
        return new DataOutlet(
            nama: $this->string('Nama')->toString(),
            kode: $this->string('Kode')->toString(),
            uuidMerek: $this->string('Merek')->toString(),
            alamat: $this->filled('Alamat') ? $this->string('Alamat')->toString() : null,
            kodeKota: $this->filled('KodeKota') ? $this->string('KodeKota')->toString() : null,
            zonaWaktu: $this->string('ZonaWaktu')->toString(),
            jamTutupBuku: $this->string('JamTutupBuku')->toString(),
            pkp: $this->boolean('Pkp'),
            nitku: $this->filled('Nitku') ? $this->string('Nitku')->toString() : null,
            pungutPbjt: $this->boolean('PungutPbjt'),
            // Tanpa kunci `Kanvas` (klien lama) = tanda kanvas tidak diubah.
            kanvas: $this->has('Kanvas') ? $this->boolean('Kanvas') : null,
            nomorKendaraan: $this->filled('NomorKendaraan') ? $this->string('NomorKendaraan')->toString() : null,
            // Tanpa kunci `Telepon` (klien lama) = tidak diubah; kunci ada tapi kosong = dikosongkan.
            telepon: $this->has('Telepon') ? ($this->filled('Telepon') ? trim($this->string('Telepon')->toString()) : '') : null,
        );
    }
}
