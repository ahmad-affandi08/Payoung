<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Brick\Math\BigDecimal;
use Throwable;

/**
 * Aturan lama langganan yang boleh dipilih tenant (D-249): lama minimal & maksimal dalam bulan dan tangga diskon menurut
 * lama. Tidak ada angka di kode: nilai dari konsol (`PengaturanPlatform`, diubah Super Admin) menang, selama belum pernah
 * disimpan dipakai nilai awal `config/tagihan.php` (`LamaLangganan`). `BatasTertinggiBulan` dan `MaksimalBulanManual`
 * adalah batas pengaman server (tidak diubah dari konsol): konsol tidak boleh menyetel maksimal di atasnya.
 */
final class PengaturanLamaLangganan
{
    public const KUNCI = 'tagihan.lama-langganan';

    /**
     * @return array{MinimalBulan: int, MaksimalBulan: int, BatasTertinggiBulan: int, MaksimalBulanManual: int, TanggaDiskon: list<array{DariBulan: int, Persen: string}>}
     */
    public function Ambil(): array
    {
        $awal = $this->NilaiAwal();
        $disimpan = $this->BacaKonsol();

        if ($disimpan === null) {
            return $awal;
        }

        $minimal = $this->Bulat($disimpan['MinimalBulan'] ?? null) ?? $awal['MinimalBulan'];
        $maksimal = $this->Bulat($disimpan['MaksimalBulan'] ?? null) ?? $awal['MaksimalBulan'];
        $tangga = isset($disimpan['TanggaDiskon']) && is_array($disimpan['TanggaDiskon'])
            ? self::RapikanTangga($disimpan['TanggaDiskon'])
            : $awal['TanggaDiskon'];

        // Nilai tersimpan yang tak lagi sah (misal batas pengaman diturunkan) tidak boleh membuat tenant tak bisa memilih.
        $maksimal = min($maksimal, $awal['BatasTertinggiBulan']);
        $minimal = max(1, min($minimal, $maksimal));

        return [...$awal, 'MinimalBulan' => $minimal, 'MaksimalBulan' => $maksimal, 'TanggaDiskon' => $tangga];
    }

    public function DariKonsol(): bool
    {
        return $this->BacaKonsol() !== null;
    }

    /**
     * Nilai awal dari server saja (abaikan konsol): dipakai konsol sebagai bawaan isian dan batas pengaman.
     *
     * @return array{MinimalBulan: int, MaksimalBulan: int, BatasTertinggiBulan: int, MaksimalBulanManual: int, TanggaDiskon: list<array{DariBulan: int, Persen: string}>}
     */
    public function NilaiAwal(): array
    {
        $konfigurasi = config('tagihan.LamaLangganan');
        $konfigurasi = is_array($konfigurasi) ? $konfigurasi : [];
        $batas = max(1, $this->Bulat($konfigurasi['BatasTertinggiBulan'] ?? null) ?? 1);
        $maksimal = max(1, min($this->Bulat($konfigurasi['MaksimalBulan'] ?? null) ?? 1, $batas));
        $minimal = max(1, min($this->Bulat($konfigurasi['MinimalBulan'] ?? null) ?? 1, $maksimal));

        return [
            'MinimalBulan' => $minimal,
            'MaksimalBulan' => $maksimal,
            'BatasTertinggiBulan' => $batas,
            'MaksimalBulanManual' => max($batas, $this->Bulat($konfigurasi['MaksimalBulanManual'] ?? null) ?? $batas),
            'TanggaDiskon' => self::RapikanTangga(is_array($konfigurasi['TanggaDiskon'] ?? null) ? $konfigurasi['TanggaDiskon'] : []),
        ];
    }

    /**
     * Lama yang dipilih tenant sendiri (tagihan lewat gerbang): harus bilangan bulat dalam rentang yang diatur.
     */
    public function PastikanSah(int $jumlahBulan, string $kolom = 'JumlahBulan'): void
    {
        $atur = $this->Ambil();

        if ($jumlahBulan < $atur['MinimalBulan'] || $jumlahBulan > $atur['MaksimalBulan']) {
            $rentang = $atur['MinimalBulan'] === $atur['MaksimalBulan']
                ? "{$atur['MinimalBulan']} bulan"
                : "{$atur['MinimalBulan']} sampai {$atur['MaksimalBulan']} bulan";

            throw new PelanggaranAturanBisnis('LamaLanggananTidakSah', "Lama langganan harus {$rentang}.", $kolom);
        }
    }

    /**
     * Batas pengaman server untuk tagihan yang bukan pilihan tenant (perpanjangan otomatis lama yang sudah dipakai):
     * rentang yang diatur konsol tidak berlaku mundur, sehingga menurunkan maksimal tidak menggagalkan perpanjangan.
     */
    public function PastikanDalamBatasPengaman(int $jumlahBulan): void
    {
        $this->PastikanSahManual($jumlahBulan);
    }

