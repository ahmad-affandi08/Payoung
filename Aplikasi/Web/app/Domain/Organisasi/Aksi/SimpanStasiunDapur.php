<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Kueri\StasiunDapurDipakai;
use App\Domain\Organisasi\Data\DataStasiunDapur;
use App\Domain\Organisasi\Model\StasiunDapur;
use Illuminate\Support\Facades\DB;

/**
 * F-10a: tambah atau ubah stasiun dapur tingkat tenant ("Dapur", "Bar", "Pastry"). Nama unik per tenant. Maksimal
 * 20 stasiun aktif (sama dengan batas template sektor).
 *
 * D-310: `Salinan` menjadikannya stasiun checker (menerima salinan semua tiket di printer, tidak memasak). Stasiun yang
 * masih dipakai kategori atau produk tidak bisa dijadikan checker, dan tenant harus tetap punya minimal satu stasiun
 * aktif yang memasak (kalau tidak, tidak ada tiket dapur sama sekali).
 */
final class SimpanStasiunDapur
{
    public const MAKS_AKTIF = 20;

    private const KOLOM_AUDIT = ['Nama', 'Urutan', 'Salinan'];

    public function __construct(private readonly PencatatAudit $audit, private readonly StasiunDapurDipakai $dipakai) {}

    public function Jalankan(?StasiunDapur $stasiun, DataStasiunDapur $data): StasiunDapur
    {
        return DB::transaction(function () use ($stasiun, $data): StasiunDapur {
            $nama = trim($data->nama);
            $dipakai = StasiunDapur::query()->where('Nama', $nama)
                ->when($stasiun !== null, fn ($kueri) => $kueri->whereKeyNot($stasiun?->Id))->exists();

            if ($dipakai) {
                throw new PelanggaranAturanBisnis('NamaStasiunDipakai', "Stasiun {$nama} sudah ada.", 'Nama');
            }

            $isian = ['Nama' => $nama, 'Urutan' => $data->urutan];

            if ($data->salinan !== null) {
                $isian['Salinan'] = $data->salinan;
            }

            if (($isian['Salinan'] ?? false) === true) {
                $this->PastikanBolehJadiSalinan($stasiun);
            }

            if ($stasiun === null) {
                if (StasiunDapur::query()->where('Status', 'Aktif')->count() >= self::MAKS_AKTIF) {
                    throw new PelanggaranAturanBisnis('BatasStasiun', 'Maksimal '.self::MAKS_AKTIF.' stasiun dapur aktif. Arsipkan stasiun yang tidak dipakai dulu.');
                }

                $stasiun = StasiunDapur::query()->create($isian);
                $this->audit->Catat('stasiun-dapur.buat', $stasiun, nilaiBaru: $stasiun->only(self::KOLOM_AUDIT));

                return $stasiun;
            }

            $stasiun = StasiunDapur::query()->lockForUpdate()->findOrFail($stasiun->Id);
            $lama = $stasiun->only(self::KOLOM_AUDIT);
            $stasiun->fill($isian);
            $berubah = array_keys($stasiun->getDirty());

            if ($berubah !== []) {
                $stasiun->save();
                $this->audit->Catat('stasiun-dapur.ubah', $stasiun, nilaiLama: array_intersect_key($lama, array_flip($berubah)), nilaiBaru: $stasiun->only($berubah));
            }

            return $stasiun;
        });
    }

    private function PastikanBolehJadiSalinan(?StasiunDapur $stasiun): void
    {
        if ($stasiun === null || $stasiun->Salinan) {
            return;
        }

        if ($this->dipakai->CekDipakai($stasiun->Id)) {
            throw new PelanggaranAturanBisnis('StasiunMasihDipakai', "Stasiun {$stasiun->Nama} masih dipakai kategori atau menu. Pindahkan dulu ke stasiun lain, lalu jadikan checker.", 'Salinan');
        }

        $lain = StasiunDapur::query()->where('Status', 'Aktif')->where('Salinan', false)->whereKeyNot($stasiun->Id)->exists();

        if (! $lain) {
            throw new PelanggaranAturanBisnis('StasiunMemasakHabis', 'Harus ada minimal satu stasiun yang memasak (bukan checker). Tambahkan stasiun lain dulu.', 'Salinan');
        }
    }
}
