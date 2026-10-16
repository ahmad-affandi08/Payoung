<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Invoice;

use App\Domain\Penjualan\Aksi\SimpanInvoiceBerulang;
use App\Domain\Penjualan\Data\DataInvoiceBerulang;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Http\Permintaan\Kelola\Grosir\AturanGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isian templat invoice berulang (F-21): jadwal dan mode ditambah isian invoice yang sama persis dengan
 * `SimpanInvoicePermintaan` (lewat `AturanInvoice`). Tanggal invoice tidak diisi di sini; mengikuti siklusnya.
 */
final class SimpanInvoiceBerulangPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...AturanInvoice::Aturan(),
            'Nama' => ['required', 'string', 'max:120'],
            'Frekuensi' => ['required', 'string', Rule::in(array_map(fn (FrekuensiInvoiceBerulang $f): string => $f->value, FrekuensiInvoiceBerulang::cases()))],
            'Interval' => ['required', 'integer', 'between:1,'.SimpanInvoiceBerulang::MAKS_INTERVAL],
            'TanggalMulai' => ['required', 'date_format:Y-m-d'],
            'TanggalBerakhir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:TanggalMulai'],
            'BatasKali' => ['nullable', 'integer', 'between:1,'.SimpanInvoiceBerulang::MAKS_BATAS_KALI],
            'Mode' => ['required', 'string', Rule::in(array_map(fn (ModeInvoiceBerulang $m): string => $m->value, ModeInvoiceBerulang::cases()))],
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
            ...AturanInvoice::Atribut(),
            'Nama' => 'nama templat',
            'Frekuensi' => 'frekuensi',
            'Interval' => 'interval',
            'TanggalMulai' => 'tanggal mulai',
            'TanggalBerakhir' => 'tanggal berakhir',
            'BatasKali' => 'jumlah penerbitan',
            'Mode' => 'mode',
        ];
    }

    public function AmbilData(int $idOutlet): DataInvoiceBerulang
    {
        $mulai = AturanGrosir::Tanggal($this->validated('TanggalMulai')) ?? CarbonImmutable::today();
        $batas = $this->validated('BatasKali');

        return new DataInvoiceBerulang(
            nama: (string) $this->validated('Nama'),
            frekuensi: FrekuensiInvoiceBerulang::from((string) $this->validated('Frekuensi')),
            interval: (int) $this->validated('Interval'),
            tanggalMulai: $mulai,
            tanggalBerakhir: AturanGrosir::Tanggal($this->validated('TanggalBerakhir')),
            batasKali: $batas === null || $batas === '' ? null : (int) $batas,
            mode: ModeInvoiceBerulang::from((string) $this->validated('Mode')),
            isi: AturanInvoice::BuatData($this->validated(), JenisInvoice::Penjualan, $idOutlet, $mulai),
        );
    }
}
