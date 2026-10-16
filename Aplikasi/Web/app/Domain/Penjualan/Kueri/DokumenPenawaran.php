<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusTampilPenawaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Muatan penawaran yang **dibaca pelanggan** (F-21 bagian 3): halaman cetak A4 di back-office dan halaman publik `/p/{kode}`
 * memakai satu keluaran yang sama. Hanya yang pantas dilihat pembeli: identitas penjual, identitas pembeli (tanpa nomor HP
 * dan email di halaman publik), baris, angka, masa berlaku, syarat, dan keputusan yang sudah diambil. Tanpa riwayat
 * internal atau catatan dalam.
 */
final class DokumenPenawaran
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly ProfilTenant $profil,
        private readonly NamaTampilUsaha $namaTampil,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Penawaran $penawaran, string $hariIni, bool $untukPublik = false): array
    {
        $tenant = $this->profil->Ambil($penawaran->IdTenant);
        $outlet = $this->petaOutlet->AmbilIdentitas([$penawaran->IdOutlet])[$penawaran->IdOutlet] ?? ['Kode' => '', 'Nama' => '', 'Alamat' => null];
        $tampil = StatusTampilPenawaran::Tentukan($penawaran->Status, $this->PunyaInvoiceAktif($penawaran), $penawaran->BerlakuSampai->toDateString(), $hariIni);

        return [
            'Penawaran' => [
                'Nomor' => $penawaran->Nomor,
                'Status' => $penawaran->Status->value,
                'StatusTampil' => $tampil->value,
                'LabelStatusTampil' => $tampil->AmbilLabel(),
                // Pelanggan boleh memutuskan hanya selama masih terbit dan berlaku.
                'BisaDiputuskan' => $penawaran->Status === StatusPenawaran::Terbit && $tampil === StatusTampilPenawaran::MenungguKeputusan,
                'AlasanBatal' => $penawaran->Status === StatusPenawaran::Dibatalkan ? $penawaran->AlasanBatal : null,
                'Tanggal' => $penawaran->Tanggal->format('Y-m-d'),
                'BerlakuSampai' => $penawaran->BerlakuSampai->format('Y-m-d'),
                'TerminHari' => $penawaran->TerminHari,
                'Perihal' => $penawaran->Perihal,
                'NomorReferensi' => $penawaran->NomorReferensi,
                'HargaTermasukPajak' => $penawaran->HargaTermasukPajak,
                'TarifPpn' => $penawaran->TarifPpn,
                'Subtotal' => $penawaran->Subtotal,
                'Diskon' => $penawaran->Diskon,
                'DasarPengenaanPajak' => $penawaran->DasarPengenaanPajak,
                'Pajak' => $penawaran->Pajak,
                'Total' => $penawaran->Total,
                'Catatan' => $penawaran->Catatan,
                'Syarat' => $penawaran->Syarat,
                'NamaPemutus' => $penawaran->NamaPemutus,
                'DiputuskanPada' => $penawaran->DiputuskanPada?->toIso8601String(),
            ],
            'Penjual' => [
                'Nama' => $this->namaTampil->UntukTenant($penawaran->IdTenant),
                'Alamat' => $outlet['Alamat'],
                'KodeOutlet' => $outlet['Kode'],
                'Npwp' => $tenant['Pkp'] ? $tenant['Npwp'] : null,
                'Pkp' => $tenant['Pkp'],
            ],
            'Pembeli' => $this->Pembeli($penawaran->IdPelanggan, $untukPublik),
            'Baris' => array_values(PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->orderBy('Urutan')->get()->map(fn (PenawaranDetail $d): array => [
                'Kunci' => (string) $d->Id,
                'Nama' => $d->Nama,
                'Keterangan' => $d->Keterangan,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
            ])->all()),
        ];
    }

    public function PunyaInvoiceAktif(Penawaran $penawaran): bool
    {
        if ($penawaran->IdInvoice === null) {
            return false;
        }

        $status = Invoice::query()->whereKey($penawaran->IdInvoice)->value('Status');
        $status = $status instanceof StatusInvoice ? $status->value : $status;

        return $status !== null && $status !== StatusInvoice::Dibatalkan->value;
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
