<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusTampilPenawaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar penawaran untuk `TabelData` (F-21 bagian 3, D-168), dibatasi outlet yang boleh diakses pelaku (null = semua).
 *
 * Cari: nomor, perihal, nomor referensi. Saring: `Status` (nilai `StatusTampilPenawaran`: kedaluwarsa dan "sudah jadi
 * invoice" diturunkan dari tanggal dan `IdInvoice`, bukan kolom), `Pelanggan`, `Tanggal` (rentang).
 */
final class DaftarPenawaran
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor', 'Total', 'BerlakuSampai'];

    public const KOLOM_SARING = ['Status', 'Pelanggan', 'Tanggal'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh, string $hariIni): array
    {
        $kueri = $this->Saring(Penawaran::query(), $p, $idOutletBoleh, $hariIni);

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor', 'Total' => 'Total', 'BerlakuSampai' => 'BerlakuSampai'], function (Collection $baris) use ($hariIni): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode(array_values(array_unique(array_map('intval', $baris->pluck('IdOutlet')->all()))));
            $idInvoice = array_values(array_filter($baris->pluck('IdInvoice')->all(), 'is_int'));
            $statusInvoice = $idInvoice === [] ? [] : Invoice::query()->whereIn('Id', $idInvoice)->pluck('Status', 'Id')->map(fn ($s): string => $s instanceof StatusInvoice ? $s->value : (string) $s)->all();

            return array_values($baris->map(function (Penawaran $d) use ($pelanggan, $outlet, $statusInvoice, $hariIni): array {
                $punyaInvoice = $d->IdInvoice !== null && ($statusInvoice[$d->IdInvoice] ?? null) !== StatusInvoice::Dibatalkan->value;
                $tampil = StatusTampilPenawaran::Tentukan($d->Status, $punyaInvoice, $d->BerlakuSampai->toDateString(), $hariIni);

                return [
                    'Uuid' => $d->Uuid,
                    'Nomor' => $d->Nomor,
                    'Perihal' => $d->Perihal,
                    'Tanggal' => $d->Tanggal->format('Y-m-d'),
                    'BerlakuSampai' => $d->BerlakuSampai->format('Y-m-d'),
                    'NamaPelanggan' => $pelanggan[$d->IdPelanggan]['Nama'] ?? '',
                    'KodeOutlet' => $outlet[$d->IdOutlet] ?? '',
                    'Status' => $d->Status->value,
                    'StatusTampil' => $tampil->value,
                    'LabelStatusTampil' => $tampil->AmbilLabel(),
                    'Total' => $d->Total,
                    'TerkirimPada' => $d->TerkirimPada?->toIso8601String(),
                    'DilihatPada' => $d->DilihatPada?->toIso8601String(),
                ];
            })->all());
        });
    }

    /**
     * @param  Builder<Penawaran>  $kueri
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<Penawaran>
     */
    private function Saring(Builder $kueri, DataPermintaanTabel $p, ?array $idOutletBoleh, string $hariIni): Builder
    {
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusTampilPenawaran $s): string => $s->value, StatusTampilPenawaran::cases()));
        $uuidPelanggan = $p->saring['Pelanggan'] ?? null;
        $idPelanggan = $uuidPelanggan === null ? null : ($this->identitas->CariId((string) $uuidPelanggan) ?? 0);
        ['Dari' => $dari, 'Sampai' => $sampai] = $p->AmbilRentangTanggal('Tanggal');
        $pola = PenerapKueriTabel::PolaCari($p->cari);
        $invoiceAktif = Invoice::query()->where('Status', '!=', StatusInvoice::Dibatalkan->value)->select('Id');

        return $kueri
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($q) => $q->where(function ($dalam) use ($status, $hariIni, $invoiceAktif): void {
                foreach ($status as $nilai) {
                    $dalam->orWhere(function ($satu) use ($nilai, $hariIni, $invoiceAktif): void {
                        match (StatusTampilPenawaran::from($nilai)) {
                            StatusTampilPenawaran::Draf => $satu->where('Status', StatusPenawaran::Draf->value),
                            StatusTampilPenawaran::MenungguKeputusan => $satu->where('Status', StatusPenawaran::Terbit->value)->where('BerlakuSampai', '>=', $hariIni),
                            StatusTampilPenawaran::Kedaluwarsa => $satu->where('Status', StatusPenawaran::Terbit->value)->where('BerlakuSampai', '<', $hariIni),
                            StatusTampilPenawaran::Diterima => $satu->where('Status', StatusPenawaran::Diterima->value)
                                ->where(fn ($d) => $d->whereNull('IdInvoice')->orWhereNotIn('IdInvoice', $invoiceAktif)),
                            StatusTampilPenawaran::DiInvoicekan => $satu->where('Status', StatusPenawaran::Diterima->value)->whereIn('IdInvoice', $invoiceAktif),
                            StatusTampilPenawaran::Ditolak => $satu->where('Status', StatusPenawaran::Ditolak->value),
                            StatusTampilPenawaran::Dibatalkan => $satu->where('Status', StatusPenawaran::Dibatalkan->value),
                        };
                    });
                }
            }))
            ->when($idPelanggan !== null, fn ($q) => $q->where('IdPelanggan', $idPelanggan))
            ->when($dari !== null, fn ($q) => $q->where('Tanggal', '>=', (string) $dari))
            ->when($sampai !== null, fn ($q) => $q->where('Tanggal', '<=', (string) $sampai))
            ->when($p->cari !== '', fn ($q) => $q->where(fn ($dalam) => $dalam
                ->where('Nomor', 'like', $pola)
                ->orWhere('Perihal', 'like', $pola)
                ->orWhere('NomorReferensi', 'like', $pola)));
    }
}
