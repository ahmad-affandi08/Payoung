<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BuilderKueri;
use Illuminate\Support\Collection;

/**
 * Daftar jurnal untuk halaman Jurnal (tipe FE `PropsDaftarJurnal`, DesainF05a C.5/E, `TabelData` D-16), terbaru di atas.
 * Cari nomor jurnal, nomor sumber, keterangan; saring rentang tanggal & jenis sumber. Pengguna yang aksesnya
 * dibatasi ke outlet tertentu hanya melihat jurnal yang semua barisnya berada di outlet aksesnya.
 */
final class DaftarJurnal
{
    public function __construct(private readonly PetaUuidOutlet $outlet) {}

    public const KOLOM_URUT = ['Tanggal', 'Nomor'];

    public const KOLOM_SARING = ['Tanggal', 'JenisSumber'];

    public const URUT_BAWAAN = '-Tanggal';

    /**
     * Daftar untuk `TabelData` (D-16): cari nomor jurnal/nomor sumber/keterangan, saring rentang tanggal & jenis sumber.
     *
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan, ?array $idOutletBoleh = null): array
    {
        $kueri = $this->BuatKueri($permintaan, $idOutletBoleh);

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor'], function (Collection $jurnal): array {
            /** @var list<Jurnal> $daftar */
            $daftar = array_values($jurnal->all());
            $idDibalik = self::AmbilIdYangDibalik(array_map(fn (Jurnal $j): int => $j->Id, $daftar));

