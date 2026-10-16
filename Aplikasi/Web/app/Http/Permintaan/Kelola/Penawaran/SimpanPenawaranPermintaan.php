<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Penawaran;

use App\Domain\Penjualan\Aksi\SimpanPenawaran;
use App\Domain\Penjualan\Data\DataPenawaran;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use App\Http\Permintaan\Kelola\Invoice\AturanInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian draf penawaran (F-21 bagian 3, D-168). Aturan baris dipakai bersama invoice lewat `AturanInvoice` (penawaran tidak
 * boleh menerima baris yang kelak ditolak invoice). Total & pajak tidak pernah dikirim klien; dihitung server. Outlet diubah
 * ke Id oleh kontroler (batas outlet pelaku).
 */
final class SimpanPenawaranPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...array_diff_key(AturanInvoice::Aturan(), ['KodePph' => 1, 'TarifPph' => 1, 'InfoPembayaran' => 1]),
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'BerlakuSampai' => ['required', 'date_format:Y-m-d'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.SimpanPenawaran::MAKS_BARIS],
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
        return [...AturanInvoice::Atribut(), 'Baris' => 'baris penawaran', 'Tanggal' => 'tanggal', 'BerlakuSampai' => 'berlaku sampai'];
    }

    public function AmbilData(int $idOutlet): DataPenawaran
    {
        $valid = $this->validated();
        $tanggal = AturanGrosir::Tanggal($valid['Tanggal']) ?? CarbonImmutable::today();
        // Baris diubah memakai pengubah isian invoice yang sama; hanya bagian baris dan teks yang dipakai.
        $dasar = AturanInvoice::BuatData($valid, JenisInvoice::Penjualan, $idOutlet, $tanggal);

        return new DataPenawaran(
            uuidPelanggan: $dasar->uuidPelanggan,
            idOutlet: $idOutlet,
            tanggal: $tanggal,
            berlakuSampai: AturanGrosir::Tanggal($valid['BerlakuSampai']) ?? $tanggal,
            terminHari: $dasar->terminHari,
            baris: $dasar->baris,
            hargaTermasukPajak: $dasar->hargaTermasukPajak,
            perihal: $dasar->perihal,
            nomorReferensi: $dasar->nomorReferensi,
            catatan: $dasar->catatan,
            syarat: $dasar->syarat,
        );
    }
}
