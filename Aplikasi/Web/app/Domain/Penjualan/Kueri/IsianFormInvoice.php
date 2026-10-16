<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceDetail;

/**
 * Isian formulir invoice (F-21): nilai awal untuk draf yang diubah, kebiasaan terakhir untuk invoice baru (syarat &
 * cara bayar diambil dari invoice terbaru supaya tidak diketik ulang tiap kali), dan invoice uang muka yang masih bisa
 * dipotong untuk satu pelanggan.
 */
final class IsianFormInvoice
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly DaftarKelompokPajak $kelompokPajak,
    ) {}

    /**
     * Isian draf yang akan diubah, dalam bentuk yang sama dengan yang dikirim formulir.
     *
     * @return array<string, mixed>
     */
    public function Draf(Invoice $invoice): array
    {
        $pelanggan = $this->identitas->AmbilNamaBanyak([$invoice->IdPelanggan])[$invoice->IdPelanggan] ?? ['Uuid' => '', 'Nama' => ''];
        $uuidKelompok = $this->PetaUuidKelompok();
        $detail = InvoiceDetail::query()->where('IdInvoice', $invoice->Id)->orderBy('Urutan')->get();
        $idProduk = array_values(array_filter($detail->pluck('IdProduk')->all(), 'is_int'));
        $uuidProduk = $idProduk === [] ? [] : Produk::query()->whereIn('Id', $idProduk)->pluck('Uuid', 'Id')->all();

        return [
            'Uuid' => $invoice->Uuid,
            'Nomor' => $invoice->Nomor,
            'Jenis' => $invoice->Jenis->value,
            'UuidPelanggan' => $pelanggan['Uuid'],
            'NamaPelanggan' => $pelanggan['Nama'],
            'UuidOutlet' => $this->petaOutlet->Ambil([$invoice->IdOutlet])[$invoice->IdOutlet] ?? '',
            'Tanggal' => $invoice->Tanggal->format('Y-m-d'),
            'TerminHari' => $invoice->TerminHari,
            'Perihal' => $invoice->Perihal ?? '',
            'NomorReferensi' => $invoice->NomorReferensi ?? '',
            'HargaTermasukPajak' => $invoice->HargaTermasukPajak,
            'KodePph' => $invoice->KodePph ?? '',
            'TarifPph' => $invoice->TarifPph === null ? '' : rtrim(rtrim($invoice->TarifPph, '0'), '.'),
            'Catatan' => $invoice->Catatan ?? '',
            'Syarat' => $invoice->Syarat ?? '',
            'InfoPembayaran' => $invoice->InfoPembayaran ?? '',
            'UuidUangMuka' => array_values(Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->pluck('Uuid')->all()),
            'Baris' => array_values($detail->map(fn (InvoiceDetail $d): array => [
                'Kunci' => (string) $d->Id,
                'UuidProduk' => $d->IdProduk === null ? null : ($uuidProduk[$d->IdProduk] ?? null),
                'Nama' => $d->Nama,
                'Keterangan' => $d->Keterangan ?? '',
                'JenisBaris' => $d->JenisBaris->value,
                'SimbolSatuan' => $d->SimbolSatuan ?? '',
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'UuidKelompokPajak' => $d->IdKelompokPajak === null ? null : ($uuidKelompok[$d->IdKelompokPajak] ?? null),
                'HargaTermasukPajak' => $d->HargaTermasukPajak,
            ])->all()),
        ];
    }

    /**
     * Kebiasaan terakhir untuk invoice baru: syarat & cara bayar dari invoice terbaru tenant ini.
     *
     * @return array{Syarat: string, InfoPembayaran: string}
     */
    public function Kebiasaan(): array
    {
        $terakhir = Invoice::query()->where(fn ($q) => $q->whereNotNull('Syarat')->orWhereNotNull('InfoPembayaran'))->orderByDesc('Id')->first(['Syarat', 'InfoPembayaran']);

        return ['Syarat' => $terakhir->Syarat ?? '', 'InfoPembayaran' => $terakhir->InfoPembayaran ?? ''];
    }

    /**
     * Invoice uang muka terbit milik pelanggan yang belum dipotong di invoice lain, ditambah yang sudah dipilih draf
     * `$uuidInvoiceAkhir` (supaya draf yang diubah tetap menampilkan DP yang sudah ia pesan).
     *
     * @return list<array{Uuid: string, Nomor: string, Tanggal: string, Dpp: string, Pajak: string, Total: string}>
     */
    public function UangMukaTersedia(string $uuidPelanggan, ?string $uuidInvoiceAkhir = null): array
    {
        $idPelanggan = $this->identitas->CariId($uuidPelanggan);

        if ($idPelanggan === null) {
            return [];
        }

        $idAkhir = $uuidInvoiceAkhir === null ? null : Invoice::query()->where('Uuid', $uuidInvoiceAkhir)->value('Id');

        return array_values(Invoice::query()
            ->where('IdPelanggan', $idPelanggan)
            ->where('Jenis', JenisInvoice::UangMuka->value)
            ->where('Status', StatusInvoice::Terbit->value)
            ->where(fn ($q) => $q->whereNull('IdInvoiceAkhir')->when(is_int($idAkhir), fn ($d) => $d->orWhere('IdInvoiceAkhir', $idAkhir)))
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->get()
            ->map(fn (Invoice $dp): array => [
                'Uuid' => $dp->Uuid,
                'Nomor' => (string) $dp->Nomor,
                'Tanggal' => $dp->Tanggal->format('Y-m-d'),
                'Dpp' => $dp->DasarPengenaanPajak,
                'Pajak' => $dp->Pajak,
                'Total' => $dp->Total,
            ])->all());
    }

    /**
     * @return array<int, string>
     */
    private function PetaUuidKelompok(): array
    {
        $peta = [];

        foreach ($this->kelompokPajak->AmbilOpsi() as $opsi) {
            $peta[$opsi['Id']] = $opsi['Uuid'];
        }

        return $peta;
    }
}
