<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Pengelola\Integrasi\Data\DataKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\StatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Layanan\PenerapKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan konfigurasi integrasi (P-05 langkah 1). Kredensial langsung terenkripsi; hanya petunjuk 4 karakter
 * terakhir yang disimpan terbuka (BR-P05.1). Perubahan pengaturan/kredensial mengembalikan status ke BelumDiuji dan
 * menonaktifkan konfigurasi sampai diuji ulang (BR-P05.4). Log audit hanya memuat nama kolom kredensial (BR-P05.6).
 */
final class SimpanKonfigurasiIntegrasi
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(?PenggunaPengelola $pelaku, DataKonfigurasiIntegrasi $data): KonfigurasiIntegrasi
    {
        // D-35: tanpa pelaku konsol hanya di edisi Lisensi (`lisensi:atur-integrasi` oleh pemasang server pembeli).
        if ($pelaku === null && ! EdisiAplikasi::CekLisensi()) {
            throw new PelanggaranAturanBisnis('D-35', 'Konfigurasi integrasi hanya bisa diubah anggota Platform Pengelola.');
        }

        if ($data->jenis === JenisIntegrasi::GerbangPembayaran) {
            throw new PelanggaranAturanBisnis('GerbangPerTenant', 'Sejak v2.06 gerbang pembayaran diatur tiap tenant dengan akun merchant sendiri. Atur penyedia yang diizinkan di bagian katalog gerbang.', 'Jenis');
        }

        if ($data->lingkungan === LingkunganIntegrasi::Produksi && ($data->alasan === null || trim($data->alasan) === '')) {
            throw new PelanggaranAturanBisnis('BR-P05.2', 'Tulis alasan perubahan konfigurasi produksi.', 'Alasan');
        }

        try {
            $konfigurasi = DB::transaction(fn (): KonfigurasiIntegrasi => $this->Simpan($pelaku, $data));
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('SudahAda', 'Konfigurasi ini baru saja dibuat anggota lain. Muat ulang halaman.');
        }

        PenerapKonfigurasiIntegrasi::LupakanCache();

        return $konfigurasi;
    }

    private function Simpan(?PenggunaPengelola $pelaku, DataKonfigurasiIntegrasi $data): KonfigurasiIntegrasi
    {
        $penyedia = $data->AmbilPenyedia();

        if ($penyedia->AmbilJenis() !== $data->jenis) {
            throw new PelanggaranAturanBisnis('PenyediaTidakCocok', 'Penyedia ini bukan untuk jenis integrasi tersebut.', 'Penyedia');
        }

        $konfigurasi = KonfigurasiIntegrasi::query()
            ->where('Jenis', $data->jenis->value)
            ->where('Lingkungan', $data->lingkungan->value)
            ->lockForUpdate()
            ->first();

        // Ganti penyedia = kredensial lama tidak berlaku lagi (v2.04).
        $gantiPenyedia = $konfigurasi !== null && $konfigurasi->Penyedia !== $penyedia;
        $kredensialLama = $konfigurasi === null || $gantiPenyedia ? [] : $konfigurasi->Kredensial;
        $kredensialBaru = [];
        $kredensialBerubah = [];

        foreach ($penyedia->AmbilBidangKredensial() as $bidang) {
            $kunci = $bidang['Kunci'];
            $nilai = $data->kredensial[$kunci] ?? '';

            if ($nilai === '') {
                if (! $bidang['Wajib']) {
                    if (isset($kredensialLama[$kunci])) {
                        $kredensialBaru[$kunci] = $kredensialLama[$kunci];
                    }

                    continue;
                }

                if (! isset($kredensialLama[$kunci])) {
                    throw new PelanggaranAturanBisnis('KredensialWajib', "{$bidang['Label']} wajib diisi.", "Kredensial.{$kunci}");
                }

                $kredensialBaru[$kunci] = $kredensialLama[$kunci];

                continue;
            }

            $kredensialBaru[$kunci] = $nilai;

            if (($kredensialLama[$kunci] ?? null) !== $nilai) {
                $kredensialBerubah[] = $kunci;
            }
        }

        // D-159: kunci yang dibuat server (pasangan RSA SNAP) bukan isian formulir; tanpa ini setiap simpan menghapusnya.
        foreach ($penyedia->AmbilKunciKredensialOtomatis() as $kunciOtomatis) {
            if (isset($kredensialLama[$kunciOtomatis])) {
                $kredensialBaru[$kunciOtomatis] = $kredensialLama[$kunciOtomatis];
            }
        }

        $pengaturanLama = $konfigurasi?->Pengaturan;
        $pengaturanBerubah = $pengaturanLama !== $data->pengaturan;
        $isiBerubah = $konfigurasi === null || $gantiPenyedia || $pengaturanBerubah || $kredensialBerubah !== [];
        $nilaiLama = $konfigurasi === null ? null : self::AmbilRingkasan($konfigurasi);

        $konfigurasi ??= new KonfigurasiIntegrasi([
            'Jenis' => $data->jenis,
            'Lingkungan' => $data->lingkungan,
            'Penyedia' => $penyedia,
        ]);
        $konfigurasi->fill([
            'Penyedia' => $penyedia,
            'Pengaturan' => $data->pengaturan,
            'Kredensial' => $kredensialBaru,
            'PetunjukKredensial' => self::BuatSemuaPetunjuk(array_diff_key($kredensialBaru, array_flip($penyedia->AmbilKunciKredensialOtomatis()))),
            'RotasiSetiapHari' => $data->rotasiSetiapHari,
        ]);

        if ($kredensialBerubah !== [] || $gantiPenyedia) {
            $konfigurasi->KredensialDiubahPada = now();
        }

        if ($isiBerubah) {
            // BR-P05.4: sistem tidak memakai isian yang belum terbukti tersambung.
            $konfigurasi->fill(['Status' => StatusIntegrasi::BelumDiuji, 'Aktif' => false, 'GagalBeruntun' => 0]);
        }

        $konfigurasi->save();

        // D-35: edisi Lisensi tanpa konsol, jadi tanpa log audit pengelola.
        if ($pelaku !== null) {
            $this->audit->Catat(
                $nilaiLama === null ? 'integrasi.buat' : 'integrasi.ubah',
                $konfigurasi,
                nilaiLama: $nilaiLama,
                nilaiBaru: [...self::AmbilRingkasan($konfigurasi), 'KredensialDiganti' => $kredensialBerubah],
                alasan: $data->alasan,
                idPelaku: $pelaku->Id,
            );
        }

        return $konfigurasi;
    }

    /** BR-P05.1: hanya 4 karakter terakhir; nilai pendek tidak ditampilkan sama sekali. */
    /** Kredensial yang petunjuknya disembunyikan penuh: kata sandi tidak pernah ditampilkan, bahkan 4 karakter terakhir. */
    public const KUNCI_TERSEMBUNYI_PENUH = ['KataSandi'];

    public static function BuatPetunjuk(string $nilai, string $kunci = ''): string
    {
        if (in_array($kunci, self::KUNCI_TERSEMBUNYI_PENUH, true)) {
            return '••••';
        }

        return mb_strlen($nilai) >= 12 ? '••••'.mb_substr($nilai, -4) : '••••';
    }

    /**
     * @param  array<string, string>  $kredensial
     * @return array<string, string>
     */
    public static function BuatSemuaPetunjuk(array $kredensial): array
    {
        $hasil = [];

        foreach ($kredensial as $kunci => $nilai) {
            $hasil[$kunci] = self::BuatPetunjuk($nilai, (string) $kunci);
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private static function AmbilRingkasan(KonfigurasiIntegrasi $konfigurasi): array
    {
        return [
            'Jenis' => $konfigurasi->Jenis->value,
            'Lingkungan' => $konfigurasi->Lingkungan->value,
            'Penyedia' => $konfigurasi->Penyedia->value,
            'Pengaturan' => $konfigurasi->Pengaturan,
            'Aktif' => $konfigurasi->Aktif,
            'RotasiSetiapHari' => $konfigurasi->RotasiSetiapHari,
        ];
    }
}
