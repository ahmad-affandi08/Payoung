<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulangDetail;
use Illuminate\Support\Collection;

/**
 * Detail dan isian formulir templat invoice berulang (F-21): kepala jadwal, baris, dan invoice yang sudah terbit darinya
 * (terbaru dulu, paling banyak 24). Isian formulir memakai bentuk yang sama dengan yang dikirim formulir, sehingga halaman
 * ubah tidak punya pemetaan sendiri.
 */
final class DetailInvoiceBerulang
{
    public const MAKS_INVOICE = 24;

    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly DaftarKelompokPajak $kelompokPajak,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(InvoiceBerulang $t): array
    {
        $pelanggan = $this->identitas->AmbilNamaBanyak([$t->IdPelanggan])[$t->IdPelanggan] ?? ['Uuid' => '', 'Nama' => ''];

        return [
            'Templat' => [
                'Uuid' => $t->Uuid,
                'Nama' => $t->Nama,
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'NamaPelanggan' => $pelanggan['Nama'],
                'UuidPelanggan' => $pelanggan['Uuid'],
                'KodeOutlet' => $this->petaOutlet->AmbilKode([$t->IdOutlet])[$t->IdOutlet] ?? '',
                'Jadwal' => $t->Frekuensi->AmbilLabel($t->Interval),
                'TanggalMulai' => $t->TanggalMulai->format('Y-m-d'),
                'TanggalBerakhir' => $t->TanggalBerakhir?->format('Y-m-d'),
                'BatasKali' => $t->BatasKali,
                'JumlahTerbit' => $t->JumlahTerbit,
                'BerikutnyaPada' => $t->BerikutnyaPada?->format('Y-m-d'),
                'Mode' => $t->Mode->value,
                'LabelMode' => $t->Mode->AmbilLabel(),
                'TerminHari' => $t->TerminHari,
                'HargaTermasukPajak' => $t->HargaTermasukPajak,
                'Perihal' => $t->Perihal,
                'NomorReferensi' => $t->NomorReferensi,
                'KodePph' => $t->KodePph,
                'TarifPph' => $this->TarifPph($t),
                'Catatan' => $t->Catatan,
                'Syarat' => $t->Syarat,
                'InfoPembayaran' => $t->InfoPembayaran,
                'TerakhirTerbitPada' => $t->TerakhirTerbitPada?->toIso8601String(),
                'GalatTerakhir' => $t->GalatTerakhir,
                'GalatPada' => $t->GalatPada?->toIso8601String(),
            ],
            'Baris' => array_values($this->Baris($t)->map(fn (InvoiceBerulangDetail $d): array => [
                'Kunci' => (string) $d->Id,
                'Nama' => $d->Nama,
                'Keterangan' => $d->Keterangan,
                'JenisBaris' => $d->JenisBaris->value,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
            ])->all()),
            'InvoiceTerbit' => array_values(Invoice::query()->where('IdInvoiceBerulang', $t->Id)->orderByDesc('TanggalSiklus')->orderByDesc('Id')->limit(self::MAKS_INVOICE)->get()->map(fn (Invoice $i): array => [
                'Uuid' => $i->Uuid,
                'Nomor' => $i->Nomor,
                'Tanggal' => $i->Tanggal->format('Y-m-d'),
                'Status' => $i->Status->value,
                'LabelStatus' => $i->Status->AmbilLabel(),
                'Total' => $i->Total,
            ])->all()),
        ];
    }

    /**
     * Isian formulir ubah: bentuk yang sama dengan isian formulir invoice, ditambah jadwal dan mode.
     *
     * @return array<string, mixed>
     */
    public function Isian(InvoiceBerulang $t): array
    {
        $pelanggan = $this->identitas->AmbilNamaBanyak([$t->IdPelanggan])[$t->IdPelanggan] ?? ['Uuid' => '', 'Nama' => ''];
        $uuidKelompok = [];

        foreach ($this->kelompokPajak->AmbilOpsi() as $opsi) {
            $uuidKelompok[$opsi['Id']] = $opsi['Uuid'];
        }

        $detail = $this->Baris($t);
        $idProduk = array_values(array_filter($detail->pluck('IdProduk')->all(), 'is_int'));
        $uuidProduk = $idProduk === [] ? [] : Produk::query()->whereIn('Id', $idProduk)->pluck('Uuid', 'Id')->all();

        return [
            'Uuid' => $t->Uuid,
            'Nama' => $t->Nama,
            'Frekuensi' => $t->Frekuensi->value,
            'Interval' => $t->Interval,
            'TanggalMulai' => $t->TanggalMulai->format('Y-m-d'),
            'TanggalBerakhir' => $t->TanggalBerakhir?->format('Y-m-d') ?? '',
            'BatasKali' => $t->BatasKali === null ? '' : (string) $t->BatasKali,
            'Mode' => $t->Mode->value,
            'JadwalTerkunci' => $t->JumlahTerbit > 0 || $t->SiklusBerikutnya > 0,
            'UuidPelanggan' => $pelanggan['Uuid'],
            'NamaPelanggan' => $pelanggan['Nama'],
            'UuidOutlet' => $this->petaOutlet->Ambil([$t->IdOutlet])[$t->IdOutlet] ?? '',
            'TerminHari' => $t->TerminHari === null ? '' : (string) $t->TerminHari,
            'Perihal' => $t->Perihal ?? '',
            'NomorReferensi' => $t->NomorReferensi ?? '',
            'HargaTermasukPajak' => $t->HargaTermasukPajak,
            'KodePph' => $t->KodePph ?? '',
            'TarifPph' => $this->TarifPph($t) ?? '',
            'Catatan' => $t->Catatan ?? '',
            'Syarat' => $t->Syarat ?? '',
            'InfoPembayaran' => $t->InfoPembayaran ?? '',
            'Baris' => array_values($detail->map(fn (InvoiceBerulangDetail $d): array => [
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
     * @return Collection<int, InvoiceBerulangDetail>
     */
    private function Baris(InvoiceBerulang $t): Collection
    {
        return InvoiceBerulangDetail::query()->where('IdInvoiceBerulang', $t->Id)->orderBy('Urutan')->get();
    }

    private function TarifPph(InvoiceBerulang $t): ?string
    {
        return $t->TarifPph === null ? null : rtrim(rtrim($t->TarifPph, '0'), '.');
    }
}
