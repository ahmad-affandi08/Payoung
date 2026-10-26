<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\PembayaranPiutangAlokasi;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Model\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laporan PPh yang dipotong pelanggan (F-21 bagian 5, D-170): satu baris per **pelunasan** berstatus dicatat yang memotong
 * PPh (mis. PPh 23 atas jasa), lengkap dengan pelanggan (nama, NPWP), invoice yang dilunasi, jenis dan tarif PPh, serta nomor
 * bukti potongnya. PPh itu kredit pajak penjual: uangnya tidak masuk kas, jadi bukti potong harus ditagih dari pelanggan.
 * Karena itu laporan ini membedakan potongan yang **sudah** dan **belum** punya nomor bukti potong.
 *
 * Periode laporan = tanggal pelunasan (saat PPh dipotong). Pelunasan yang dibatalkan tidak dihitung. Dibatasi outlet yang
 * boleh diakses pelaku lewat piutang yang dilunasi (null = semua). Uang string desimal (CLAUDE.md #7).
 *
 * Cari: nomor pelunasan atau nomor bukti potong. Saring: `Tanggal` (rentang), `Bukti` (`Ada` | `Belum`), `Pelanggan`.
 */
final class DaftarPphDipotong
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor', 'PotonganPph'];

    public const KOLOM_SARING = ['Tanggal', 'Bukti', 'Pelanggan'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(private readonly IdentitasPelanggan $identitas) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}, Ringkasan: array<string, mixed>}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = $this->Saring($this->Dasar($idOutletBoleh), $p);
        $hasil = PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'PotonganPph' => 'PotonganPph'], fn (Collection $baris): array => $this->Petakan($baris));

        return [...$hasil, 'Ringkasan' => $this->Ringkas($this->Saring($this->Dasar($idOutletBoleh), $p))];
    }

    /**
     * Rekap untuk saringan yang sama dengan tabel (ikut berubah saat saringan berubah): total PPh dipotong, serta bagian
     * yang sudah dan belum punya bukti potong.
     *
     * @param  Builder<PembayaranPiutang>  $kueri
     * @return array{Total: string, JumlahPelunasan: int, SudahBukti: array{Jumlah: int, Nilai: string}, BelumBukti: array{Jumlah: int, Nilai: string}}
     */
    private function Ringkas(Builder $kueri): array
    {
        $ada = fn (Builder $q): Builder => $q->whereNotNull('NomorBuktiPotong')->where('NomorBuktiPotong', '!=', '');
        $rekap = function (Builder $q): array {
            $baris = $q->selectRaw('COUNT(*) AS Jumlah, COALESCE(SUM(PotonganPph), 0) AS Nilai')->toBase()->first();

            return ['Jumlah' => (int) ($baris->Jumlah ?? 0), 'Nilai' => Uang::Dari((string) ($baris->Nilai ?? '0'))->KeString()];
        };
        $sudah = $rekap($ada(clone $kueri));
        $belum = $rekap((clone $kueri)->where(fn (Builder $q) => $q->whereNull('NomorBuktiPotong')->orWhere('NomorBuktiPotong', '')));

        return [
            'Total' => Uang::Dari($sudah['Nilai'])->Tambah(Uang::Dari($belum['Nilai']))->KeString(),
            'JumlahPelunasan' => $sudah['Jumlah'] + $belum['Jumlah'],
            'SudahBukti' => $sudah,
            'BelumBukti' => $belum,
        ];
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<PembayaranPiutang>
     */
    private function Dasar(?array $idOutletBoleh): Builder
    {
        return PembayaranPiutang::query()
            ->where('Status', StatusPembayaranPiutang::Diposting->value)
            ->where('PotonganPph', '>', 0)
            ->when($idOutletBoleh !== null, fn (Builder $q) => $q->whereIn('Id', PembayaranPiutangAlokasi::query()
                ->whereIn('IdPiutang', Piutang::query()->whereIn('IdOutlet', $idOutletBoleh ?? [])->select('Id'))
                ->select('IdPembayaranPiutang')));
    }

    /**
     * @param  Builder<PembayaranPiutang>  $kueri
     * @return Builder<PembayaranPiutang>
     */
    private function Saring(Builder $kueri, DataPermintaanTabel $p): Builder
    {
        $bukti = $p->saring['Bukti'] ?? null;
        $uuidPelanggan = $p->saring['Pelanggan'] ?? null;
        $idPelanggan = $uuidPelanggan === null ? null : ($this->identitas->CariId((string) $uuidPelanggan) ?? 0);
        ['Dari' => $dari, 'Sampai' => $sampai] = $p->AmbilRentangTanggal('Tanggal');
        $pola = PenerapKueriTabel::PolaCari($p->cari);

        return $kueri
            ->when($dari !== null, fn (Builder $q) => $q->where('Tanggal', '>=', (string) $dari))
            ->when($sampai !== null, fn (Builder $q) => $q->where('Tanggal', '<=', (string) $sampai))
            ->when($idPelanggan !== null, fn (Builder $q) => $q->where('IdPelanggan', $idPelanggan))
            ->when($bukti === 'Ada', fn (Builder $q) => $q->whereNotNull('NomorBuktiPotong')->where('NomorBuktiPotong', '!=', ''))
            ->when($bukti === 'Belum', fn (Builder $q) => $q->where(fn (Builder $dalam) => $dalam->whereNull('NomorBuktiPotong')->orWhere('NomorBuktiPotong', '')))
            ->when($p->cari !== '', fn (Builder $q) => $q->where(fn (Builder $dalam) => $dalam->where('Nomor', 'like', $pola)->orWhere('NomorBuktiPotong', 'like', $pola)));
    }

    /**
     * @param  Collection<int, PembayaranPiutang>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris): array
    {
        $nama = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
        $alokasi = PembayaranPiutangAlokasi::query()->whereIn('IdPembayaranPiutang', $baris->pluck('Id')->all())->where('PotonganPph', '>', 0)->get();
        $piutang = Piutang::query()->whereIn('Id', $alokasi->pluck('IdPiutang')->all())->get()->keyBy('Id');
        $invoice = Invoice::query()->whereIn('Id', $piutang->pluck('IdInvoice')->filter()->all())->get()->keyBy('Id');
        $npwp = [];

        return array_values($baris->map(function (PembayaranPiutang $p) use ($nama, $alokasi, $piutang, $invoice, &$npwp): array {
            $dokumen = [];

            foreach ($alokasi->where('IdPembayaranPiutang', $p->Id) as $a) {
                $pi = $piutang->get($a->IdPiutang);
                $inv = $pi === null || $pi->IdInvoice === null ? null : $invoice->get($pi->IdInvoice);
                $dokumen[] = [
                    'Nomor' => $inv->Nomor ?? $pi->Nomor ?? '',
                    'UuidInvoice' => $inv?->Uuid,
                    'JenisPph' => $inv->KodePph ?? 'PPh',
                    'Tarif' => $inv?->TarifPph,
                    'DasarPph' => $inv?->DasarPph,
                    'PotonganPph' => (string) $a->PotonganPph,
                ];
            }

            $npwp[$p->IdPelanggan] ??= $this->identitas->AmbilUntukInvoice($p->IdPelanggan)['Npwp'] ?? null;
            $ada = $p->NomorBuktiPotong !== null && trim($p->NomorBuktiPotong) !== '';

            return [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->Tanggal->toDateString(),
                'UuidPelanggan' => $nama[$p->IdPelanggan]['Uuid'] ?? null,
                'NamaPelanggan' => $nama[$p->IdPelanggan]['Nama'] ?? '',
                'Npwp' => $npwp[$p->IdPelanggan],
                'Dokumen' => $dokumen,
                'JenisPph' => implode(', ', array_values(array_unique(array_column($dokumen, 'JenisPph')))),
                'PotonganPph' => $p->PotonganPph,
                'NomorBuktiPotong' => $ada ? $p->NomorBuktiPotong : null,
                'BuktiLengkap' => $ada,
            ];
        })->all());
    }
}
