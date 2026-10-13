<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pembelian\Aksi\SimpanNotaDebitPembelian;
use App\Domain\Pembelian\Enum\JenisNotaDebit;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Domain\Pembelian\Model\NotaDebitPembelianDetail;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use Illuminate\Support\Collection;

/**
 * Tampilan nota debit pembelian (v6.07): daftar (server, saring `Jenis` & `Status`, cari nomor nota/nota pemasok/faktur),
 * isian formulir dari satu faktur (sisa nilai yang bisa diklaim per baris), dan rincian (porsi persediaan/HPP, jurnal).
 */
final class DaftarNotaDebit
{
    public const KOLOM_URUT = ['Nomor', 'Tanggal', 'Total'];

    public const KOLOM_SARING = ['Jenis', 'Status'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(
        private readonly JurnalSumber $jurnal,
        private readonly PengaturanPembelianTenant $pengaturan,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $jenis = $p->AmbilDaftar('Jenis', array_map(fn (JenisNotaDebit $j): string => $j->value, JenisNotaDebit::cases()));
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()));
        $pola = PenerapKueriTabel::PolaCari($p->cari);

        $kueri = NotaDebitPembelian::query()
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($jenis !== [], fn ($k) => $k->whereIn('Jenis', $jenis))
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when($p->cari !== '', fn ($k) => $k->where(fn ($d) => $d->where('Nomor', 'like', $pola)
                ->orWhere('NomorNotaPemasok', 'like', $pola)
                ->orWhereIn('IdFakturPembelian', FakturPembelian::query()->where('Nomor', 'like', $pola)->select('Id'))));

