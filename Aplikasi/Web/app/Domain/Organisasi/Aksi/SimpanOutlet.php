<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Data\DataOutlet;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Referensi\Enum\ZonaWaktu;
use App\Domain\Referensi\Kueri\WilayahKota;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use Illuminate\Support\Facades\DB;

/**
 * F-02 langkah 1: tambah atau ubah outlet.
 * - BR-02.1: outlet aktif dibatasi paket (`BatasOutlet`).
 * - BR-02.2: kode unik per tenant (termasuk outlet arsip) dan tidak bisa diubah setelah `KodeDikunciPada` terisi.
 * - BR-02.4: outlet baru langsung mendapat satu lokasi stok jenis Toko.
 * - Zona waktu mengikuti kota (P-02); tanpa kota, WIB/WITA/WIT dipilih manual.
 * - Modul Salesman bagian 3: `Kanvas` menandai outlet kendaraan salesman (lokasi stok Toko = kendaraan) beserta plat
 *   nomornya; nomor kendaraan dikosongkan bila tanda kanvas dimatikan.
 */
final class SimpanOutlet
{
    /** Kolom yang dicatat di log audit. */
    private const KOLOM_AUDIT = ['Nama', 'Kode', 'IdMerek', 'Alamat', 'Telepon', 'KodeKota', 'ZonaWaktu', 'JamTutupBuku', 'ProfilPajak', 'Kanvas', 'NomorKendaraan'];

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly WilayahKota $wilayahKota,
        private readonly PastikanBatasPaket $batasPaket,
        private readonly PemakaianBatasOrganisasi $pemakaian,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(?Outlet $outlet, DataOutlet $data): Outlet
    {
        return DB::transaction(function () use ($outlet, $data): Outlet {
            $kode = mb_strtoupper(trim($data->kode));
            $isian = [
                'Nama' => trim($data->nama),
                'Kode' => $kode,
                'IdMerek' => $this->AmbilIdMerek($data->uuidMerek),
                'Alamat' => $data->alamat === null || trim($data->alamat) === '' ? null : trim($data->alamat),
                'KodeKota' => $data->kodeKota,
                'ZonaWaktu' => $this->TentukanZonaWaktu($data),
                'JamTutupBuku' => $data->jamTutupBuku,
                'ProfilPajak' => [
                    'Pkp' => $data->pkp,
                    'Nitku' => $data->pkp && $data->nitku !== null && trim($data->nitku) !== '' ? trim($data->nitku) : null,
                    'PungutPbjt' => $data->pungutPbjt,
                ],
            ];

            if ($data->telepon !== null) {
                $isian['Telepon'] = trim($data->telepon) === '' ? null : trim($data->telepon);
            }

            if ($data->kanvas !== null) {
                $isian['Kanvas'] = $data->kanvas;
                $isian['NomorKendaraan'] = $data->kanvas ? self::NormalkanNomorKendaraan($data->nomorKendaraan) : null;
            }

            $this->PastikanKodeUnik($kode, $outlet?->Id);

            return $outlet === null ? $this->Buat($isian) : $this->Ubah($outlet, $isian);
        });
    }

    /**
     * @param  array<string, mixed>  $isian
     */
    private function Buat(array $isian): Outlet
    {
        $this->batasPaket->Pastikan($this->konteks->Wajib(), 'BatasOutlet', fn (): int => $this->pemakaian->HitungOutlet());

        $outlet = Outlet::query()->create($isian);
        $kodeGudang = Gudang::query()->where('Kode', $outlet->Kode)->exists() ? "{$outlet->Kode}-TOKO" : $outlet->Kode;
        $gudang = Gudang::query()->create([
            'IdOutlet' => $outlet->Id,
            'Kode' => $kodeGudang,
            'Nama' => "Toko {$outlet->Nama}",
            'Jenis' => JenisGudang::Toko,
        ]);

        $this->audit->Catat('outlet.buat', $outlet, nilaiBaru: $outlet->only(self::KOLOM_AUDIT));
        $this->audit->Catat('gudang.buat', $gudang, nilaiBaru: $gudang->only(['IdOutlet', 'Kode', 'Nama', 'Jenis']));

        return $outlet;
    }

    /**
     * @param  array<string, mixed>  $isian
     */
    private function Ubah(Outlet $outlet, array $isian): Outlet
    {
        $outlet = Outlet::query()->lockForUpdate()->findOrFail($outlet->Id);
        // F-01: kunci lain di ProfilPajak (biaya layanan, harga termasuk pajak) tidak boleh hilang saat outlet diubah.
        // Digabung dari baris yang sudah terkunci supaya perubahan pajak yang baru di-commit tidak tertimpa.
        $isian['ProfilPajak'] = [...($outlet->ProfilPajak ?? []), ...$isian['ProfilPajak']];

        if ($isian['Kode'] !== $outlet->Kode && $outlet->KodeDikunciPada !== null) {
            throw new PelanggaranAturanBisnis('BR-02.2', 'Kode outlet tidak bisa diubah karena outlet sudah bertransaksi. Nomor dokumen lama memakai kode ini.', 'Kode');
        }

        $lama = $outlet->only(self::KOLOM_AUDIT);
        $outlet->fill($isian);
        $berubah = array_keys($outlet->getDirty());

        if ($berubah === []) {
            return $outlet;
        }

        $outlet->save();
        $this->audit->Catat(
            'outlet.ubah',
            $outlet,
            nilaiLama: array_intersect_key($lama, array_flip($berubah)),
            nilaiBaru: $outlet->only($berubah),
        );

        return $outlet;
    }

    /** Plat nomor huruf besar dengan spasi tunggal ("ad  1234 xy" → "AD 1234 XY"); kosong = null. */
    private static function NormalkanNomorKendaraan(?string $nomor): ?string
    {
        $nomor = $nomor === null ? '' : mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $nomor)));

        return $nomor === '' ? null : $nomor;
    }

    private function PastikanKodeUnik(string $kode, ?int $kecualiId): void
    {
        $dipakai = Outlet::query()->where('Kode', $kode)->when($kecualiId !== null, fn ($kueri) => $kueri->whereKeyNot($kecualiId))->exists();

        if ($dipakai) {
            throw new PelanggaranAturanBisnis('BR-02.2', "Kode {$kode} sudah dipakai outlet lain (termasuk outlet yang diarsipkan). Pilih kode lain.", 'Kode');
        }
    }

    private function AmbilIdMerek(string $uuidMerek): int
    {
        $merek = Merek::query()->where('Uuid', $uuidMerek)->first()
            ?? throw new PelanggaranAturanBisnis('MerekTidakDitemukan', 'Pilih merek yang tersedia.', 'Merek');

        return $merek->Id;
    }

    private function TentukanZonaWaktu(DataOutlet $data): string
    {
        if ($data->kodeKota === null) {
            return (ZonaWaktu::tryFrom($data->zonaWaktu) ?? ZonaWaktu::Wib)->AmbilZonaIana();
        }

        $kota = $this->wilayahKota->Cari($data->kodeKota)
            ?? throw new PelanggaranAturanBisnis('KotaTidakDikenal', 'Pilih kabupaten/kota dari daftar.', 'KodeKota');

        return $kota['ZonaWaktuIana'];
    }
}
