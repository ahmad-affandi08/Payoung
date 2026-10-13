<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Detail & lembar hitung stok opname (tipe FE `PropsDetailStokOpname`). Hitung buta (F-05b): selama opname
 * Berlangsung, jumlah sistem, mutasi selama opname, dan selisih TIDAK dikirim ke peramban (null) sehingga penghitung
 * tidak bisa melihatnya lewat respons mana pun; setelah diajukan untuk ditinjau semuanya tampil. Selisih sementara
 * (sebelum disetujui) = fisik − (sistem + mutasi sejak snapshot) dihitung saat dibaca.
 */
final class DetailStokOpname
{
    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly InfoProdukStok $infoProduk,
        private readonly JurnalSumber $jurnalSumber,
        private readonly RiwayatDokumenPersediaan $riwayat,
    ) {}

    public static function CekSistemTersembunyi(StokOpname $o): bool
    {
        return $o->HitungButa && $o->Status === StatusStokOpname::Berlangsung;
    }

    /**
     * @return array{Opname: array<string, mixed>, Baris: list<array<string, mixed>>, Penghitung: list<array<string, mixed>>, Jurnal: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>}
     */
    public function Ambil(StokOpname $o): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$o->IdGudang])[$o->IdGudang] ?? null;
        $detail = StokOpnameDetail::query()->where('IdStokOpname', $o->Id)->orderBy('Urutan')->get();
        $idPenghitung = array_values(array_unique(array_filter($detail->pluck('DihitungOleh')->all(), 'is_int')));
        [$riwayat, $nama] = $this->riwayat->Ambil(StokOpname::JENIS_DOKUMEN, $o->Id, fn (string $s): string => StatusStokOpname::tryFrom($s)?->AmbilLabel() ?? $s, [$o->DibuatOleh, $o->DiajukanOleh, $o->DisetujuiOleh, $o->DibatalkanOleh, ...$idPenghitung]);
        $sembunyi = self::CekSistemTersembunyi($o);
        $sejak = $sembunyi || $o->Status !== StatusStokOpname::Ditinjau ? [] : $this->HitungMutasiSejak($o);

        return [
            'Opname' => [
                'Uuid' => $o->Uuid,
                'Nomor' => $o->Nomor,
                'Status' => $o->Status->value,
                'LabelStatus' => $o->Status->AmbilLabel(),
                'UuidGudang' => $gudang->uuid ?? '',
                'NamaGudang' => $gudang->nama ?? '',
                'NamaOutlet' => $gudang?->namaOutlet,
                'NamaKategori' => $o->NamaKategori,
                'NamaRak' => $o->NamaRak,
                'HitungButa' => $o->HitungButa,
                'SistemTersembunyi' => $sembunyi,
                'TanggalSnapshot' => $o->TanggalSnapshot->format('Y-m-d'),
                'SnapshotPada' => $o->SnapshotPada->toIso8601String(),
                'TanggalPosting' => $o->TanggalPosting?->format('Y-m-d'),
                'Catatan' => $o->Catatan,
                'JumlahBaris' => $o->JumlahBaris,
                'JumlahDihitung' => $o->JumlahDihitung,
                'TotalNilaiLebih' => $o->TotalNilaiLebih,
                'TotalNilaiKurang' => $o->TotalNilaiKurang,
                'AlasanBatal' => $o->AlasanBatal,
                'DibuatOleh' => $nama($o->DibuatOleh),
                'DiajukanOleh' => $nama($o->DiajukanOleh),
                'DisetujuiOleh' => $nama($o->DisetujuiOleh),
                'DisetujuiPada' => $o->DisetujuiPada?->toIso8601String(),
            ],
            'Baris' => $this->AmbilBaris($detail, $sembunyi, $sejak, $nama),
            'Penghitung' => $this->RingkasPenghitung($detail, $nama),
            'Jurnal' => $this->jurnalSumber->Ambil(JenisSumberJurnal::StokOpname, $o->Id),
            'Riwayat' => $riwayat,
        ];
    }

    /**
     * Ringkasan per penghitung (F-05c INV-07): berapa baris yang dihitung tiap orang dan kapan terakhir. Baris belum dihitung
     * tidak masuk. Urut dari yang paling banyak menghitung.
     *
     * @param  Collection<int, StokOpnameDetail>  $detail
     * @param  callable(int|null): ?string  $nama
     * @return list<array{Nama: string, JumlahBaris: int, TerakhirPada: string|null}>
     */
    private function RingkasPenghitung(Collection $detail, callable $nama): array
    {
        $ringkas = [];

        foreach ($detail as $d) {
            if ($d->DihitungOleh === null) {
                continue;
            }

            $kunci = $d->DihitungOleh;
            $ringkas[$kunci] ??= ['Nama' => $nama($kunci) ?? 'Pengguna dihapus', 'JumlahBaris' => 0, 'TerakhirPada' => null];
            $ringkas[$kunci]['JumlahBaris']++;
            $pada = $d->DihitungPada?->toIso8601String();

            if ($pada !== null && ($ringkas[$kunci]['TerakhirPada'] === null || $pada > $ringkas[$kunci]['TerakhirPada'])) {
                $ringkas[$kunci]['TerakhirPada'] = $pada;
            }
        }

        $hasil = array_values($ringkas);
        usort($hasil, fn (array $a, array $b): int => [$b['JumlahBaris'], $a['Nama']] <=> [$a['JumlahBaris'], $b['Nama']]);

        return $hasil;
    }

    /**
     * @param  Collection<int, StokOpnameDetail>  $detail
     * @param  array<int, string>  $sejak  IdDetail → Σ mutasi sejak snapshot (hanya saat Ditinjau)
     * @param  callable(int|null): ?string  $nama
     * @return list<array<string, mixed>>
     */
    private function AmbilBaris(Collection $detail, bool $sembunyi, array $sejak, callable $nama): array
    {
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);

        return array_values($detail->map(function (StokOpnameDetail $d) use ($produk, $sembunyi, $sejak, $nama): array {
            $info = $produk[$d->IdProduk] ?? null;
            $mutasi = $d->MutasiSelamaOpname ?? ($sejak[$d->Id] ?? null);
            $selisih = $d->Selisih;

            if ($selisih === null && $mutasi !== null && $d->JumlahFisik !== null) {
                $selisih = Kuantitas::Dari($d->JumlahFisik)->Kurangi(Kuantitas::Dari($d->JumlahSistem)->Tambah(Kuantitas::Dari($mutasi)))->KeString();
            }

            return [
                'Urutan' => $d->Urutan,
                'UuidProduk' => $info->uuid ?? '',
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $info->simbolSatuan ?? '',
                'BolehDesimal' => $info->bolehDesimal ?? false,
                'Pelacakan' => $info?->pelacakan->value ?? 'Tidak',
                'NomorBatch' => $d->NomorBatch,
                'TanggalKedaluwarsa' => $d->TanggalKedaluwarsa?->format('Y-m-d'),
                'NomorSeri' => $d->NomorSeri,
                'DariSnapshot' => $d->DariSnapshot,
                'JumlahSistem' => $sembunyi ? null : $d->JumlahSistem,
                'JumlahFisik' => $d->JumlahFisik,
                'DihitungOleh' => $nama($d->DihitungOleh),
                'DihitungPada' => $d->DihitungPada?->toIso8601String(),
                'MutasiSelamaOpname' => $sembunyi ? null : $mutasi,
                'Selisih' => $sembunyi ? null : $selisih,
                'NilaiSelisih' => $sembunyi ? null : $d->NilaiSelisih,
            ];
        })->all());
    }

    /**
     * @return array<int, string>
     */
    private function HitungMutasiSejak(StokOpname $o): array
    {
        $hasil = [];

        foreach (StokOpnameDetail::query()->where('IdStokOpname', $o->Id)->whereNotNull('JumlahFisik')->get() as $d) {
            $jumlah = MutasiStok::query()
                ->where('IdProduk', $d->IdProduk)
                ->where('IdGudang', $o->IdGudang)
                ->where('Id', '>', $d->IdMutasiSnapshot)
                ->when($d->NomorBatch !== null, fn ($k) => $k->where('IdBatchStok', $d->IdBatchStok ?? 0))
                ->when($d->NomorSeri !== null, fn ($k) => $k->where('IdNomorSeri', $d->IdNomorSeri ?? 0))
                ->sum('Jumlah');
            $hasil[$d->Id] = (string) BigDecimal::of((string) $jumlah)->toScale(4);
        }

        return $hasil;
    }
}
