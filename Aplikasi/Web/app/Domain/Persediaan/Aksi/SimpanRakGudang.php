<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Model\RakGudang;
use Illuminate\Support\Facades\DB;

/**
 * F-05c INV-07: membuat atau mengubah rak di satu lokasi stok aktif. Kode unik per lokasi stok (huruf besar). Lokasi stok
 * rak tidak bisa dipindah setelah dibuat. Audit `rak-gudang.buat` / `rak-gudang.ubah`.
 */
final class SimpanRakGudang
{
    private const KOLOM_AUDIT = ['IdGudang', 'Kode', 'Nama', 'Urutan', 'Aktif'];

    public function __construct(
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idGudang, ?RakGudang $rak, string $kode, string $nama, int $urutan, int $idPengguna): RakGudang
    {
        $kode = mb_strtoupper(trim($kode));
        $nama = trim($nama);

        if ($kode === '' || $nama === '') {
            throw new PelanggaranAturanBisnis('RakTidakLengkap', 'Isi kode dan nama rak.', $kode === '' ? 'Kode' : 'Nama');
        }

        return DB::transaction(function () use ($idGudang, $rak, $kode, $nama, $urutan, $idPengguna): RakGudang {
            if ($rak === null) {
                $this->pemeriksaLokasi->AmbilLokasi($idGudang);
            } else {
                $rak = RakGudang::query()->whereKey($rak->Id)->lockForUpdate()->firstOrFail();
                $idGudang = $rak->IdGudang;
            }

            $dipakai = RakGudang::query()->where('IdGudang', $idGudang)->where('Kode', $kode)->when($rak !== null, fn ($k) => $k->whereKeyNot($rak?->Id))->exists();

            if ($dipakai) {
                throw new PelanggaranAturanBisnis('KodeRakDipakai', "Kode rak {$kode} sudah dipakai di lokasi stok ini. Pilih kode lain.", 'Kode');
            }

            if ($rak === null) {
                $rak = RakGudang::query()->create(['IdGudang' => $idGudang, 'Kode' => $kode, 'Nama' => $nama, 'Urutan' => $urutan]);
                $this->audit->Catat('rak-gudang.buat', $rak, null, $rak->only(self::KOLOM_AUDIT), idPengguna: $idPengguna);

                return $rak;
            }

            $lama = $rak->only(self::KOLOM_AUDIT);
            $rak->fill(['Kode' => $kode, 'Nama' => $nama, 'Urutan' => $urutan]);

            if ($rak->isDirty()) {
                $rak->save();
                $this->audit->Catat('rak-gudang.ubah', $rak, $lama, $rak->only(self::KOLOM_AUDIT), idPengguna: $idPengguna);
            }

            return $rak;
        });
    }
}