            return array_map(fn (Jurnal $j): array => self::PetakanBaris($j, isset($idDibalik[$j->Id])), $daftar);
        });
    }

    /**
     * Ekspor ke software akuntansi (FIN-12, F28): satu baris per baris jurnal (akun, debit, kredit) dari jurnal yang lolos
     * cari & saringan yang sama dengan daftar, urut tanggal lalu nomor, dialirkan per 200 jurnal tanpa memuat semuanya.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return Generator<int, list<string>>
     */
    public function AlirkanBarisEkspor(DataPermintaanTabel $permintaan, ?array $idOutletBoleh = null): Generator
    {
        $kueri = $this->BuatKueri($permintaan, $idOutletBoleh)->orderBy('Tanggal')->orderBy('Id');
        $namaOutlet = array_column($this->outlet->AmbilRingkas(), 'Nama', 'Id');

        foreach ($kueri->clone()->toBase()->select('Id')->pluck('Id')->chunk(200) as $potongan) {
            $jurnal = Jurnal::query()->whereIn('Id', $potongan->all())->orderBy('Tanggal')->orderBy('Id')->get();
            $baris = JurnalDetail::query()
                ->join('Akun', fn ($j) => $j->on('Akun.Id', '=', 'JurnalDetail.IdAkun')->on('Akun.IdTenant', '=', 'JurnalDetail.IdTenant'))
                ->whereIn('JurnalDetail.IdJurnal', $potongan->all())
                ->orderBy('JurnalDetail.IdJurnal')
                ->orderBy('JurnalDetail.Id')
                ->get(['JurnalDetail.IdJurnal', 'JurnalDetail.IdOutlet', 'JurnalDetail.Debit', 'JurnalDetail.Kredit', 'JurnalDetail.Memo', 'Akun.Kode AS KodeAkun', 'Akun.Nama AS NamaAkun'])
                ->groupBy('IdJurnal');

            foreach ($jurnal as $j) {
                foreach ($baris->get($j->Id, collect()) as $b) {
                    yield [
                        $j->Tanggal->toDateString(),
                        $j->Nomor,
                        $j->JenisSumber->AmbilLabel(),
                        (string) $j->NomorSumber,
                        $j->Keterangan,
                        (string) $b->getAttribute('KodeAkun'),
                        (string) $b->getAttribute('NamaAkun'),
                        $b->IdOutlet === null ? '' : ($namaOutlet[(int) $b->IdOutlet] ?? ''),
                        (string) ($b->Memo ?? ''),
                        (string) $b->Debit,
                        (string) $b->Kredit,
                    ];
                }
            }
        }
    }

    /**
     * Kueri jurnal untuk cari & saringan tabel (tanggal, jenis sumber) dan batas outlet akses.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<Jurnal>
     */
    private function BuatKueri(DataPermintaanTabel $permintaan, ?array $idOutletBoleh): Builder
    {
        $kata = $permintaan->cari;
        $tanggal = $permintaan->AmbilRentangTanggal('Tanggal');
        $jenis = $permintaan->AmbilDaftar('JenisSumber', array_map(fn (JenisSumberJurnal $j): string => $j->value, JenisSumberJurnal::cases()));

        $kueri = Jurnal::query()
            ->when($kata !== '', function (Builder $kueri) use ($kata): void {
                $pola = PenerapKueriTabel::PolaCari($kata);
                $kueri->where(fn (Builder $k) => $k->where('Nomor', 'like', $pola)
                    ->orWhere('NomorSumber', 'like', $pola)
                    ->orWhere('Keterangan', 'like', $pola));
            })
            ->when($tanggal['Dari'] !== null, fn (Builder $k) => $k->where('Tanggal', '>=', $tanggal['Dari']))
            ->when($tanggal['Sampai'] !== null, fn (Builder $k) => $k->where('Tanggal', '<=', $tanggal['Sampai']))
            ->when($jenis !== [], fn (Builder $k) => $k->whereIn('JenisSumber', $jenis));

        self::BatasiOutlet($kueri, $idOutletBoleh);

        return $kueri;
    }

    /**
     * Baris daftar (`BarisDaftarJurnal`). `Dibalik` = sudah ada jurnal pembaliknya; `Pembalik` = jurnal ini pembalik.
     *
     * @return array<string, mixed>
     */
    public static function PetakanBaris(Jurnal $jurnal, bool $dibalik): array
    {
        return [
            'Uuid' => $jurnal->Uuid,
            'Nomor' => $jurnal->Nomor,
            'Tanggal' => $jurnal->Tanggal->toDateString(),
            'JenisSumber' => $jurnal->JenisSumber->value,
            'LabelJenisSumber' => $jurnal->JenisSumber->AmbilLabel(),
            'NomorSumber' => $jurnal->NomorSumber,
            'TautanSumber' => $jurnal->JenisSumber->BuatTautan($jurnal->UuidSumber),
            'Keterangan' => $jurnal->Keterangan,
            'TotalDebit' => $jurnal->TotalDebit,
            'Otomatis' => $jurnal->Otomatis,
            'Dibalik' => $dibalik,
            'Pembalik' => $jurnal->IdJurnalDibalik !== null,
        ];
    }

    /**
     * Jurnal dengan baris di luar outlet akses (atau tanpa outlet = tingkat usaha) disembunyikan.
     *
     * @param  Builder<Jurnal>  $kueri
     * @param  list<int>|null  $idOutletBoleh
     */
    public static function BatasiOutlet(Builder $kueri, ?array $idOutletBoleh): void
    {
        if ($idOutletBoleh === null) {
            return;
        }

        $kueri->whereNotExists(function (BuilderKueri $sub) use ($idOutletBoleh): void {
            $sub->from((new JurnalDetail)->getTable(), 'd')
                ->whereColumn('d.IdJurnal', 'Jurnal.Id')
                ->whereColumn('d.IdTenant', 'Jurnal.IdTenant')
                ->where(fn (BuilderKueri $k) => $k->whereNull('d.IdOutlet')->orWhereNotIn('d.IdOutlet', $idOutletBoleh === [] ? [0] : $idOutletBoleh));
        });
    }

    /**
     * @param  list<int>  $idJurnal
     * @return array<int, true>
     */
    private static function AmbilIdYangDibalik(array $idJurnal): array
    {
        if ($idJurnal === []) {
            return [];
        }

        $hasil = [];

        foreach (Jurnal::query()->whereIn('IdJurnalDibalik', $idJurnal)->pluck('IdJurnalDibalik') as $id) {
            $hasil[(int) $id] = true;
        }

        return $hasil;
    }
}
