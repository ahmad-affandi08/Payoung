<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Model\SuratJalan;

/**
 * Dokumen hulu grosir yang bisa dipilih untuk memulai dokumen turunannya (D-288): surat jalan yang masih punya barang
 * untuk diretur. Retur grosir selalu milik satu penyerahan (BR-12.7), jadi halaman "buat retur" yang dibuka dari menu
 * memakai daftar ini supaya pengguna memilih suratnya, bukan mencarinya sendiri. Bentuk butir seragam dengan
 * `DokumenHuluPembelian`. Hanya membaca, terbaru dulu, dibatasi outlet yang boleh diakses.
 */
final class DokumenHuluGrosir
{
    public const BATAS_BAWAAN = 100;

    public function __construct(private readonly IdentitasPelanggan $identitas) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function SuratJalanUntukRetur(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        /** @var list<SuratJalan> $surat */
        $surat = array_values(SuratJalan::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereHas('Detail', fn ($d) => $d->whereColumn('Jumlah', '>', 'JumlahDiretur'))
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByDesc('Tanggal')
            ->orderByDesc('Id')
            ->limit($batas)
            ->get()
            ->all());
        $pelanggan = $this->identitas->AmbilNamaBanyak(array_map(fn (SuratJalan $s): int => $s->IdPelanggan, $surat));

        return array_map(fn (SuratJalan $s): array => [
            'Uuid' => $s->Uuid,
            'Nomor' => $s->Nomor,
            'Pihak' => $pelanggan[$s->IdPelanggan]['Nama'] ?? '',
            'Tanggal' => $s->Tanggal->format('Y-m-d'),
            'Rincian' => 'total '.Uang::Dari((string) $s->Total)->FormatRupiah().($s->IdFakturPenjualan === null ? ' | belum difakturkan' : ''),
            'Lencana' => $s->IdFakturPenjualan === null ? null : ['Jenis' => 'netral', 'Teks' => 'Sudah difakturkan'],
        ], $surat);
    }
}
