<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataSuratJalan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian surat jalan grosir (BR-12.2). Barisnya menunjuk **Urutan** baris SO, bukan Id basis data, dan harga tidak
 * dikirim: seluruhnya disalin dari snapshot SO yang sudah dikonfirmasi.
 */
final class KirimPesananGrosirPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidGudang' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'NamaPengirim' => ['nullable', 'string', 'max:100'],
            'NomorKendaraan' => ['nullable', 'string', 'max:30'],
            'NamaPenerima' => ['nullable', 'string', 'max:100'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.SimpanPesananGrosirPermintaan::MAKS_BARIS],
            'Baris.*.Urutan' => ['required', 'integer', 'min:1'],
            'Baris.*.Jumlah' => ['required', 'string', AturanGrosir::JUMLAH],
            // Produk bernomor seri: satu nomor per unit.
            'Baris.*.NomorSeri' => ['nullable', 'array', 'max:500'],
            'Baris.*.NomorSeri.*' => ['string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AturanGrosir::Pesan();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudang' => 'lokasi stok', 'Tanggal' => 'tanggal', 'Baris' => 'barang', 'Baris.*.Jumlah' => 'jumlah'];
    }

    public function AmbilData(string $uuidPesanan, int $idGudang): DataSuratJalan
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return new DataSuratJalan(
            uuidPesanan: $uuidPesanan,
            idGudang: $idGudang,
            tanggal: AturanGrosir::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            baris: array_map(fn (array $b): DataBarisSuratJalan => new DataBarisSuratJalan(
                (int) $b['Urutan'],
                AturanGrosir::Jumlah($b['Jumlah']),
                AturanGrosir::DaftarTeks($b['NomorSeri'] ?? null),
            ), $baris),
            namaPengirim: AturanGrosir::Teks($this->validated('NamaPengirim')),
            nomorKendaraan: AturanGrosir::Teks($this->validated('NomorKendaraan')),
            namaPenerima: AturanGrosir::Teks($this->validated('NamaPenerima')),
            catatan: AturanGrosir::Teks($this->validated('Catatan')),
        );
    }
}
