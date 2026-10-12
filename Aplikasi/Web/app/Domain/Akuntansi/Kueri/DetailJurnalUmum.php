<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalUmum;
use App\Domain\Organisasi\Kueri\DaftarAnggota;

/**
 * Detail satu jurnal umum manual (FIN-05, tipe FE `PropsDetailJurnalUmum`): dokumen, dokumen pembalik/yang dibalik,
 * info lampiran (tanpa path), dan baris jurnalnya (dari `DetailJurnal`, satu-satunya sumber baris). Null bila tidak ada
 * di tenant aktif atau jurnalnya memuat baris di luar outlet akses pelaku (pelaku berbatas outlet tidak melihat jurnal
 * tingkat usaha).
 */
final class DetailJurnalUmum
{
    public function __construct(
        private readonly DetailJurnal $detailJurnal,
        private readonly DaftarAnggota $anggota,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Cari(string $uuid, ?array $idOutletBoleh): ?JurnalUmum
    {
        $dokumen = JurnalUmum::query()->where('Uuid', $uuid)->first();

        if (! $dokumen instanceof JurnalUmum) {
            return null;
        }

        $kueri = Jurnal::query()
            ->where('JenisSumber', JenisSumberJurnal::JurnalUmum->value)
            ->where('IdSumber', $dokumen->Id)
            ->where('KunciSumber', 'Utama');
        DaftarJurnal::BatasiOutlet($kueri, $idOutletBoleh);

        return $kueri->exists() ? $dokumen : null;
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>|null
     */
    public function Ambil(string $uuid, ?array $idOutletBoleh): ?array
    {
        $dokumen = $this->Cari($uuid, $idOutletBoleh);

        if (! $dokumen instanceof JurnalUmum) {
            return null;
        }

        $jurnal = Jurnal::query()
            ->where('JenisSumber', JenisSumberJurnal::JurnalUmum->value)
            ->where('IdSumber', $dokumen->Id)
            ->where('KunciSumber', 'Utama')
            ->first(['Uuid', 'Nomor']);
        $isi = $jurnal instanceof Jurnal ? $this->detailJurnal->Ambil($jurnal->Uuid, $idOutletBoleh) : null;

        if ($isi === null) {
            return null;
        }

        $pembuat = $dokumen->DibuatOleh === null ? [] : $this->anggota->AmbilNamaPengguna($dokumen->IdTenant, [$dokumen->DibuatOleh]);
        $dibalik = $dokumen->IdJurnalUmumDibalik === null ? null : JurnalUmum::query()->whereKey($dokumen->IdJurnalUmumDibalik)->first(['Uuid', 'Nomor']);
        $pembalik = JurnalUmum::query()->where('IdJurnalUmumDibalik', $dokumen->Id)->first(['Uuid', 'Nomor']);

        return [
            'JurnalUmum' => [
                'Uuid' => $dokumen->Uuid,
                'Nomor' => $dokumen->Nomor,
                'Jenis' => $dokumen->Jenis->value,
                'LabelJenis' => $dokumen->Jenis->AmbilLabel(),
                'Tanggal' => $dokumen->Tanggal->toDateString(),
                'Referensi' => $dokumen->Referensi,
                'Keterangan' => $dokumen->Keterangan,
                'TotalNilai' => $dokumen->TotalNilai,
                'Pembalik' => $dokumen->IdJurnalUmumDibalik !== null,
                'Dibalik' => $pembalik instanceof JurnalUmum,
                'DibuatOleh' => $dokumen->DibuatOleh === null ? null : ($pembuat[$dokumen->DibuatOleh] ?? null),
                'DibuatPada' => $dokumen->DibuatPada?->toIso8601String() ?? '',
                'UuidDibalik' => $dibalik?->Uuid,
                'NomorDibalik' => $dibalik?->Nomor,
                'UuidPembalik' => $pembalik?->Uuid,
                'NomorPembalik' => $pembalik?->Nomor,
                'Lampiran' => $dokumen->PathLampiran === null ? null : ['Nama' => $dokumen->NamaLampiran ?? 'Lampiran', 'Ukuran' => $dokumen->UkuranLampiran ?? 0],
            ],
            'Jurnal' => ['Uuid' => $jurnal->Uuid, 'Nomor' => $jurnal->Nomor],
            'Baris' => $isi['Baris'],
            'Total' => $isi['Total'],
        ];
    }
}
