<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusBayarInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar invoice untuk `TabelData` (F-21, D-166), dibatasi outlet yang boleh diakses pelaku (null = semua).
 *
 * Cari: nomor, perihal, nomor referensi pelanggan. Saring: `Status`, `Jenis`, `Pelanggan`, `Tanggal` (rentang), dan
 * `Pembayaran` (`Terbuka`, `LewatTempo`, `Lunas`) yang dibaca dari `Piutang`, bukan dari kolom di invoice. Status
 * penagihan tiap baris diturunkan oleh `StatusBayarInvoice`, satu tafsir yang sama dengan detail dan halaman publik.
 */
final class DaftarInvoice
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor', 'Total', 'JatuhTempo'];

    public const KOLOM_SARING = ['Status', 'Jenis', 'Pelanggan', 'Tanggal', 'Pembayaran'];

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
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh, string $hariIni): array
    {
        $kueri = $this->Saring(Invoice::query(), $p, $idOutletBoleh, $hariIni);

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total', 'JatuhTempo' => 'JatuhTempo'], function (Collection $baris) use ($hariIni): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode(array_values(array_unique(array_map('intval', $baris->pluck('IdOutlet')->all()))));
            $piutang = $this->piutang->AmbilPiutangBanyakInvoice($baris->pluck('Id')->all());

            return array_values($baris->map(function (Invoice $d) use ($pelanggan, $outlet, $piutang, $hariIni): array {
                $infoPiutang = $piutang[$d->Id] ?? null;
                $statusBayar = StatusBayarInvoice::Tentukan(
                    $d->Status,
                    $infoPiutang === null ? null : StatusPiutang::from($infoPiutang['Status']),
                    $d->JatuhTempo->toDateString(),
                    $hariIni,
                );

                return [
                    'Uuid' => $d->Uuid,
                    'Nomor' => $d->Nomor,
                    'Jenis' => $d->Jenis->value,
                    'LabelJenis' => $d->Jenis->AmbilLabel(),
                    'Perihal' => $d->Perihal,
                    'Tanggal' => $d->Tanggal->format('Y-m-d'),
                    'JatuhTempo' => $d->JatuhTempo->format('Y-m-d'),
                    'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                    'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                    'Status' => $d->Status->value,
                    'LabelStatus' => $d->Status->AmbilLabel(),
                    'StatusBayar' => $statusBayar->value,
                    'LabelStatusBayar' => $statusBayar->AmbilLabel(),
                    'Total' => $d->Total,
                    'Tagihan' => $d->Tagihan,
                    'Sisa' => $d->Status === StatusInvoice::Terbit ? ($infoPiutang['Sisa'] ?? '0.00') : null,
                    'TerkirimPada' => $d->TerkirimPada?->toIso8601String(),
                    'DilihatPada' => $d->DilihatPada?->toIso8601String(),
                ];
            })->all());
        });
    }

    /**
     * @param  Builder<Invoice>  $kueri
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<Invoice>
     */
    private function Saring(Builder $kueri, DataPermintaanTabel $p, ?array $idOutletBoleh, string $hariIni): Builder
    {
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusInvoice $s): string => $s->value, StatusInvoice::cases()));
        $jenis = $p->AmbilDaftar('Jenis', array_map(fn (JenisInvoice $j): string => $j->value, JenisInvoice::cases()));
        $uuidPelanggan = $p->saring['Pelanggan'] ?? null;
        $idPelanggan = $uuidPelanggan === null ? null : ($this->identitas->CariId((string) $uuidPelanggan) ?? 0);
        $pembayaran = $p->saring['Pembayaran'] ?? null;
        ['Dari' => $dari, 'Sampai' => $sampai] = $p->AmbilRentangTanggal('Tanggal');
        $pola = PenerapKueriTabel::PolaCari($p->cari);
        $terbuka = [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value];

        return $kueri
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($q) => $q->whereIn('Status', $status))
            ->when($jenis !== [], fn ($q) => $q->whereIn('Jenis', $jenis))
            ->when($idPelanggan !== null, fn ($q) => $q->where('IdPelanggan', $idPelanggan))
            ->when($dari !== null, fn ($q) => $q->where('Tanggal', '>=', (string) $dari))
            ->when($sampai !== null, fn ($q) => $q->where('Tanggal', '<=', (string) $sampai))
            ->when($pembayaran === 'Terbuka', fn ($q) => $q->where('Status', StatusInvoice::Terbit->value)
                ->whereIn('Id', Piutang::query()->whereNotNull('IdInvoice')->whereIn('Status', $terbuka)->select('IdInvoice')))
            ->when($pembayaran === 'LewatTempo', fn ($q) => $q->where('Status', StatusInvoice::Terbit->value)
                ->whereIn('Id', Piutang::query()->whereNotNull('IdInvoice')->whereIn('Status', $terbuka)->where('JatuhTempo', '<', $hariIni)->select('IdInvoice')))
            ->when($pembayaran === 'Lunas', fn ($q) => $q->where('Status', StatusInvoice::Terbit->value)
                ->whereNotIn('Id', Piutang::query()->whereNotNull('IdInvoice')->whereNotIn('Status', [StatusPiutang::Lunas->value])->select('IdInvoice')))
            ->when($p->cari !== '', fn ($q) => $q->where(fn ($dalam) => $dalam
                ->where('Nomor', 'like', $pola)
                ->orWhere('Perihal', 'like', $pola)
                ->orWhere('NomorReferensi', 'like', $pola)));
    }
}
