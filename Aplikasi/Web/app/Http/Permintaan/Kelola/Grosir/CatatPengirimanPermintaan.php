<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use App\Domain\Penjualan\Data\DataPengirimanGrosir;
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian kabar pengiriman surat jalan (F-12 §9.7). Langkah (berangkat, diterima, gagal, batal-berangkat) datang dari
 * alamat; isian yang wajib per langkah diperiksa Aksinya dengan kode galat yang menunjuk bidangnya.
 */
final class CatatPengirimanPermintaan extends FormRequest
{
    public const LANGKAH = ['berangkat' => StatusPengirimanGrosir::DalamPerjalanan, 'diterima' => StatusPengirimanGrosir::Diterima, 'gagal' => StatusPengirimanGrosir::Gagal, 'batal-berangkat' => StatusPengirimanGrosir::Disiapkan];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidArmada' => ['nullable', 'string', 'ulid'],
            'NamaPengemudi' => ['nullable', 'string', 'max:100'],
            'NamaPenerima' => ['nullable', 'string', 'max:100'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Waktu' => ['nullable', 'date_format:Y-m-d H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidArmada' => 'armada', 'NamaPengemudi' => 'nama pengemudi', 'NamaPenerima' => 'nama penerima', 'Catatan' => 'catatan', 'Waktu' => 'waktu'];
    }

    public function AmbilData(string $uuidSuratJalan, string $langkah): DataPengirimanGrosir
    {
        $waktu = $this->validated('Waktu');

        return new DataPengirimanGrosir(
            uuidSuratJalan: $uuidSuratJalan,
            tujuan: self::LANGKAH[$langkah],
            uuidArmada: AturanGrosir::Teks($this->validated('UuidArmada')),
            namaPengemudi: AturanGrosir::Teks($this->validated('NamaPengemudi')),
            namaPenerima: AturanGrosir::Teks($this->validated('NamaPenerima')),
            catatan: AturanGrosir::Teks($this->validated('Catatan')),
            waktuLokal: is_string($waktu) && $waktu !== '' ? $waktu : null,
        );
    }
}
