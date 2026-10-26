<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Pembelian\Enum\JenisBiayaTambahan;
use App\Domain\Pembelian\Model\BiayaTambahanPembelian;
use App\Domain\Pembelian\Model\BiayaTambahanPembelianDetail;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use Illuminate\Support\Collection;

/**
 * Tampilan biaya tambahan pembelian (v3.41): daftar (server, saring `Jenis` & `Status`, cari nomor dokumen/GRN), isian
 * formulir dari satu penerimaan barang, dan rincian (alokasi per baris, porsi persediaan/HPP, jurnal).
 */
final class DaftarBiayaTambahan
{
    public const KOLOM_URUT = ['Nomor', 'Tanggal', 'Jumlah'];

    public const KOLOM_SARING = ['Jenis', 'Status'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly JurnalSumber $jurnal,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $jenis = $p->AmbilDaftar('Jenis', array_map(fn (JenisBiayaTambahan $j): string => $j->value, JenisBiayaTambahan::cases()));
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()));
        $pola = PenerapKueriTabel::PolaCari($p->cari);

        $kueri = BiayaTambahanPembelian::query()
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($jenis !== [], fn ($k) => $k->whereIn('Jenis', $jenis))
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when($p->cari !== '', fn ($k) => $k->where(fn ($d) => $d->where('Nomor', 'like', $pola)
                ->orWhereIn('IdPenerimaanBarang', PenerimaanBarang::query()->where('Nomor', 'like', $pola)->select('Id'))));

        return PenerapKueriTabel::Terapkan($kueri, $p, array_combine(self::KOLOM_URUT, self::KOLOM_URUT), function (Collection $baris): array {
            /** @var Collection<int, BiayaTambahanPembelian> $baris */
            $grn = PenerimaanBarang::query()->whereKey($baris->pluck('IdPenerimaanBarang')->unique()->values()->all())->get(['Id', 'Uuid', 'Nomor'])->keyBy('Id');
            $pemasok = Pemasok::query()->withTrashed()->whereKey(array_values(array_filter($baris->pluck('IdPemasok')->unique()->all(), 'is_int')))->pluck('Nama', 'Id');

            return array_values($baris->map(fn (BiayaTambahanPembelian $b): array => [
                'Uuid' => $b->Uuid,
                'Nomor' => $b->Nomor,
                'Tanggal' => $b->Tanggal->toDateString(),
                'Jenis' => $b->Jenis->value,
                'LabelJenis' => $b->Jenis->AmbilLabel(),
                'NomorPenerimaan' => $grn->get($b->IdPenerimaanBarang)->Nomor ?? '',
                'NamaPenagih' => $b->IdPemasok === null ? null : (string) ($pemasok[$b->IdPemasok] ?? ''),
                'Jumlah' => (string) $b->Jumlah,
                'KePersediaan' => (string) $b->KePersediaan,
                'KeHpp' => (string) $b->KeHpp,
                'Status' => $b->Status->value,
            ])->all());
        });
    }

    /**
     * Isian formulir: ringkasan penerimaan & baris yang masih punya sisa (jumlah & nilai setelah retur).
     *
     * @return array<string, mixed>
     */
    public function Isian(PenerimaanBarang $grn): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$grn->IdGudang])[$grn->IdGudang] ?? null;
        $baris = PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->orderBy('Urutan')->orderBy('Id')->get();

        return [
            'Penerimaan' => [
                'Uuid' => $grn->Uuid,
                'Nomor' => $grn->Nomor,
                'Tanggal' => $grn->Tanggal->toDateString(),
                'Status' => $grn->Status->value,
                'NamaGudang' => $gudang->nama ?? '',
                'NamaPemasok' => $grn->IdPemasok === null ? null : Pemasok::query()->withTrashed()->whereKey($grn->IdPemasok)->value('Nama'),
                'UuidPemasok' => $grn->IdPemasok === null ? null : Pemasok::query()->whereKey($grn->IdPemasok)->value('Uuid'),
                'Baris' => array_values($baris->map(fn (PenerimaanBarangDetail $d): array => [
                    'Id' => $d->Id,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'Jumlah' => Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur))->KeString(),
                    'Nilai' => Uang::Dari($d->Nilai)->Kurangi(Uang::Dari($d->NilaiDiretur))->KeString(),
                    'Pelacakan' => $d->NomorBatch !== null || $d->DaftarNomorSeri !== null,
                ])->all()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Detail(BiayaTambahanPembelian $b): array
    {
        $grn = PenerimaanBarang::query()->find($b->IdPenerimaanBarang);
        $detail = BiayaTambahanPembelianDetail::query()->where('IdBiayaTambahanPembelian', $b->Id)->orderBy('Id')->get();
        $barisGrn = PenerimaanBarangDetail::query()->whereKey($detail->pluck('IdPenerimaanBarangDetail')->all())->get()->keyBy('Id');
        $gudang = $grn === null ? null : ($this->infoGudang->AmbilBanyak([$grn->IdGudang])[$grn->IdGudang] ?? null);

        return [
            'Biaya' => [
                'Uuid' => $b->Uuid,
                'Nomor' => $b->Nomor,
                'Tanggal' => $b->Tanggal->toDateString(),
                'Jenis' => $b->Jenis->value,
                'LabelJenis' => $b->Jenis->AmbilLabel(),
                'LabelDasarAlokasi' => $b->DasarAlokasi->AmbilLabel(),
                'Jumlah' => (string) $b->Jumlah,
                'KePersediaan' => (string) $b->KePersediaan,
                'KeHpp' => (string) $b->KeHpp,
                'Status' => $b->Status->value,
                'Catatan' => $b->Catatan,
                'AlasanBatal' => $b->AlasanBatal,
                'NamaPenagih' => $b->IdPemasok === null ? null : Pemasok::query()->withTrashed()->whereKey($b->IdPemasok)->value('Nama'),
                'UuidPenerimaan' => $grn?->Uuid,
                'NomorPenerimaan' => $grn->Nomor ?? '',
                'NamaGudang' => $gudang->nama ?? '',
            ],
            'Baris' => array_values($detail->map(fn (BiayaTambahanPembelianDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $barisGrn->get($d->IdPenerimaanBarangDetail)->NamaProduk ?? '',
                'Sku' => $barisGrn->get($d->IdPenerimaanBarangDetail)?->Sku,
                'Alokasi' => (string) $d->Alokasi,
                'KePersediaan' => (string) $d->KePersediaan,
                'KeHpp' => (string) $d->KeHpp,
            ])->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::BiayaTambahanPembelian, $b->Id),
        ];
    }
}
