<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Persediaan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataTransferStok;
use App\Domain\Persediaan\Kueri\PelacakanTersedia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi form transfer stok POST (buat, `Uuid` klien opsional) dan PUT (ubah draf, wajib `VersiDiubahPada`)
 * (F-05b). Jumlah dikirim dalam satuan dasar. Batch/nomor seri asal lewat Uuid. Aturan bisnis diperiksa Aksi.
 */
final class SimpanTransferStokPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $maksimal = (int) config('persediaan.Dokumen.MaksimalBaris', 500);

        return [
            ...($this->isMethod('post') ? ['Uuid' => ['nullable', 'ulid'], 'UuidPermintaanStok' => ['nullable', 'ulid']] : ['VersiDiubahPada' => ['required', 'string', 'max:40']]),
            'UuidGudangAsal' => ['required', 'ulid'],
            'UuidGudangTujuan' => ['required', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Baris' => ['required', 'array', 'min:1', "max:{$maksimal}"],
            'Baris.*.UuidProduk' => ['required', 'ulid'],
            'Baris.*.Jumlah' => ['required', SimpanStokAwalPermintaan::POLA_JUMLAH],
            'Baris.*.UuidBatchStok' => ['nullable', 'ulid'],
            'Baris.*.UuidNomorSeri' => ['nullable', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Baris.required' => 'Tambahkan minimal satu produk.',
            'Baris.min' => 'Tambahkan minimal satu produk.',
            'Baris.max' => 'Satu transfer maksimal :max baris. Pecah menjadi beberapa transfer.',
            'Baris.*.Jumlah.regex' => 'Jumlah berupa angka tanpa titik ribuan, maksimal 4 angka desimal. Misal: 12 atau 2.5.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['UuidGudangAsal' => 'lokasi asal', 'UuidGudangTujuan' => 'lokasi tujuan', 'Tanggal' => 'tanggal kirim', 'Catatan' => 'catatan', 'Baris.*.Jumlah' => 'jumlah'];
    }

    /** Produk/batch/nomor seri tak dikenal di tenant aktif dipetakan ke Id 0 sehingga Aksi menolaknya per baris. */
    public function AmbilData(int $idGudangAsal, int $idGudangTujuan): DataTransferStok
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));
        $produk = app(InfoProdukStok::class)->AmbilDariUuid(array_values(array_map(fn (array $b): string => (string) $b['UuidProduk'], $baris)));
        $pelacakan = app(PelacakanTersedia::class);
        $batch = $pelacakan->AmbilIdBatch(array_values(array_filter(array_map(fn (array $b): ?string => is_string($b['UuidBatchStok'] ?? null) ? $b['UuidBatchStok'] : null, $baris))));
        $seri = $pelacakan->AmbilIdSeri(array_values(array_filter(array_map(fn (array $b): ?string => is_string($b['UuidNomorSeri'] ?? null) ? $b['UuidNomorSeri'] : null, $baris))));

        return new DataTransferStok(
            uuid: $this->isMethod('post') && is_string($this->validated('Uuid')) ? (string) $this->validated('Uuid') : null,
            idGudangAsal: $idGudangAsal,
            idGudangTujuan: $idGudangTujuan,
            tanggal: CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('Tanggal')) ?: CarbonImmutable::today(),
            catatan: is_string($this->validated('Catatan')) ? (string) $this->validated('Catatan') : null,
            baris: array_map(fn (array $b): DataBarisDokumenStok => new DataBarisDokumenStok(
                idProduk: ($produk[(string) $b['UuidProduk']] ?? null)->id ?? 0,
                jumlah: Kuantitas::Dari((string) $b['Jumlah']),
                idBatchStok: is_string($b['UuidBatchStok'] ?? null) ? ($batch[$b['UuidBatchStok']] ?? 0) : null,
                idNomorSeri: is_string($b['UuidNomorSeri'] ?? null) ? ($seri[$b['UuidNomorSeri']] ?? 0) : null,
            ), $baris),
            versiDiubahPada: $this->isMethod('put') && is_string($this->validated('VersiDiubahPada')) ? (string) $this->validated('VersiDiubahPada') : null,
            uuidPermintaanStok: $this->isMethod('post') && is_string($this->validated('UuidPermintaanStok')) ? (string) $this->validated('UuidPermintaanStok') : null,
        );
    }
}
