<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;

/**
 * Identitas pajak pembeli untuk dokumen pajak (Faktur Pajak Coretax, PRD v3.11–3.12): NPWP/NIK (didekripsi di sini, hanya
 * dipakai untuk membuat berkas ekspor) beserta nama & alamat sesuai DJP. Kueri publik domain Pelanggan; domain lain tidak
 * membaca tabel `Pelanggan` langsung.
 */
final class IdentitasPajakPelanggan
{
    /**
     * @param  list<int>  $idPelanggan
     * @return array<int, array{Nama: string, Alamat: string|null, Email: string|null, Npwp: string|null, Nik: string|null, NamaNpwp: string|null, AlamatNpwp: string|null}>
     */
    public function AmbilBanyak(array $idPelanggan): array
    {
        $idPelanggan = array_values(array_unique($idPelanggan));

        if ($idPelanggan === []) {
            return [];
        }

        $hasil = [];

        foreach (Pelanggan::query()->whereIn('Id', $idPelanggan)->get() as $p) {
            $hasil[$p->Id] = [
                'Nama' => $p->Nama,
                'Alamat' => $p->Alamat,
                'Email' => $p->Email,
                'Npwp' => $p->Npwp,
                'Nik' => $p->Nik,
                'NamaNpwp' => $p->NamaNpwp,
                'AlamatNpwp' => $p->AlamatNpwp,
            ];
        }

        return $hasil;
    }

    /**
     * Dropdown pembeli Faktur Pajak per transaksi kasir (TAX-07): pelanggan aktif yang cocok nama atau nomor HP, beserta
     * jenis identitas pajaknya (NPWP 15/16 digit atau NIK 16 digit) dan empat digit terakhirnya saja. Pelanggan tanpa
     * identitas sah tetap tampil (`JenisIdentitas` null) supaya operator tahu harus melengkapinya, bukan mengira
     * pelanggannya tidak ada. NPWP/NIK terenkripsi, jadi tidak bisa disaring di basis data.
     *
     * @return list<array{Uuid: string, Nama: string, JenisIdentitas: string|null, IdentitasTersamar: string|null}>
     */
    public function Cari(string $kata, int $batas = 20): array
    {
        $kata = trim($kata);
        $angka = (string) preg_replace('/\D+/', '', $kata);
        $cariHp = $angka !== '' && strlen($angka) >= 3 && preg_match('/^[0-9+() .-]+$/', $kata) === 1;

        return array_values(Pelanggan::query()
            ->where('Status', StatusPelanggan::Aktif->value)
            ->when($kata !== '', fn ($kueri) => $kueri->where(
                $cariHp ? 'NoHp' : 'Nama',
                'like',
                PenerapKueriTabel::PolaCari($cariHp ? (NomorHp::Normalisasi($kata) ?? ltrim($angka, '0')) : $kata),
            ))
            ->orderBy('Nama')->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get()
            ->map(function (Pelanggan $p): array {
                $npwp = (string) preg_replace('/\D/', '', (string) $p->Npwp);
                $nik = (string) preg_replace('/\D/', '', (string) $p->Nik);
                $jenis = in_array(strlen($npwp), [15, 16], true) ? 'NPWP' : (strlen($nik) === 16 ? 'NIK' : null);
                $nomor = $jenis === 'NPWP' ? $npwp : $nik;

                return [
                    'Uuid' => $p->Uuid,
                    'Nama' => $p->Nama,
                    'JenisIdentitas' => $jenis,
                    'IdentitasTersamar' => $jenis === null ? null : '••••'.substr($nomor, -4),
                ];
            })->all());
    }
}