    /** Lama tagihan manual (harga kesepakatan): rentang lebih longgar, batas atasnya `MaksimalBulanManual`. */
    public function PastikanSahManual(int $jumlahBulan, string $kolom = 'JumlahBulan'): void
    {
        $batas = $this->Ambil()['MaksimalBulanManual'];

        if ($jumlahBulan < 1 || $jumlahBulan > $batas) {
            throw new PelanggaranAturanBisnis('LamaLanggananTidakSah', "Lama langganan tagihan manual harus 1 sampai {$batas} bulan.", $kolom);
        }
    }

    /**
     * Merapikan dan memvalidasi tangga diskon: DariBulan bilangan bulat >= 1 unik, persen 0..100, diurut naik menurut
     * lama, dan persen tidak boleh turun saat lama bertambah (lama lebih panjang tidak boleh lebih mahal per bulan).
     *
     * @param  array<mixed>  $mentah
     * @return list<array{DariBulan: int, Persen: string}>
     */
    public static function RapikanTangga(array $mentah): array
    {
        $peta = [];

        foreach ($mentah as $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $dari = self::BulatStatis($baris['DariBulan'] ?? null);
            $persen = self::PersenStatis($baris['Persen'] ?? null);

            if ($dari === null || $dari < 1 || $persen === null) {
                continue;
            }

            $peta[$dari] = $persen;
        }

        ksort($peta);
        $hasil = [];
        $tertinggi = BigDecimal::zero();

        foreach ($peta as $dari => $persen) {
            if (BigDecimal::of($persen)->isLessThan($tertinggi)) {
                continue;
            }

            $tertinggi = BigDecimal::of($persen);
            $hasil[] = ['DariBulan' => $dari, 'Persen' => $persen];
        }

        return $hasil;
    }

    /**
     * Memeriksa isian konsol sebelum disimpan: berbeda dari `RapikanTangga` yang diam-diam membuang baris, ini menolak.
     *
     * @param  list<array{DariBulan: mixed, Persen: mixed}>  $tangga
     */
    public function PastikanIsianKonsolSah(int $minimal, int $maksimal, array $tangga): void
    {
        $batas = $this->NilaiAwal()['BatasTertinggiBulan'];

        if ($minimal < 1 || $maksimal < $minimal || $maksimal > $batas) {
            throw new PelanggaranAturanBisnis('LamaLanggananTidakSah', "Lama minimal harus 1 atau lebih dan maksimal tidak boleh kurang dari minimal atau lebih dari {$batas} bulan.", 'MaksimalBulan');
        }

        $rapi = self::RapikanTangga($tangga);

        if (count($rapi) !== count($tangga)) {
            throw new PelanggaranAturanBisnis('TanggaDiskonTidakSah', 'Tangga diskon harus berisi lama (bulan) yang berbeda-beda dan persen 0 sampai 100 yang tidak turun saat lama bertambah.', 'TanggaDiskon');
        }
    }

    /**
     * @param  list<array{DariBulan: int, Persen: string}>  $tangga
     */
    public function Simpan(int $minimal, int $maksimal, array $tangga, int $idPenggunaPengelola): void
    {
        PengaturanPlatform::query()->updateOrCreate(
            ['Kunci' => self::KUNCI],
            [
                'Nilai' => (string) json_encode(['MinimalBulan' => $minimal, 'MaksimalBulan' => $maksimal, 'TanggaDiskon' => self::RapikanTangga($tangga)], JSON_THROW_ON_ERROR),
                'IdPenggunaPengelolaPengubah' => $idPenggunaPengelola,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function BacaKonsol(): ?array
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', self::KUNCI)->value('Nilai');

        if (! is_string($nilai)) {
            return null;
        }

        try {
            $uraian = json_decode($nilai, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($uraian) ? $uraian : null;
    }

    private function Bulat(mixed $nilai): ?int
    {
        return self::BulatStatis($nilai);
    }

    private static function BulatStatis(mixed $nilai): ?int
    {
        if (is_int($nilai)) {
            return $nilai;
        }

        return is_string($nilai) && preg_match('/^\d{1,4}$/', trim($nilai)) === 1 ? (int) trim($nilai) : null;
    }

    private static function PersenStatis(mixed $nilai): ?string
    {
        $teks = is_int($nilai) ? (string) $nilai : (is_string($nilai) ? trim($nilai) : '');

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $teks) !== 1) {
            return null;
        }

        return BigDecimal::of($teks)->isGreaterThan(100) ? null : $teks;
    }
}