        return PenerapKueriTabel::Terapkan($kueri, $p, array_combine(self::KOLOM_URUT, self::KOLOM_URUT), function (Collection $baris): array {
            /** @var Collection<int, NotaDebitPembelian> $baris */
            $faktur = FakturPembelian::query()->whereKey($baris->pluck('IdFakturPembelian')->unique()->values()->all())->get(['Id', 'Uuid', 'Nomor'])->keyBy('Id');
            $pemasok = Pemasok::query()->withTrashed()->whereKey(array_values(array_filter($baris->pluck('IdPemasok')->unique()->all(), 'is_int')))->pluck('Nama', 'Id');

            return array_values($baris->map(fn (NotaDebitPembelian $n): array => [
                'Uuid' => $n->Uuid,
                'Nomor' => $n->Nomor,
                'NomorNotaPemasok' => $n->NomorNotaPemasok,
                'Tanggal' => $n->Tanggal->toDateString(),
                'Jenis' => $n->Jenis->value,
                'LabelJenis' => $n->Jenis->AmbilLabel(),
                'NomorFaktur' => $faktur->get($n->IdFakturPembelian)->Nomor ?? '',
                'NamaPemasok' => $n->IdPemasok === null ? null : (string) ($pemasok[$n->IdPemasok] ?? ''),
                'Total' => (string) $n->Total,
                'NilaiRefund' => (string) $n->NilaiRefund,
                'Status' => $n->Status->value,
            ])->all());
        });
    }

    /**
     * Isian formulir: ringkasan faktur dan baris yang masih punya sisa nilai untuk diklaim.
     *
     * @return array<string, mixed>
     */
    public function Isian(FakturPembelian $faktur): array
    {
        $baris = FakturPembelianDetail::query()->where('IdFakturPembelian', $faktur->Id)->orderBy('Urutan')->orderBy('Id')->get();
        $sudah = SimpanNotaDebitPembelian::HitungSudahDinotakan(array_values(array_map(fn (FakturPembelianDetail $b): int => $b->Id, $baris->all())));
        $sisa = $faktur->AmbilSisa();

        return [
            'Faktur' => [
                'Uuid' => $faktur->Uuid,
                'Nomor' => $faktur->Nomor,
                'NomorFakturPemasok' => $faktur->NomorFakturPemasok,
                'Tanggal' => $faktur->Tanggal->toDateString(),
                'Status' => $faktur->Status->value,
                'Total' => (string) $faktur->Total,
                'SisaHutang' => ($sisa->BernilaiNegatif() ? Uang::Nol() : $sisa)->KeString(),
                'PpnDikreditkan' => $faktur->PpnDikreditkan,
                'NamaPemasok' => $faktur->IdPemasok === null ? null : Pemasok::query()->withTrashed()->whereKey($faktur->IdPemasok)->value('Nama'),
                'Baris' => array_values($baris->map(function (FakturPembelianDetail $b) use ($sudah): array {
                    $kapasitas = Uang::Dari($b->Subtotal)->Tambah(Uang::Dari($b->AlokasiOngkir))->Kurangi(Uang::Dari($b->NilaiDiretur))->Kurangi($sudah[$b->Id]['NilaiKlaim'] ?? Uang::Nol());

                    return [
                        'Id' => $b->Id,
                        'NamaProduk' => $b->NamaProduk,
                        'Nilai' => Uang::Dari($b->Subtotal)->Tambah(Uang::Dari($b->AlokasiOngkir))->KeString(),
                        'SisaKlaim' => ($kapasitas->BernilaiNegatif() ? Uang::Nol() : $kapasitas)->KeString(),
                    ];
                })->all()),
            ],
            'BatasPersetujuan' => $this->pengaturan->Ambil()->batasPersetujuanPo->KeString(),
        ];
    }

    /**
     * Nota debit satu faktur (untuk halaman rincian faktur).
     *
     * @return list<array<string, mixed>>
     */
    public function UntukFaktur(FakturPembelian $faktur): array
    {
        return array_values(NotaDebitPembelian::query()->where('IdFakturPembelian', $faktur->Id)->orderBy('Id')->get()->map(fn (NotaDebitPembelian $n): array => [
            'Uuid' => $n->Uuid,
            'Nomor' => $n->Nomor,
            'Tanggal' => $n->Tanggal->toDateString(),
            'LabelJenis' => $n->Jenis->AmbilLabel(),
            'Total' => (string) $n->Total,
            'NilaiRefund' => (string) $n->NilaiRefund,
            'Status' => $n->Status->value,
            'LabelStatus' => $n->Status->AmbilLabel(),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function Detail(NotaDebitPembelian $n): array
    {
        $faktur = FakturPembelian::query()->find($n->IdFakturPembelian);
        $detail = NotaDebitPembelianDetail::query()->where('IdNotaDebitPembelian', $n->Id)->orderBy('Urutan')->get();

        return [
            'Nota' => [
                'Uuid' => $n->Uuid,
                'Nomor' => $n->Nomor,
                'NomorNotaPemasok' => $n->NomorNotaPemasok,
                'Tanggal' => $n->Tanggal->toDateString(),
                'Jenis' => $n->Jenis->value,
                'LabelJenis' => $n->Jenis->AmbilLabel(),
                'NilaiKlaim' => (string) $n->NilaiKlaim,
                'Pajak' => (string) $n->Pajak,
                'Total' => (string) $n->Total,
                'PotongHutang' => (string) $n->PotongHutang,
                'NilaiRefund' => (string) $n->NilaiRefund,
                'KePersediaan' => (string) $n->KePersediaan,
                'KeHpp' => (string) $n->KeHpp,
                'Alasan' => $n->Alasan,
                'Status' => $n->Status->value,
                'AlasanBatal' => $n->AlasanBatal,
                'NamaPemasok' => $n->IdPemasok === null ? null : Pemasok::query()->withTrashed()->whereKey($n->IdPemasok)->value('Nama'),
                'UuidFaktur' => $faktur?->Uuid,
                'NomorFaktur' => $faktur->Nomor ?? '',
            ],
            'Baris' => array_values($detail->map(fn (NotaDebitPembelianDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $d->NamaProduk,
                'NilaiKlaim' => (string) $d->NilaiKlaim,
                'Pajak' => (string) $d->Pajak,
                'KePersediaan' => (string) $d->KePersediaan,
                'KeHpp' => (string) $d->KeHpp,
            ])->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::NotaDebitPembelian, $n->Id),
        ];
    }
}
