<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Persediaan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi penerimaan transfer stok (F-05b, D-105): tanggal terima dan, per baris (`Urutan`), jumlah diterima
 * (`Jumlah`), jumlah rusak (`Rusak`, diterima tetapi tidak layak jual), dan jumlah ditolak (`Ditolak`, dikembalikan ke
 * lokasi asal), satuan dasar. Rusak & Ditolak boleh dikosongkan. Semuanya 0 = baris itu tidak diproses kali ini.
 */
final class TerimaTransferStokPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.(int) config('persediaan.Dokumen.MaksimalBaris', 500)],
            'Baris.*.Urutan' => ['required', 'integer', 'min:1'],
            'Baris.*.Jumlah' => ['nullable', SimpanStokAwalPermintaan::POLA_JUMLAH],
            'Baris.*.Rusak' => ['nullable', SimpanStokAwalPermintaan::POLA_JUMLAH],
            'Baris.*.Ditolak' => ['nullable', SimpanStokAwalPermintaan::POLA_JUMLAH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Baris.required' => 'Isi jumlah diterima, rusak, atau ditolak minimal satu barang.',
            'Baris.*.Jumlah.regex' => 'Jumlah diterima berupa angka tanpa titik ribuan, maksimal 4 angka desimal.',
            'Baris.*.Rusak.regex' => 'Jumlah rusak berupa angka tanpa titik ribuan, maksimal 4 angka desimal.',
            'Baris.*.Ditolak.regex' => 'Jumlah ditolak berupa angka tanpa titik ribuan, maksimal 4 angka desimal.',
        ];
    }

    public function AmbilTanggal(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('Tanggal')) ?: CarbonImmutable::today();
    }

    /**
     * @return list<DataTerimaTransfer>
     */
    public function AmbilBaris(): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return array_map(fn (array $b): DataTerimaTransfer => new DataTerimaTransfer(
            (int) $b['Urutan'],
            Kuantitas::Dari((string) (($b['Jumlah'] ?? '') === '' ? '0' : $b['Jumlah'])),
            Kuantitas::Dari((string) (($b['Rusak'] ?? '') === '' ? '0' : $b['Rusak'])),
            Kuantitas::Dari((string) (($b['Ditolak'] ?? '') === '' ? '0' : $b['Ditolak'])),
        ), $baris);
    }
}
