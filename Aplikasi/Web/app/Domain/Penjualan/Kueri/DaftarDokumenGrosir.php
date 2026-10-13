<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Daftar dokumen grosir untuk `TabelData` (F-12, §9.7): SO, surat jalan, faktur penjualan, dan retur (BR-12.7). Semua dibatasi outlet
 * yang boleh diakses pelaku (null = semua). Cari: nomor dokumen. Saring: `Status`, `Tanggal` (rentang), dan khusus
 * surat jalan `Difakturkan` (Belum/Sudah) — saringan itu yang dipakai butir Kotak Tindakan BR-12.4.
 *
 * Nama pelanggan dan sisa piutang diambil lewat layanan publik domain Pelanggan, bukan dengan membaca tabelnya.
 */
final class DaftarDokumenGrosir
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor', 'Total', 'JatuhTempo'];

    public const KOLOM_SARING = ['Status', 'Pelanggan', 'Tanggal', 'Difakturkan', 'Pengiriman'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly PencatatPiutangPenjualan $piutang,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Pesanan(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = $this->Saring(PesananGrosir::query(), $p, $idOutletBoleh, array_map(fn (StatusPesananGrosir $s): string => $s->value, StatusPesananGrosir::cases()));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total'], function (Collection $baris): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode($this->IdOutlet($baris->pluck('IdOutlet')->all()));

            return array_values($baris->map(fn (PesananGrosir $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'TanggalKirimDiminta' => $d->TanggalKirimDiminta?->format('Y-m-d'),
                'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
                'Total' => $d->Total,
                'ButuhPersetujuan' => $d->IdPenyetujuKredit !== null,
            ])->all());
        });
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function SuratJalan(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = $this->Saring(SuratJalan::query(), $p, $idOutletBoleh, array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()));
        $difakturkan = $p->saring['Difakturkan'] ?? null;
        $pengiriman = $p->saring['Pengiriman'] ?? null;
        $kueri = $kueri
            ->when($difakturkan === 'Belum', fn ($q) => $q->whereNull('IdFakturPenjualan'))
            ->when($difakturkan === 'Sudah', fn ($q) => $q->whereNotNull('IdFakturPenjualan'))
            ->when(is_string($pengiriman) && StatusPengirimanGrosir::tryFrom($pengiriman) !== null, fn ($q) => $q->where('StatusPengiriman', $pengiriman));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total'], function (Collection $baris): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode($this->IdOutlet($baris->pluck('IdOutlet')->all()));
            $pesanan = PesananGrosir::query()->whereIn('Id', $baris->pluck('IdPesananGrosir')->all())->pluck('Nomor', 'Id');
            $faktur = FakturPenjualan::query()->whereIn('Id', $baris->pluck('IdFakturPenjualan')->filter()->all())->pluck('Nomor', 'Id');

            return array_values($baris->map(fn (SuratJalan $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'NomorPesanan' => $pesanan->get($d->IdPesananGrosir),
                'NomorFaktur' => $d->IdFakturPenjualan === null ? null : $faktur->get($d->IdFakturPenjualan),
                'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
                'StatusPengiriman' => $d->StatusPengiriman->value,
                'LabelStatusPengiriman' => $d->StatusPengiriman->AmbilLabel(),
                'Total' => $d->Total,
                'TotalHpp' => $d->TotalHpp,
            ])->all());
        });
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Faktur(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = $this->Saring(FakturPenjualan::query(), $p, $idOutletBoleh, array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()), 'NomorFakturPajak');

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total', 'JatuhTempo' => 'JatuhTempo'], function (Collection $baris): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode($this->IdOutlet($baris->pluck('IdOutlet')->all()));
            // Sisa tagihan adalah milik `Piutang` (BR-12.5); faktur tidak menyimpan salinannya.
            $piutang = $this->piutang->AmbilPiutangBanyakFaktur($baris->pluck('Id')->all());

            return array_values($baris->map(fn (FakturPenjualan $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'NomorFakturPajak' => $d->NomorFakturPajak,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'JatuhTempo' => $d->JatuhTempo->format('Y-m-d'),
                'PeriodePenyerahan' => $d->PeriodePenyerahan,
                'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
                'Total' => $d->Total,
                'Sisa' => $piutang[$d->Id]['Sisa'] ?? null,
                'StatusPiutang' => $piutang[$d->Id]['Status'] ?? null,
                'LabelStatusPiutang' => $piutang[$d->Id]['LabelStatus'] ?? null,
            ])->all());
        });
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Retur(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $kueri = $this->Saring(ReturGrosir::query(), $p, $idOutletBoleh, array_map(fn (StatusDokumenTerposting $s): string => $s->value, StatusDokumenTerposting::cases()));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total'], function (Collection $baris): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode($this->IdOutlet($baris->pluck('IdOutlet')->all()));
            $suratJalan = SuratJalan::query()->whereIn('Id', $baris->pluck('IdSuratJalan')->all())->pluck('Nomor', 'Id');
            $faktur = FakturPenjualan::query()->whereIn('Id', $baris->pluck('IdFakturPenjualan')->filter()->all())->pluck('Nomor', 'Id');

            return array_values($baris->map(fn (ReturGrosir $d): array => [
                'Uuid' => $d->Uuid,
                'Nomor' => $d->Nomor,
                'Tanggal' => $d->Tanggal->format('Y-m-d'),
                'NomorSuratJalan' => $suratJalan->get($d->IdSuratJalan),
                'NomorFaktur' => $d->IdFakturPenjualan === null ? null : $faktur->get($d->IdFakturPenjualan),
                'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                'Status' => $d->Status->value,
                'LabelStatus' => $d->Status->AmbilLabel(),
                'Total' => $d->Total,
                'MengurangiPiutang' => $d->MengurangiPiutang,
                'PerluTinjauan' => $d->PerluTinjauan,
                'Alasan' => $d->Alasan,
            ])->all());
        });
    }

    /**
     * `Collection` PHPStan tidak kovarian atas TValue, jadi yang diterima di sini larik hasil `pluck`, bukan koleksi
     * modelnya — supaya satu pembantu ini bisa dipakai ketiga daftar tanpa cast.
     *
     * @param  array<mixed>  $idOutlet
     * @return list<int>
     */
    private function IdOutlet(array $idOutlet): array
    {
        return array_values(array_unique(array_filter($idOutlet, 'is_int')));
    }

    /**
     * Saringan bersama: outlet boleh, status, pelanggan (Uuid), rentang tanggal, dan cari nomor.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $kueri
     * @param  list<int>|null  $idOutletBoleh
     * @param  list<string>  $statusBoleh
     * @return Builder<TModel>
     */
    private function Saring(Builder $kueri, DataPermintaanTabel $p, ?array $idOutletBoleh, array $statusBoleh, ?string $kolomCari = null): Builder
    {
        $status = $p->AmbilDaftar('Status', $statusBoleh);
        $uuidPelanggan = $p->saring['Pelanggan'] ?? null;
        $idPelanggan = $uuidPelanggan === null ? null : ($this->identitas->CariId((string) $uuidPelanggan) ?? 0);
        ['Dari' => $dari, 'Sampai' => $sampai] = $p->AmbilRentangTanggal('Tanggal');
        $pola = PenerapKueriTabel::PolaCari($p->cari);

        return $kueri
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($q) => $q->whereIn('Status', $status))
            ->when($idPelanggan !== null, fn ($q) => $q->where('IdPelanggan', $idPelanggan))
            ->when($dari !== null, fn ($q) => $q->where('Tanggal', '>=', (string) $dari))
            ->when($sampai !== null, fn ($q) => $q->where('Tanggal', '<=', (string) $sampai))
            ->when($p->cari !== '', fn ($q) => $q->where(fn ($dalam) => $dalam
                ->where('Nomor', 'like', $pola)
                ->when($kolomCari !== null, fn ($atau) => $atau->orWhere((string) $kolomCari, 'like', $pola))));
    }
}
