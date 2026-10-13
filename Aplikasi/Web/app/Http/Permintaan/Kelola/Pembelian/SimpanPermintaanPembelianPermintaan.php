<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembelian;

use App\Domain\Pembelian\Aksi\SimpanPesananPembelian;
use App\Domain\Pembelian\Data\DataBarisPesananPembelian;
use App\Domain\Pembelian\Data\DataPermintaanPembelian;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Isian draf permintaan pembelian (F-04 PR); lokasi stok diubah ke Id oleh kontroler (batas outlet). */
final class SimpanPermintaanPembelianPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidGudang' => ['required', 'string', 'ulid'],
            'UuidPemasok' => ['nullable', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'DibutuhkanPada' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:Tanggal'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.SimpanPesananPembelian::MAKS_BARIS],
            'Baris.*.UuidProduk' => ['required', 'string', 'ulid'],
            'Baris.*.UuidProdukSatuan' => ['nullable', 'string', 'ulid'],
            'Baris.*.Jumlah' => ['required', 'string', AturanPembelian::JUMLAH],
            'Baris.*.Harga' => ['nullable', 'string', AturanPembelian::UANG],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AturanPembelian::Pesan();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudang' => 'lokasi stok', 'UuidPemasok' => 'pemasok', 'Tanggal' => 'tanggal', 'DibutuhkanPada' => 'tanggal dibutuhkan', 'Baris' => 'barang', 'Baris.*.Jumlah' => 'jumlah', 'Baris.*.Harga' => 'harga perkiraan'];
    }

    public function AmbilData(int $idGudang, int $idPengguna): DataPermintaanPembelian
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return new DataPermintaanPembelian(
            $idGudang,
            AturanPembelian::Teks($this->validated('UuidPemasok')),
            AturanPembelian::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            AturanPembelian::Tanggal($this->validated('DibutuhkanPada')),
            AturanPembelian::Teks($this->validated('Catatan')),
            array_map(fn (array $b): DataBarisPesananPembelian => new DataBarisPesananPembelian(
                (string) $b['UuidProduk'],
                AturanPembelian::Teks($b['UuidProdukSatuan'] ?? null),
                AturanPembelian::Jumlah($b['Jumlah']),
                AturanPembelian::Uang($b['Harga'] ?? null),
                AturanPembelian::Uang(null),
            ), $baris),
            $idPengguna,
        );
    }
}
