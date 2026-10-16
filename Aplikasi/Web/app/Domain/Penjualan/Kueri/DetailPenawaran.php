<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusTampilPenawaran;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;
use App\Domain\Penjualan\Model\PengirimanPenawaran;

/**
 * Detail penawaran untuk halaman back-office (F-21 bagian 3, D-168): kepala, baris, keputusan, invoice hasilnya, riwayat
 * status, pengiriman WhatsApp, dan tautan publik. Uang & jumlah tetap string desimal (CLAUDE.md #7).
 */
final class DetailPenawaran
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly DaftarAnggota $anggota,
        private readonly KonteksTenant $konteks,
        private readonly DokumenPenawaran $dokumen,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Penawaran $penawaran, string $hariIni): array
    {
        $invoice = $penawaran->IdInvoice === null ? null : Invoice::query()->whereKey($penawaran->IdInvoice)->first();
        $punyaInvoiceAktif = $this->dokumen->PunyaInvoiceAktif($penawaran);
        $tampil = StatusTampilPenawaran::Tentukan($penawaran->Status, $punyaInvoiceAktif, $penawaran->BerlakuSampai->toDateString(), $hariIni);
        $pelanggan = $this->identitas->AmbilNamaBanyak([$penawaran->IdPelanggan])[$penawaran->IdPelanggan] ?? null;

        return [
            'Penawaran' => [
                'Uuid' => $penawaran->Uuid,
                'Nomor' => $penawaran->Nomor,
                'Status' => $penawaran->Status->value,
                'LabelStatus' => $penawaran->Status->AmbilLabel(),
                'StatusTampil' => $tampil->value,
                'LabelStatusTampil' => $tampil->AmbilLabel(),
                'NamaPelanggan' => $pelanggan['Nama'] ?? '',
                'UuidPelanggan' => $pelanggan['Uuid'] ?? null,
                'UuidOutlet' => $this->petaOutlet->Ambil([$penawaran->IdOutlet])[$penawaran->IdOutlet] ?? null,
                'KodeOutlet' => $this->petaOutlet->AmbilKode([$penawaran->IdOutlet])[$penawaran->IdOutlet] ?? '',
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
                'AlasanBatal' => $penawaran->AlasanBatal,
                'TerkirimPada' => $penawaran->TerkirimPada?->toIso8601String(),
                'DilihatPada' => $penawaran->DilihatPada?->toIso8601String(),
                'JumlahDilihat' => $penawaran->JumlahDilihat,
                'DiterbitkanPada' => $penawaran->DiterbitkanPada?->toIso8601String(),
                'Keputusan' => $penawaran->DiputuskanPada === null ? null : [
                    'Sumber' => $penawaran->SumberKeputusan,
                    'Nama' => $penawaran->NamaPemutus,
                    'Catatan' => $penawaran->CatatanKeputusan,
                    'Pada' => $penawaran->DiputuskanPada->toIso8601String(),
                ],
                'Invoice' => $invoice === null ? null : ['Uuid' => $invoice->Uuid, 'Nomor' => $invoice->Nomor, 'Status' => $invoice->Status->value, 'LabelStatus' => $invoice->Status->AmbilLabel()],
                'TautanPublik' => $penawaran->Status === StatusPenawaran::Draf ? null : url('/p/'.KodeInvoicePublik::Buat($penawaran->IdTenant, $penawaran->KodePublik)),
            ],
            'Baris' => array_values(PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->orderBy('Urutan')->get()->map(fn (PenawaranDetail $d): array => [
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
                'Pajak' => $d->Pajak,
            ])->all()),
            'Riwayat' => $this->Riwayat($penawaran->Id),
            'Pengiriman' => $this->Pengiriman($penawaran->Id),
        ];
    }

    /**
     * Kiriman WhatsApp terbaru: hanya status dan waktu; nomor tujuan tidak ikut.
     *
     * @return list<array{Uuid: string, Status: string, LabelStatus: string, PesanGalat: string|null, Pada: string}>
     */
    private function Pengiriman(int $idPenawaran): array
    {
        return array_values(PengirimanPenawaran::query()->where('IdPenawaran', $idPenawaran)->orderByDesc('Id')->limit(10)->get()->map(fn (PengirimanPenawaran $k): array => [
            'Uuid' => $k->Uuid,
            'Status' => $k->Status->value,
            'LabelStatus' => $k->Status->AmbilLabel(),
            'PesanGalat' => $k->PesanGalat,
            'Pada' => ($k->TerkirimPada ?? $k->DibuatPada)?->toIso8601String() ?? '',
        ])->all());
    }

    private function UuidProduk(int $idProduk): ?string
    {
        $uuid = Produk::query()->whereKey($idProduk)->value('Uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * @return list<array{StatusKe: string, Oleh: string|null, Pada: string, Alasan: string|null}>
     */
    private function Riwayat(int $idPenawaran): array
    {
        $riwayat = RiwayatStatusDokumen::query()->where('JenisDokumen', Penawaran::JENIS_DOKUMEN)->where('IdDokumen', $idPenawaran)->orderBy('Id')->get();
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
