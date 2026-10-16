<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Invoice;

use App\Domain\Penjualan\Aksi\SimpanInvoice;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian draf invoice (F-21, D-166). **Angka turunan tidak ada di sini**: total, pajak, PPh, dan potongan uang muka
 * dihitung server dari baris, jadi tidak ada yang bisa dikarang dari peramban. Harga baris memang dikirim klien karena
 * invoice berisi kesepakatan dengan pelanggan (jasa, proyek), bukan harga katalog. Outlet diubah ke Id oleh kontroler
 * (batas outlet pelaku).
 */
final class SimpanInvoicePermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Jenis' => ['required', 'string', Rule::in(array_map(fn (JenisInvoice $j): string => $j->value, JenisInvoice::cases()))],
            'UuidPelanggan' => ['required', 'string', 'ulid'],
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'TerminHari' => ['nullable', 'integer', 'between:0,365'],
            'Perihal' => ['nullable', 'string', 'max:150'],
            'NomorReferensi' => ['nullable', 'string', 'max:60'],
            'HargaTermasukPajak' => ['required', 'boolean'],
            'KodePph' => ['nullable', 'string', 'max:20'],
            'TarifPph' => ['nullable', 'string', 'max:12'],
            'Catatan' => ['nullable', 'string', 'max:1000'],
            'Syarat' => ['nullable', 'string', 'max:1000'],
            'InfoPembayaran' => ['nullable', 'string', 'max:500'],
            'UuidUangMuka' => ['nullable', 'array', 'max:'.SimpanInvoice::MAKS_UANG_MUKA],
            'UuidUangMuka.*' => ['required', 'string', 'ulid', 'distinct'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.SimpanInvoice::MAKS_BARIS],
            'Baris.*.UuidProduk' => ['nullable', 'string', 'ulid'],
            'Baris.*.Nama' => ['required', 'string', 'max:200'],
            'Baris.*.Keterangan' => ['nullable', 'string', 'max:255'],
            'Baris.*.JenisBaris' => ['required', 'string', Rule::in(array_map(fn (JenisBarisInvoice $j): string => $j->value, JenisBarisInvoice::cases()))],
            'Baris.*.SimbolSatuan' => ['nullable', 'string', 'max:20'],
            'Baris.*.Jumlah' => ['required', 'string', AturanGrosir::JUMLAH],
            'Baris.*.Harga' => ['required', 'string', AturanGrosir::UANG],
            'Baris.*.Diskon' => ['nullable', 'string', AturanGrosir::UANG],
            'Baris.*.UuidKelompokPajak' => ['nullable', 'string', 'ulid'],
            'Baris.*.HargaTermasukPajak' => ['nullable', 'boolean'],
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
        return [
            'UuidPelanggan' => 'pelanggan',
            'UuidOutlet' => 'outlet',
            'Tanggal' => 'tanggal',
            'TerminHari' => 'termin pembayaran',
            'Perihal' => 'perihal',
            'NomorReferensi' => 'nomor referensi',
            'KodePph' => 'jenis PPh',
            'TarifPph' => 'tarif PPh',
            'Baris' => 'baris invoice',
            'Baris.*.Nama' => 'nama baris',
            'Baris.*.Jumlah' => 'jumlah',
            'Baris.*.Harga' => 'harga',
            'Baris.*.Diskon' => 'diskon',
        ];
    }

    public function AmbilData(int $idOutlet): DataInvoice
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));
        $termin = $this->validated('TerminHari');

        return new DataInvoice(
            jenis: JenisInvoice::from((string) $this->validated('Jenis')),
            uuidPelanggan: (string) $this->validated('UuidPelanggan'),
            idOutlet: $idOutlet,
            tanggal: AturanGrosir::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today(),
            terminHari: $termin === null || $termin === '' ? null : (int) $termin,
            baris: array_map(fn (array $b): DataBarisInvoice => new DataBarisInvoice(
                uuidProduk: AturanGrosir::Teks($b['UuidProduk'] ?? null),
                nama: (string) $b['Nama'],
                keterangan: AturanGrosir::Teks($b['Keterangan'] ?? null),
                jenisBaris: JenisBarisInvoice::from((string) $b['JenisBaris']),
                simbolSatuan: AturanGrosir::Teks($b['SimbolSatuan'] ?? null),
                jumlah: AturanGrosir::Jumlah($b['Jumlah']),
                harga: AturanGrosir::Uang($b['Harga']),
                diskon: AturanGrosir::Uang($b['Diskon'] ?? null),
                uuidKelompokPajak: AturanGrosir::Teks($b['UuidKelompokPajak'] ?? null),
                hargaTermasukPajak: isset($b['HargaTermasukPajak']) ? (bool) $b['HargaTermasukPajak'] : null,
            ), $baris),
            hargaTermasukPajak: (bool) $this->validated('HargaTermasukPajak'),
            perihal: AturanGrosir::Teks($this->validated('Perihal')),
            nomorReferensi: AturanGrosir::Teks($this->validated('NomorReferensi')),
            kodePph: AturanGrosir::Teks($this->validated('KodePph')),
            tarifPph: AturanGrosir::Teks($this->validated('TarifPph')),
            catatan: AturanGrosir::Teks($this->validated('Catatan')),
            syarat: AturanGrosir::Teks($this->validated('Syarat')),
            infoPembayaran: AturanGrosir::Teks($this->validated('InfoPembayaran')),
            uuidUangMuka: array_values(array_map('strval', (array) ($this->validated('UuidUangMuka') ?? []))),
        );
    }
}
