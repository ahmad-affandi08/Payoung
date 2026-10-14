<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Aksi\SiapkanProdukMinimumCharge;
use App\Domain\Organisasi\Data\DataMeja;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Layanan\PenjagaModeMeja;
use App\Domain\Organisasi\Model\AreaMeja;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Support\Facades\DB;

/**
 * F-10a: tambah atau ubah meja di satu outlet. Nama unik per outlet ("7", "VIP 2"), kapasitas 1–99, area opsional
 * dan harus area aktif di outlet yang sama. Menambah meja butuh `pos.mode-meja` aktif di outlet (PenjagaModeMeja).
 * `MinimumCharge` (D-144) opsional; mengisinya menyiapkan produk selisih minimum charge bila belum ada.
 */
final class SimpanMeja
{
    private const KOLOM_AUDIT = ['IdOutlet', 'IdAreaMeja', 'Nama', 'Kapasitas', 'Bentuk', 'Urutan', 'MinimumCharge'];

    public function __construct(
        private readonly PenjagaModeMeja $penjaga,
        private readonly PencatatAudit $audit,
        private readonly SiapkanProdukMinimumCharge $produkMinimum,
    ) {}

    public function Jalankan(Outlet $outlet, ?Meja $meja, DataMeja $data): Meja
    {
        return DB::transaction(function () use ($outlet, $meja, $data): Meja {
            $nama = trim($data->nama);
            $dipakai = Meja::query()->where('IdOutlet', $outlet->Id)->where('Nama', $nama)
                ->when($meja !== null, fn ($kueri) => $kueri->whereKeyNot($meja?->Id))->exists();

            if ($dipakai) {
                throw new PelanggaranAturanBisnis('NamaMejaDipakai', "Meja {$nama} sudah ada di outlet ini. Pakai nama atau nomor lain.", 'Nama');
            }

            $idArea = null;

            if ($data->uuidArea !== null && $data->uuidArea !== '') {
                $area = AreaMeja::query()->where('IdOutlet', $outlet->Id)->where('Uuid', $data->uuidArea)->first();

                if ($area === null || ($area->Status !== StatusOrganisasi::Aktif && $area->Id !== $meja?->IdAreaMeja)) {
                    throw new PelanggaranAturanBisnis('AreaTidakValid', 'Pilih area aktif di outlet ini.', 'Area');
                }

                $idArea = $area->Id;
            }

            $isian = ['IdAreaMeja' => $idArea, 'Nama' => $nama, 'Kapasitas' => $data->kapasitas, 'Bentuk' => $data->bentuk, 'Urutan' => $data->urutan, 'MinimumCharge' => $data->minimumCharge?->KeString()];

            if ($data->minimumCharge !== null) {
                // D-144: produk penampung selisih minimum charge disiapkan sekali saat minimum pertama kali dipakai.
                $this->produkMinimum->Jalankan();
            }

            if ($meja === null) {
                $this->penjaga->PastikanBisaMenambah($outlet);
                $meja = Meja::query()->create(['IdOutlet' => $outlet->Id, ...$isian]);
                $this->audit->Catat('meja.buat', $meja, nilaiBaru: self::NilaiAudit($meja, self::KOLOM_AUDIT));

                return $meja;
            }

            $meja = Meja::query()->lockForUpdate()->findOrFail($meja->Id);
            $lama = self::NilaiAudit($meja, self::KOLOM_AUDIT);
            $meja->fill($isian);
            $berubah = array_keys($meja->getDirty());

            if ($berubah !== []) {
                $meja->save();
                $this->audit->Catat('meja.ubah', $meja, nilaiLama: array_intersect_key($lama, array_flip($berubah)), nilaiBaru: self::NilaiAudit($meja, $berubah));
            }

            return $meja;
        });
    }

    /**
     * @param  list<string>  $kolom
     * @return array<string, mixed>
     */
    private static function NilaiAudit(Meja $meja, array $kolom): array
    {
        $nilai = $meja->only($kolom);

        if (array_key_exists('Bentuk', $nilai)) {
            $nilai['Bentuk'] = $meja->Bentuk->value;
        }

        return $nilai;
    }
}
