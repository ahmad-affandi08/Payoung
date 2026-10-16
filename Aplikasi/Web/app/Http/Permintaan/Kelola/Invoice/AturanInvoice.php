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
use Illuminate\Validation\Rule;

/**
 * Aturan validasi dan konversi isian invoice yang dipakai bersama formulir invoice dan templat invoice berulang (F-21):
 * satu tempat, supaya templat tidak pernah menerima isian yang ditolak invoice biasa (atau sebaliknya).
 */
final class AturanInvoice
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function Aturan(): array
    {
        return [
            'UuidPelanggan' => ['required', 'string', 'ulid'],
            'UuidOutlet' => ['required', 'string', 'ulid'],
            'TerminHari' => ['nullable', 'integer', 'between:0,365'],
            'Perihal' => ['nullable', 'string', 'max:150'],
            'NomorReferensi' => ['nullable', 'string', 'max:60'],
            'HargaTermasukPajak' => ['required', 'boolean'],
            'KodePph' => ['nullable', 'string', 'max:20'],
            'TarifPph' => ['nullable', 'string', 'max:12'],
            'Catatan' => ['nullable', 'string', 'max:1000'],
            'Syarat' => ['nullable', 'string', 'max:1000'],
            'InfoPembayaran' => ['nullable', 'string', 'max:500'],
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
    public static function Atribut(): array
    {
        return [
            'UuidPelanggan' => 'pelanggan',
            'UuidOutlet' => 'outlet',
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

    /**
     * @param  array<string, mixed>  $valid  hasil `validated()`
     * @param  list<string>  $uuidUangMuka
     */
    public static function BuatData(array $valid, JenisInvoice $jenis, int $idOutlet, CarbonImmutable $tanggal, array $uuidUangMuka = []): DataInvoice
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $valid['Baris']);
        $termin = $valid['TerminHari'] ?? null;

        return new DataInvoice(
            jenis: $jenis,
            uuidPelanggan: (string) $valid['UuidPelanggan'],
            idOutlet: $idOutlet,
            tanggal: $tanggal,
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
            hargaTermasukPajak: (bool) $valid['HargaTermasukPajak'],
            perihal: AturanGrosir::Teks($valid['Perihal'] ?? null),
            nomorReferensi: AturanGrosir::Teks($valid['NomorReferensi'] ?? null),
            kodePph: AturanGrosir::Teks($valid['KodePph'] ?? null),
            tarifPph: AturanGrosir::Teks($valid['TarifPph'] ?? null),
            catatan: AturanGrosir::Teks($valid['Catatan'] ?? null),
            syarat: AturanGrosir::Teks($valid['Syarat'] ?? null),
            infoPembayaran: AturanGrosir::Teks($valid['InfoPembayaran'] ?? null),
            uuidUangMuka: $uuidUangMuka,
        );
    }
}
