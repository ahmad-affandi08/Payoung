<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusBayarInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceDetail;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PengirimanInvoice;

/**
 * Detail invoice untuk halaman back-office (F-21, D-166): kepala, baris, uang muka yang dipotong, status penagihan
 * (diturunkan dari `Piutang`), pelunasan, jurnal, riwayat status, dan tautan publik. Uang & jumlah tetap string desimal
 * (CLAUDE.md #7).
 */
final class DetailInvoice
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly PencatatPiutangPenjualan $piutang,
        private readonly JurnalSumber $jurnal,
        private readonly DaftarAnggota $anggota,
        private readonly KonteksTenant $konteks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Invoice $invoice, string $hariIni): array
    {
        $infoPiutang = $this->piutang->AmbilPiutangBanyakInvoice([$invoice->Id])[$invoice->Id] ?? null;
        $statusBayar = StatusBayarInvoice::Tentukan(
            $invoice->Status,
            $infoPiutang === null ? null : StatusPiutang::from($infoPiutang['Status']),
            $invoice->JatuhTempo->toDateString(),
            $hariIni,
        );
        $pelunasan = $infoPiutang === null ? [] : $this->piutang->AmbilPelunasan($this->IdPiutang($invoice->Id));
        $pelanggan = $this->identitas->AmbilNamaBanyak([$invoice->IdPelanggan])[$invoice->IdPelanggan] ?? null;

        return [
            'Invoice' => [
                'Uuid' => $invoice->Uuid,
                'Nomor' => $invoice->Nomor,
                'Jenis' => $invoice->Jenis->value,
                'LabelJenis' => $invoice->Jenis->AmbilLabel(),
                'Status' => $invoice->Status->value,
                'LabelStatus' => $invoice->Status->AmbilLabel(),
                'StatusBayar' => $statusBayar->value,
                'LabelStatusBayar' => $statusBayar->AmbilLabel(),
                'NamaPelanggan' => $pelanggan['Nama'] ?? '',
                'UuidPelanggan' => $pelanggan['Uuid'] ?? null,
                'UuidOutlet' => $this->petaOutlet->Ambil([$invoice->IdOutlet])[$invoice->IdOutlet] ?? null,
                'KodeOutlet' => $this->petaOutlet->AmbilKode([$invoice->IdOutlet])[$invoice->IdOutlet] ?? '',
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
                'DasarPph' => $invoice->DasarPph,
                'NilaiPph' => $invoice->NilaiPph,
                'Catatan' => $invoice->Catatan,
                'Syarat' => $invoice->Syarat,
                'InfoPembayaran' => $invoice->InfoPembayaran,
                'AlasanBatal' => $invoice->AlasanBatal,
                'TerkirimPada' => $invoice->TerkirimPada?->toIso8601String(),
                'DilihatPada' => $invoice->DilihatPada?->toIso8601String(),
                'JumlahDilihat' => $invoice->JumlahDilihat,
                'DiterbitkanPada' => $invoice->DiterbitkanPada?->toIso8601String(),
                // Penawaran asal (F-21 bagian 3): penawaran yang menunjuk invoice ini lewat `IdInvoice`.
                'Penawaran' => $this->PenawaranAsal($invoice->Id),
                'DipakaiDiInvoiceAkhir' => $invoice->Jenis === JenisInvoice::UangMuka && $invoice->IdInvoiceAkhir !== null,
                // Sisa tagihan & uang yang sudah diterima dibaca dari `Piutang`, bukan disalin ke invoice.
                'Sisa' => $infoPiutang['Sisa'] ?? null,
                'Dibayar' => $infoPiutang['Dibayar'] ?? null,
                'TautanPublik' => $invoice->Status === StatusInvoice::Terbit ? url('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik)) : null,
            ],
            'Baris' => array_values(InvoiceDetail::query()->where('IdInvoice', $invoice->Id)->orderBy('Urutan')->get()->map(fn (InvoiceDetail $d): array => [
                'Kunci' => (string) $d->Id,
                'UuidProduk' => $d->IdProduk === null ? null : $this->UuidProduk($d->IdProduk),
                'Nama' => $d->Nama,
                'Keterangan' => $d->Keterangan,
                'JenisBaris' => $d->JenisBaris->value,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
                'HargaTermasukPajak' => $d->HargaTermasukPajak,
                'IdKelompokPajak' => $d->IdKelompokPajak,
                'Pajak' => $d->Pajak,
                'Pendapatan' => $d->Pendapatan,
            ])->all()),
            'UangMuka' => array_values(Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->get()->map(fn (Invoice $dp): array => [
                'Uuid' => $dp->Uuid,
                'Nomor' => $dp->Nomor,
                'Tanggal' => $dp->Tanggal->format('Y-m-d'),
                'Dpp' => $dp->DasarPengenaanPajak,
                'Pajak' => $dp->Pajak,
                'Total' => $dp->Total,
            ])->all()),
            'Pelunasan' => $pelunasan,
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::Invoice, $invoice->Id),
            'Riwayat' => $this->Riwayat($invoice->Id),
            'Pengiriman' => $this->Pengiriman($invoice->Id),
        ];
    }

    /**
     * Kiriman WhatsApp terbaru (invoice & pengingat): hanya jenis, tahap, status, dan waktu; nomor tujuan tidak ikut.
     *
     * @return list<array{Uuid: string, Jenis: string, Tahap: string|null, Status: string, LabelStatus: string, PesanGalat: string|null, Pada: string}>
     */
    private function Pengiriman(int $idInvoice): array
    {
        return array_values(PengirimanInvoice::query()->where('IdInvoice', $idInvoice)->orderByDesc('Id')->limit(10)->get()->map(fn (PengirimanInvoice $k): array => [
            'Uuid' => $k->Uuid,
            'Jenis' => $k->Jenis->value,
            'Tahap' => $k->Tahap,
            'Status' => $k->Status->value,
            'LabelStatus' => $k->Status->AmbilLabel(),
            'PesanGalat' => $k->PesanGalat,
            'Pada' => ($k->TerkirimPada ?? $k->DibuatPada)?->toIso8601String() ?? '',
        ])->all());
    }

    /**
     * @return array{Uuid: string, Nomor: string|null}|null
     */
    private function PenawaranAsal(int $idInvoice): ?array
    {
        $penawaran = Penawaran::query()->where('IdInvoice', $idInvoice)->first(['Uuid', 'Nomor']);

        return $penawaran === null ? null : ['Uuid' => $penawaran->Uuid, 'Nomor' => $penawaran->Nomor];
    }

    private function IdPiutang(int $idInvoice): int
    {
        return (int) Piutang::query()->where('IdInvoice', $idInvoice)->value('Id');
    }

    private function UuidProduk(int $idProduk): ?string
    {
        $uuid = Produk::query()->whereKey($idProduk)->value('Uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * @return list<array{StatusKe: string, Oleh: string|null, Pada: string, Alasan: string|null}>
     */
    private function Riwayat(int $idInvoice): array
    {
        $riwayat = RiwayatStatusDokumen::query()->where('JenisDokumen', Invoice::JENIS_DOKUMEN)->where('IdDokumen', $idInvoice)->orderBy('Id')->get();
        $idPengguna = array_values(array_unique(array_filter($riwayat->pluck('DiubahOleh')->all(), 'is_int')));
        $nama = $idPengguna === [] ? [] : $this->anggota->AmbilNamaPengguna($this->konteks->Wajib(), $idPengguna);

        return array_values($riwayat->map(fn (RiwayatStatusDokumen $r): array => [
            'StatusKe' => (string) $r->StatusKe,
            'Oleh' => $r->DiubahOleh === null ? null : ($nama[$r->DiubahOleh] ?? null),
            'Pada' => $r->DiubahPada?->toIso8601String() ?? '',
            'Alasan' => $r->Alasan,
        ])->all());
    }
}
