<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\SatuanProdukPembelian;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelianDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use Illuminate\Support\Collection;

/**
 * Props halaman permintaan pembelian (F-04 PR; tipe FE di `Tipe/Pembelian.ts`): daftar `TabelData` (dibatasi outlet
 * yang boleh diakses pelaku; cari nomor/nama pemasok usulan; saring Status, Tanggal), detail dengan riwayat, dan isian
 * awal formulir ubah.
 */
final class TampilanPermintaanPembelian
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor'];

    public const KOLOM_SARING = ['Status', 'Tanggal'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(
        private readonly PetaNamaPembelian $peta,
        private readonly InfoProdukStok $infoProduk,
        private readonly SatuanProdukPembelian $satuan,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Daftar(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusPermintaanPembelian $s): string => $s->value, StatusPermintaanPembelian::cases()));
        ['Dari' => $dari, 'Sampai' => $sampai] = $p->AmbilRentangTanggal('Tanggal');
        $pola = PenerapKueriTabel::PolaCari($p->cari);
        $kueri = PermintaanPembelian::query()
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($q) => $q->whereIn('Status', $status))
            ->when($dari !== null, fn ($q) => $q->where('Tanggal', '>=', (string) $dari))
            ->when($sampai !== null, fn ($q) => $q->where('Tanggal', '<=', (string) $sampai))
            ->when($p->cari !== '', fn ($q) => $q->where(fn ($dalam) => $dalam
                ->where('Nomor', 'like', $pola)
                ->orWhere('Catatan', 'like', $pola)
                ->orWhereIn('IdPemasok', Pemasok::query()->withTrashed()->where('Nama', 'like', $pola)->select('Id'))));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor'], function (Collection $baris): array {
            $pemasok = $this->peta->Pemasok($baris->pluck('IdPemasok')->filter()->all());
            $gudang = $this->peta->Gudang($baris->pluck('IdGudang')->all());
            $jumlah = PermintaanPembelianDetail::query()->whereIn('IdPermintaanPembelian', $baris->pluck('Id')->all())
                ->selectRaw('`IdPermintaanPembelian`, COUNT(*) AS `Jumlah`')->groupBy('IdPermintaanPembelian')->pluck('Jumlah', 'IdPermintaanPembelian');

            return array_values($baris->map(fn (PermintaanPembelian $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'DibutuhkanPada' => $d->DibutuhkanPada?->format('Y-m-d'),
                'NamaPemasok' => $d->IdPemasok === null ? null : ($pemasok[$d->IdPemasok]['Nama'] ?? null),
                'NamaGudang' => $gudang[$d->IdGudang]->nama ?? '',
                'NamaOutlet' => $gudang[$d->IdGudang]->namaOutlet ?? null,
                'JumlahBaris' => (int) ($jumlah[$d->Id] ?? 0),
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
            ])->all());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function Detail(PermintaanPembelian $permintaan): array
    {
        $gudang = $this->peta->Gudang([$permintaan->IdGudang])[$permintaan->IdGudang] ?? null;
        $pengguna = $this->peta->Pengguna([$permintaan->DibuatOleh, $permintaan->DiputuskanOleh, $permintaan->DibatalkanOleh, $permintaan->DijadikanPoOleh]);
        $pemasok = $permintaan->IdPemasok === null ? null : $permintaan->Pemasok;
        $po = $permintaan->IdPesananPembelian === null ? null : PesananPembelian::query()->whereKey($permintaan->IdPesananPembelian)->first(['Uuid', 'Nomor']);
        $riwayat = RiwayatStatusDokumen::query()->where('JenisDokumen', PermintaanPembelian::JENIS_DOKUMEN)->where('IdDokumen', $permintaan->Id)->orderBy('Id')->get();
        $namaRiwayat = $this->peta->Pengguna($riwayat->pluck('DiubahOleh')->all());

        return [
            'Permintaan' => [
                'Uuid' => $permintaan->Uuid,
                'Nomor' => $permintaan->Nomor,
                'Tanggal' => $permintaan->Tanggal->format('Y-m-d'),
                'DibutuhkanPada' => $permintaan->DibutuhkanPada?->format('Y-m-d'),
                'Status' => $permintaan->Status->value,
                'LabelStatus' => $permintaan->Status->AmbilLabel(),
                'Pemasok' => $pemasok === null ? null : ['Uuid' => $pemasok->Uuid, 'Kode' => $pemasok->Kode, 'Nama' => $pemasok->Nama],
                'UuidGudang' => $gudang?->uuid,
                'NamaGudang' => $gudang->nama ?? '',
                'NamaOutlet' => $gudang?->namaOutlet,
                'Catatan' => $permintaan->Catatan,
                'AlasanDitolak' => $permintaan->AlasanDitolak,
                'AlasanBatal' => $permintaan->AlasanBatal,
                'DibuatOleh' => $permintaan->DibuatOleh === null ? null : ($pengguna[$permintaan->DibuatOleh] ?? null),
                'DiputuskanOleh' => $permintaan->DiputuskanOleh === null ? null : ($pengguna[$permintaan->DiputuskanOleh] ?? null),
                'DiputuskanPada' => $permintaan->DiputuskanPada?->toIso8601String(),
                'IdPembuat' => $permintaan->DibuatOleh,
                'IdPengaju' => $permintaan->DiajukanOleh,
                'Pesanan' => $po === null ? null : ['Uuid' => $po->Uuid, 'Nomor' => $po->Nomor],
            ],
            'Baris' => array_values($permintaan->Detail()->get()->map(fn (PermintaanPembelianDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'HargaPerkiraan' => $d->HargaPerkiraan,
            ])->all()),
            'Riwayat' => array_values($riwayat->map(fn (RiwayatStatusDokumen $r): array => [
                'StatusKe' => (string) $r->StatusKe,
                'Oleh' => $r->DiubahOleh === null ? null : ($namaRiwayat[$r->DiubahOleh] ?? null),
                'Pada' => $r->DiubahPada?->toIso8601String() ?? '',
                'Alasan' => $r->Alasan,
            ])->all()),
        ];
    }

    /**
     * Isian awal formulir ubah: baris lengkap dengan pilihan satuan pembelian (sama bentuknya dengan draf PO).
     *
     * @return array<string, mixed>
     */
    public function Isian(PermintaanPembelian $permintaan): array
    {
        $detail = $permintaan->Detail()->get();
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
        $satuan = $this->satuan->AmbilUntukProduk(array_keys($produk));
        $gudang = $this->peta->Gudang([$permintaan->IdGudang])[$permintaan->IdGudang] ?? null;
        $pemasok = $permintaan->IdPemasok === null ? null : $permintaan->Pemasok;

        return [
            'Uuid' => $permintaan->Uuid,
            'Nomor' => $permintaan->Nomor,
            'UuidPemasok' => $pemasok?->Uuid,
            'UuidGudang' => $gudang->uuid ?? '',
            'Tanggal' => $permintaan->Tanggal->format('Y-m-d'),
            'DibutuhkanPada' => $permintaan->DibutuhkanPada?->format('Y-m-d'),
            'Catatan' => $permintaan->Catatan,
            'Baris' => array_values($detail->map(function (PermintaanPembelianDetail $d) use ($produk, $satuan): array {
                $info = $produk[$d->IdProduk] ?? null;
                $pilihan = $satuan[$d->IdProduk] ?? [];
                $terpilih = array_values(array_filter($pilihan, fn (array $s): bool => $s['Id'] === $d->IdProdukSatuan))[0] ?? null;

                return [
                    'UuidProduk' => $info->uuid ?? '',
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'Pelacakan' => $info?->pelacakan->value ?? 'Tidak',
                    'SimbolSatuan' => $info->simbolSatuan ?? $d->SimbolSatuan,
                    'BolehDesimal' => $info->bolehDesimal ?? false,
                    'Satuan' => array_map(fn (array $s): array => ['Uuid' => $s['Uuid'], 'Simbol' => $s['Simbol'], 'Nama' => $s['Nama'], 'Konversi' => $s['Konversi'], 'DefaultBeli' => $s['DefaultBeli']], $pilihan),
                    'UuidProdukSatuan' => $terpilih['Uuid'] ?? null,
                    'Jumlah' => (string) Kuantitas::Dari($d->Jumlah)->KeDesimal()->strippedOfTrailingZeros(),
                    'Harga' => $d->HargaPerkiraan,
                    'Diskon' => '0.00',
                ];
            })->all()),
        ];
    }
}
