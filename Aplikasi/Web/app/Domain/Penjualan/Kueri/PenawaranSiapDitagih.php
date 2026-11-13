<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;

/**
 * Penawaran yang sudah diterima pelanggan tetapi belum menjadi invoice aktif, tertua dulu. Aturannya sama dengan butir
 * Kotak Tindakan "Penawaran diterima belum ditagih" (invoice yang dibatalkan tidak dihitung). Dipakai formulir invoice
 * baru supaya tagihan dimulai dari penawarannya, bukan diketik ulang (D-287). Bentuk butir seragam dengan
 * `DokumenHuluPembelian`.
 */
final class PenawaranSiapDitagih
{
    public const BATAS_BAWAAN = 50;

    public function __construct(private readonly IdentitasPelanggan $identitas) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function Daftar(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        $invoiceAktif = Invoice::query()->where('Status', '!=', StatusInvoice::Dibatalkan->value)->select('Id');
        /** @var list<Penawaran> $penawaran */
        $penawaran = Penawaran::query()
            ->where('Status', StatusPenawaran::Diterima->value)
            ->where(fn ($k) => $k->whereNull('IdInvoice')->orWhereNotIn('IdInvoice', $invoiceAktif))
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('DiputuskanPada')
            ->orderBy('Id')
            ->limit($batas)
            ->get()
            ->all();
        $pelanggan = $this->identitas->AmbilNamaBanyak(array_map(fn (Penawaran $p): int => $p->IdPelanggan, $penawaran));

        return array_map(fn (Penawaran $p): array => [
            'Uuid' => $p->Uuid,
            'Nomor' => (string) $p->Nomor,
            'Pihak' => $pelanggan[$p->IdPelanggan]['Nama'] ?? '',
            'Tanggal' => ($p->DiputuskanPada ?? $p->Tanggal)->format('Y-m-d'),
            'Rincian' => ($p->Perihal !== null && $p->Perihal !== '' ? $p->Perihal.' | ' : '').'total '.Uang::Dari((string) $p->Total)->FormatRupiah(),
            'Lencana' => null,
        ], $penawaran);
    }
}
