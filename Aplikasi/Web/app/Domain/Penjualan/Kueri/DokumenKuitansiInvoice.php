<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Muatan kuitansi satu pembayaran invoice (F-21 bagian 5): halaman cetak A4 di back-office dan halaman publik
 * `/i/{kode}/kuitansi/{uuidPembayaran}` memakai satu keluaran yang sama. Nomor kuitansi = nomor pelunasan piutang (`BP/…`),
 * jadi tidak ada deret nomor kedua yang bisa berselisih dengan pembukuan. Hanya yang pantas dibaca pembeli: pembayaran yang
 * dialokasikan ke invoice ini, uang yang diterima (dilunasi dikurangi PPh yang dipotong pembeli), sisa tagihan sesudahnya.
 * Tanpa akun kas/bank tujuan, catatan internal, atau kontak pembeli di halaman publik.
 */
final class DokumenKuitansiInvoice
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly PencatatPiutangPenjualan $piutang,
        private readonly ProfilTenant $profil,
        private readonly NamaTampilUsaha $namaTampil,
    ) {}

    /**
     * Null bila invoice belum terbit (draf), atau pembayaran itu tidak mengalokasikan apa pun ke invoice ini.
     *
     * @return array<string, mixed>|null
     */
    public function Ambil(Invoice $invoice, string $uuidPembayaran, bool $untukPublik = false): ?array
    {
        if ($invoice->Status === StatusInvoice::Draf) {
            return null;
        }

        $idPiutang = Piutang::query()->where('IdInvoice', $invoice->Id)->value('Id');
        $pelunasan = is_int($idPiutang) ? $this->piutang->AmbilPelunasanKuitansi($idPiutang, $uuidPembayaran) : null;

        if ($pelunasan === null) {
            return null;
        }

        $tenant = $this->profil->Ambil($invoice->IdTenant);
        $outlet = $this->petaOutlet->AmbilIdentitas([$invoice->IdOutlet])[$invoice->IdOutlet] ?? ['Kode' => '', 'Nama' => '', 'Alamat' => null];
        $pembeli = $this->identitas->AmbilUntukInvoice($invoice->IdPelanggan) ?? ['Nama' => '', 'Alamat' => null, 'NoHp' => '', 'Email' => null, 'Npwp' => null];

        return [
            'Kuitansi' => [
                ...$pelunasan,
                'KodePph' => $invoice->KodePph,
                'TarifPph' => $invoice->TarifPph,
            ],
            'Invoice' => [
                'Nomor' => $invoice->Nomor,
                'Tanggal' => $invoice->Tanggal->format('Y-m-d'),
                'Perihal' => $invoice->Perihal,
                'Tagihan' => $invoice->Tagihan,
                'Status' => $invoice->Status->value,
            ],
            'Penjual' => [
                'Nama' => $this->namaTampil->UntukTenant($invoice->IdTenant),
                'Alamat' => $outlet['Alamat'],
                'KodeOutlet' => $outlet['Kode'],
                'Npwp' => $tenant['Pkp'] ? $tenant['Npwp'] : null,
                'Pkp' => $tenant['Pkp'],
            ],
            'Pembeli' => $untukPublik ? [...$pembeli, 'NoHp' => '', 'Email' => null] : $pembeli,
        ];
    }
}
