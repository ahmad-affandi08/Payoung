<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pembelian;

use App\Domain\Pembelian\Aksi\SimpanBelanjaStokTempo;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Data\DataPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Isian penerimaan barang dari PO (`UuidPesananPembelian` + `Baris.*.IdBarisPesanan`) atau tanpa PO (lokasi, pemasok
 * opsional, produk/satuan/harga per baris), juga dipakai belanja stok (`UuidAkun`, `NomorNota`). Lampiran surat
 * jalan opsional (privat).
 */
final class SimpanPenerimaanBarangPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'UuidPesananPembelian' => ['nullable', 'string', 'ulid'],
            'UuidPemasok' => ['nullable', 'string', 'ulid'],
            'UuidGudang' => ['nullable', 'string', 'ulid', 'required_without:UuidPesananPembelian'],
            'UuidAkun' => ['nullable', 'string', 'ulid'],
            // D-256: belanja stok dibayar dari uang laci kasir yang sudah dicatat keluar di POS (tanpa akun kas/bank).
            'DariUangLaci' => ['nullable', 'boolean'],
            // D-257: konfirmasi belanja kas/bank ini bukan uang laci yang sudah dicatat kasir (mencegah catat ganda).
            'BukanUangLaci' => ['nullable', 'boolean'],
            // Audit kemudahan pakai #20: belanja stok dibayar nanti (tempo) = penerimaan + faktur belum dibayar.
            'BayarNanti' => ['nullable', 'boolean'],
            'TerminHari' => ['nullable', 'integer', 'min:0', 'max:'.SimpanBelanjaStokTempo::MAKS_TERMIN],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'NomorSuratJalan' => ['nullable', 'string', 'max:60'],
            'NomorNota' => ['nullable', 'string', 'max:60'],
            'Ongkir' => ['nullable', 'string', AturanPembelian::UANG],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Lampiran' => AturanPembelian::Lampiran(),
            'Baris' => ['required', 'array', 'min:1', 'max:'.PemrosesPenerimaanBarang::MAKS_BARIS],
            'Baris.*.IdBarisPesanan' => ['nullable', 'integer', 'min:1'],
            'Baris.*.UuidProduk' => ['nullable', 'string', 'ulid'],
            'Baris.*.UuidProdukSatuan' => ['nullable', 'string', 'ulid'],
            'Baris.*.Jumlah' => ['required', 'string', AturanPembelian::JUMLAH],
            'Baris.*.Harga' => ['nullable', 'string', AturanPembelian::UANG],
            'Baris.*.Diskon' => ['nullable', 'string', AturanPembelian::UANG],
            'Baris.*.NomorBatch' => ['nullable', 'string', 'max:60'],
            'Baris.*.TanggalKedaluwarsa' => ['nullable', 'date_format:Y-m-d'],
            'Baris.*.NomorSeri' => ['nullable', 'array', 'max:'.PemrosesPenerimaanBarang::MAKS_NOMOR_SERI_PER_BARIS],
            'Baris.*.NomorSeri.*' => ['string', 'max:100'],
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
        return ['UuidGudang' => 'lokasi stok', 'Tanggal' => 'tanggal', 'Baris' => 'barang', 'Baris.*.Jumlah' => 'jumlah', 'Baris.*.Harga' => 'harga', 'Baris.*.Diskon' => 'diskon', 'Ongkir' => 'ongkir', 'Lampiran' => 'lampiran', 'UuidAkun' => 'akun kas/bank'];
    }

    public function AmbilUuidGudang(): ?string
    {
        return AturanPembelian::Teks($this->validated('UuidGudang'));
    }

    public function AmbilData(?int $idGudang, int $idPengguna): DataPenerimaanBarang
    {
        return new DataPenerimaanBarang(
            AturanPembelian::Teks($this->validated('UuidPesananPembelian')),
            AturanPembelian::Teks($this->validated('UuidPemasok')),
            $idGudang,
            $this->AmbilTanggal(),
            AturanPembelian::Teks($this->validated('NomorSuratJalan')),
            AturanPembelian::Uang($this->validated('Ongkir')),
            AturanPembelian::Teks($this->validated('Catatan')),
            $this->AmbilBaris(),
            $this->AmbilLampiran(),
            $idPengguna,
        );
    }

    public function AmbilDataBelanja(int $idGudang, int $idPengguna): DataBelanjaStok
    {
        return new DataBelanjaStok(
            AturanPembelian::Teks($this->validated('UuidPemasok')),
            $idGudang,
            $this->AmbilTanggal(),
            (string) AturanPembelian::Teks($this->validated('UuidAkun')),
            AturanPembelian::Teks($this->validated('NomorNota')),
            AturanPembelian::Uang($this->validated('Ongkir')),
            AturanPembelian::Teks($this->validated('Catatan')),
            $this->AmbilBaris(),
            $this->AmbilLampiran(),
            $idPengguna,
            $this->CekDariUangLaci(),
            (bool) $this->validated('BukanUangLaci', false),
        );
    }

    /** Uang laci hanya untuk belanja yang dibayar sekarang: belanja tempo menjadi hutang ke pemasok. */
    public function CekDariUangLaci(): bool
    {
        return ! $this->CekBayarNanti() && (bool) $this->validated('DariUangLaci', false);
    }

    public function CekBayarNanti(): bool
    {
        return (bool) $this->validated('BayarNanti', false);
    }

    public function AmbilTerminHari(): int
    {
        return (int) ($this->validated('TerminHari') ?? 0);
    }

    private function AmbilTanggal(): CarbonImmutable
    {
        return AturanPembelian::Tanggal($this->validated('Tanggal')) ?? CarbonImmutable::today();
    }

    private function AmbilLampiran(): ?UploadedFile
    {
        $berkas = $this->file('Lampiran');

        return $berkas instanceof UploadedFile ? $berkas : null;
    }

    /**
     * @return list<DataBarisPenerimaanBarang>
     */
    private function AmbilBaris(): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $this->validated('Baris'));

        return array_map(fn (array $b): DataBarisPenerimaanBarang => new DataBarisPenerimaanBarang(
            isset($b['IdBarisPesanan']) ? (int) $b['IdBarisPesanan'] : null,
            AturanPembelian::Teks($b['UuidProduk'] ?? null),
            AturanPembelian::Teks($b['UuidProdukSatuan'] ?? null),
            AturanPembelian::Jumlah($b['Jumlah']),
            AturanPembelian::UangAtauNull($b['Harga'] ?? null),
            AturanPembelian::UangAtauNull($b['Diskon'] ?? null),
            AturanPembelian::Teks($b['NomorBatch'] ?? null),
            AturanPembelian::Tanggal($b['TanggalKedaluwarsa'] ?? null),
            array_values(array_map('strval', (array) ($b['NomorSeri'] ?? []))),
        ), $baris);
    }
}
