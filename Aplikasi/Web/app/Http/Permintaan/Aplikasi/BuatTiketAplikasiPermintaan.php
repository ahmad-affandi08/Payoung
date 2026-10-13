<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Aplikasi;

use App\Domain\Dukungan\Enum\KategoriTiketDukungan;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tiket dukungan baru dari aplikasi (Kasir dan Owner, P-09): tanpa lampiran berkas; konteks otomatis dari server,
 * `LayarAsal` dan `LogSingkat` dari aplikasi (disaring server). Aplikasi Kasir menambah `UuidPengguna` (staf pelapor).
 */
class BuatTiketAplikasiPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Kategori' => ['required', 'string', Rule::enum(KategoriTiketDukungan::class)],
            'Prioritas' => ['nullable', 'string', Rule::enum(PrioritasTiketDukungan::class)],
            'Judul' => ['required', 'string', 'min:5', 'max:150'],
            'Isi' => ['required', 'string', 'min:10', 'max:10000'],
            'LayarAsal' => ['nullable', 'string', 'max:100'],
            'LogSingkat' => ['nullable', 'array', 'max:50'],
            'LogSingkat.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function AmbilKategori(): KategoriTiketDukungan
    {
        return KategoriTiketDukungan::from($this->string('Kategori')->toString());
    }

    public function AmbilPrioritas(): PrioritasTiketDukungan
    {
        return $this->filled('Prioritas') ? PrioritasTiketDukungan::from($this->string('Prioritas')->toString()) : PrioritasTiketDukungan::Normal;
    }

    public function AmbilJudul(): string
    {
        return $this->string('Judul')->trim()->toString();
    }

    public function AmbilIsi(): string
    {
        return str_replace("\r\n", "\n", $this->string('Isi')->trim()->toString());
    }

    public function AmbilLayarAsal(): ?string
    {
        return $this->filled('LayarAsal') ? $this->string('LayarAsal')->trim()->toString() : null;
    }

    /**
     * @return array<mixed>
     */
    public function AmbilLog(): array
    {
        $log = $this->input('LogSingkat', []);

        return is_array($log) ? $log : [];
    }
}
