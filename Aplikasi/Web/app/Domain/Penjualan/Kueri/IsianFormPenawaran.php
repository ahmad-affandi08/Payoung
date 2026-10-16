<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;

/**
 * Isian formulir penawaran (F-21 bagian 3): nilai awal untuk draf yang diubah atau penawaran yang disalin (revisi), dan
 * kebiasaan terakhir (syarat dari penawaran terbaru) untuk penawaran baru supaya tidak diketik ulang.
 */
final class IsianFormPenawaran
{
    /** Masa berlaku bawaan penawaran baru (hari). */
    public const MASA_BERLAKU_BAWAAN = 14;

    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly DaftarKelompokPajak $kelompokPajak,
    ) {}

    /**
     * Isian dalam bentuk yang sama dengan yang dikirim formulir. `$salinan` = true menghasilkan isian untuk penawaran
     * **baru** dari penawaran ini: tanpa Uuid/Nomor; tanggal & masa berlaku diisi ulang oleh formulir.
     *
     * @return array<string, mixed>
     */
    public function Draf(Penawaran $penawaran, bool $salinan = false): array
    {
        $pelanggan = $this->identitas->AmbilNamaBanyak([$penawaran->IdPelanggan])[$penawaran->IdPelanggan] ?? ['Uuid' => '', 'Nama' => ''];
        $uuidKelompok = $this->PetaUuidKelompok();
        $detail = PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->orderBy('Urutan')->get();
        $idProduk = array_values(array_filter($detail->pluck('IdProduk')->all(), 'is_int'));
        $uuidProduk = $idProduk === [] ? [] : Produk::query()->whereIn('Id', $idProduk)->pluck('Uuid', 'Id')->all();

        return [
            'Uuid' => $salinan ? null : $penawaran->Uuid,
            'Nomor' => $salinan ? null : $penawaran->Nomor,
            'UuidPelanggan' => $pelanggan['Uuid'],
            'NamaPelanggan' => $pelanggan['Nama'],
            'UuidOutlet' => $this->petaOutlet->Ambil([$penawaran->IdOutlet])[$penawaran->IdOutlet] ?? '',
            'Tanggal' => $penawaran->Tanggal->format('Y-m-d'),
            'BerlakuSampai' => $penawaran->BerlakuSampai->format('Y-m-d'),
            'TerminHari' => $penawaran->TerminHari,
            'Perihal' => $penawaran->Perihal ?? '',
            'NomorReferensi' => $penawaran->NomorReferensi ?? '',
            'HargaTermasukPajak' => $penawaran->HargaTermasukPajak,
            'Catatan' => $penawaran->Catatan ?? '',
            'Syarat' => $penawaran->Syarat ?? '',
            'Baris' => array_values($detail->map(fn (PenawaranDetail $d): array => [
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
     * Kebiasaan terakhir untuk penawaran baru: syarat dari penawaran terbaru tenant ini.
     *
     * @return array{Syarat: string}
     */
    public function Kebiasaan(): array
    {
        $terakhir = Penawaran::query()->whereNotNull('Syarat')->orderByDesc('Id')->first(['Syarat']);

        return ['Syarat' => $terakhir->Syarat ?? ''];
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
