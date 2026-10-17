<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusBayarInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceDetail;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Muatan invoice yang **dibaca pelanggan** (F-21): halaman cetak A4 di back-office dan halaman publik `/i/{kode}` memakai
 * satu keluaran yang sama, jadi apa yang dicetak sama dengan apa yang dilihat pelanggan. Hanya yang pantas dilihat
 * pembeli: identitas penjual (nama tampil outlet, alamat, NPWP bila PKP), identitas pembeli, baris, angka, uang muka yang
 * dipotong, pelunasan yang berlaku, dan cara bayar. Tanpa jurnal, riwayat internal, atau catatan dalam.
 *
 * PPh 23 yang dipotong pembeli ditampilkan sebagai "akan dipotong pembeli" di bawah total tagihan, supaya pembeli tahu
 * jumlah yang harus ditransfer; ia bukan baris pengurang piutang sampai bukti potongnya diterima.
 */
final class DokumenInvoice
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly PencatatPiutangPenjualan $piutang,
        private readonly ProfilTenant $profil,
        private readonly NamaTampilUsaha $namaTampil,
    ) {}

    /**
     * `$untukPublik` (halaman `/i/{kode}`): nomor HP dan email pembeli tidak ikut. Tautan publik adalah rahasia yang bisa
     * diteruskan atau bocor, jadi data kontak pembeli tidak ditampilkan di sana (minimisasi data, UU PDP); nama, alamat, dan
     * NPWP tetap tampil karena itulah identitas penagihan yang ada di invoice cetak.
     *
     * @return array<string, mixed>
     */
    public function Ambil(Invoice $invoice, string $hariIni, bool $untukPublik = false): array
    {
        $info = $this->piutang->AmbilPiutangBanyakInvoice([$invoice->Id])[$invoice->Id] ?? null;
        $statusBayar = StatusBayarInvoice::Tentukan(
            $invoice->Status,
            $info === null ? null : StatusPiutang::from($info['Status']),
            $invoice->JatuhTempo->toDateString(),
            $hariIni,
        );
        $pelunasan = $info === null ? [] : array_values(array_filter(
            $this->piutang->AmbilPelunasan((int) Piutang::query()->where('IdInvoice', $invoice->Id)->value('Id')),
            fn (array $p): bool => $p['Status'] === StatusPembayaranPiutang::Diposting->value,
        ));
        $tenant = $this->profil->Ambil($invoice->IdTenant);
        $outlet = $this->petaOutlet->AmbilIdentitas([$invoice->IdOutlet])[$invoice->IdOutlet] ?? ['Kode' => '', 'Nama' => '', 'Alamat' => null];
        $nilaiPph = Uang::Dari($invoice->NilaiPph);

        return [
            'Invoice' => [
                'Nomor' => $invoice->Nomor,
                'Jenis' => $invoice->Jenis->value,
                'LabelJenis' => $invoice->Jenis->AmbilLabel(),
                'Status' => $invoice->Status->value,
                'StatusBayar' => $statusBayar->value,
                'LabelStatusBayar' => $statusBayar->AmbilLabel(),
                'AlasanBatal' => $invoice->Status === StatusInvoice::Dibatalkan ? $invoice->AlasanBatal : null,
                'Tanggal' => $invoice->Tanggal->format('Y-m-d'),
                'JatuhTempo' => $invoice->JatuhTempo->format('Y-m-d'),
                'TerminHari' => $invoice->TerminHari,
                'Perihal' => $invoice->Perihal,
                'NomorReferensi' => $invoice->NomorReferensi,
                'HargaTermasukPajak' => $invoice->HargaTermasukPajak,
                'TarifPpn' => $invoice->TarifPpn,
                'Subtotal' => $invoice->Subtotal,
                'Diskon' => $invoice->Diskon,
                'DasarPengenaanPajak' => $invoice->DasarPengenaanPajak,
                'Pajak' => $invoice->Pajak,
                'Total' => $invoice->Total,
                'PotonganUangMukaDpp' => $invoice->PotonganUangMukaDpp,
                'PotonganUangMukaPajak' => $invoice->PotonganUangMukaPajak,
                'Tagihan' => $invoice->Tagihan,
                'KodePph' => $invoice->KodePph,
                'TarifPph' => $invoice->TarifPph,
                'NilaiPph' => $invoice->NilaiPph,
                'PphDipotongPembeli' => $nilaiPph->BernilaiNol() ? null : $nilaiPph->KeString(),
                'Catatan' => $invoice->Catatan,
                'Syarat' => $invoice->Syarat,
                'InfoPembayaran' => $invoice->InfoPembayaran,
                'Sisa' => $invoice->Status === StatusInvoice::Terbit ? ($info['Sisa'] ?? '0.00') : null,
                'Dibayar' => $info['Dibayar'] ?? null,
            ],
            'Penjual' => [
                'Nama' => $this->namaTampil->UntukTenant($invoice->IdTenant),
                'Alamat' => $outlet['Alamat'],
                'KodeOutlet' => $outlet['Kode'],
                'Npwp' => $tenant['Pkp'] ? $tenant['Npwp'] : null,
                'Pkp' => $tenant['Pkp'],
            ],
            'Pembeli' => $this->Pembeli($invoice->IdPelanggan, $untukPublik),
            'Baris' => array_values(InvoiceDetail::query()->where('IdInvoice', $invoice->Id)->orderBy('Urutan')->get()->map(fn (InvoiceDetail $d): array => [
                'Kunci' => (string) $d->Id,
                'Nama' => $d->Nama,
                'Keterangan' => $d->Keterangan,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
            ])->all()),
            'UangMuka' => array_values(Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->get()->map(fn (Invoice $dp): array => [
                'Nomor' => $dp->Nomor,
                'Tanggal' => $dp->Tanggal->format('Y-m-d'),
                'Total' => $dp->Total,
            ])->all()),
            'Pelunasan' => array_map(fn (array $p): array => [
                'Uuid' => $p['Uuid'],
                'Nomor' => $p['Nomor'],
                'Tanggal' => $p['Tanggal'],
                'Jumlah' => $p['Jumlah'],
                'PotonganPph' => $p['PotonganPph'],
            ], $pelunasan),
        ];
    }

    /**
     * @return array{Nama: string, Alamat: string|null, NoHp: string, Email: string|null, Npwp: string|null}
     */
    private function Pembeli(int $idPelanggan, bool $untukPublik): array
    {
        $pembeli = $this->identitas->AmbilUntukInvoice($idPelanggan) ?? ['Nama' => '', 'Alamat' => null, 'NoHp' => '', 'Email' => null, 'Npwp' => null];

        return $untukPublik ? [...$pembeli, 'NoHp' => '', 'Email' => null] : $pembeli;
    }
}
