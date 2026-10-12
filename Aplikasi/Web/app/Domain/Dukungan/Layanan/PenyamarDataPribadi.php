<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

/**
 * Menyamarkan data pribadi pada data yang ditampilkan kepada petugas dukungan (PGL-16, BR-P09.1): nomor HP, email,
 * alamat, tanggal lahir, identitas pajak, rekening, dan nama pelanggan diganti tanda "•" kecuali Owner membukanya secara
 * sadar. Penentuannya lewat nama kunci data (kunci JSON = nama kolom, jadi konsisten di semua halaman dan tabel) ditambah
 * bentuk nilai email, sehingga halaman baru yang memakai nama kunci yang sama otomatis ikut tersamar.
 */
final class PenyamarDataPribadi
{
    private const TANDA = '•';

    /** Kunci berisi nomor telepon: sisakan tiga angka terakhir. */
    private const KUNCI_TELEPON = ['NoHp', 'NoHpPelanggan', 'NoWa', 'NoTelepon', 'Telepon', 'NomorHp', 'NomorTelepon'];

    /** Kunci berisi nama orang: sisakan huruf pertama. */
    private const KUNCI_NAMA = ['NamaPelanggan', 'NamaPemesan', 'NamaPembeli', 'NamaKontak', 'NamaPasien', 'NamaPenerima', 'NamaTamu', 'NamaDokter', 'Pelanggan', 'Penerima', 'Pemesan'];

    /** Kunci yang nilainya disamarkan seluruhnya. */
    private const KUNCI_PENUH = [
        'Alamat', 'AlamatKirim', 'AlamatPasien', 'AlamatTerakhir', 'AlamatDasar', 'AlamatUsaha', 'AlamatNpwp', 'AlamatPelanggan',
        'Npwp', 'NpwpPelanggan', 'Nik', 'NikPelanggan', 'NomorRekening', 'NomorRekeningTujuan', 'NamaPemilikRekening',
        'TanggalLahir', 'Kontak', 'CatatanPelanggan', 'NomorSip', 'NomorStr',
    ];

    /** Kunci dalam objek pelanggan yang juga nama orang. */
    private const KUNCI_NAMA_DALAM_PELANGGAN = ['Nama', 'NamaLengkap'];

    /**
     * @param  bool  $dalamPelanggan  Data berada di halaman/objek pelanggan, jadi `Nama` adalah nama orang
     */
    public function Samarkan(mixed $data, bool $dalamPelanggan = false): mixed
    {
        if (! is_array($data)) {
            return is_string($data) ? $this->SamarkanEmailDalamNilai($data) : $data;
        }

        $hasil = [];

        foreach ($data as $kunci => $nilai) {
            if (is_string($kunci)) {
                $jenis = $this->TentukanJenis($kunci, $dalamPelanggan);

                // Kunci berawalan Email juga memuat waktu/status verifikasi; hanya nilai berbentuk alamat yang disamarkan.
                if ($jenis === 'email' && (! is_string($nilai) || ! str_contains($nilai, '@'))) {
                    $jenis = null;
                }

                if ($jenis !== null && (is_string($nilai) || is_int($nilai))) {
                    $hasil[$kunci] = $nilai === '' ? $nilai : $this->SamarkanNilai((string) $nilai, $jenis);

                    continue;
                }

                $hasil[$kunci] = $this->Samarkan($nilai, $dalamPelanggan || str_starts_with($kunci, 'Pelanggan'));

                continue;
            }

            $hasil[$kunci] = $this->Samarkan($nilai, $dalamPelanggan);
        }

        return $hasil;
    }

    public function SamarkanNilai(string $nilai, string $jenis): string
    {
        return match ($jenis) {
            'telepon' => str_repeat(self::TANDA, 6).mb_substr($nilai, -3),
            'email' => $this->SamarkanEmail($nilai),
            'nama' => mb_substr($nilai, 0, 1).str_repeat(self::TANDA, 3),
            default => str_repeat(self::TANDA, 5),
        };
    }

    private function TentukanJenis(string $kunci, bool $dalamPelanggan): ?string
    {
        return match (true) {
            in_array($kunci, self::KUNCI_TELEPON, true) => 'telepon',
            in_array($kunci, self::KUNCI_NAMA, true) => 'nama',
            in_array($kunci, self::KUNCI_PENUH, true) => 'penuh',
            str_starts_with($kunci, 'Email') => 'email',
            $dalamPelanggan && in_array($kunci, self::KUNCI_NAMA_DALAM_PELANGGAN, true) => 'nama',
            default => null,
        };
    }

    /** Alamat email di nilai bebas mana pun (catatan, pesan) ikut disamarkan. */
    private function SamarkanEmailDalamNilai(string $nilai): string
    {
        if (! str_contains($nilai, '@')) {
            return $nilai;
        }

        return (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            fn (array $cocok): string => $this->SamarkanEmail($cocok[0]),
            $nilai,
        );
    }

    private function SamarkanEmail(string $email): string
    {
        $posisi = strrpos($email, '@');

        return $posisi === false || $posisi === 0
            ? str_repeat(self::TANDA, 5)
            : mb_substr($email, 0, 1).str_repeat(self::TANDA, 3).substr($email, $posisi);
    }
}
