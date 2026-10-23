<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Lisensi\Kontrak\PengaturIntegrasiServer;
use App\Domain\Pengelola\Integrasi\Aksi\SimpanKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Aksi\UbahStatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Aksi\UjiKoneksiIntegrasi;
use App\Domain\Pengelola\Integrasi\Data\DataKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Kueri\DaftarIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Pelaksana {@see PengaturIntegrasiServer} (D-35): Owner edisi Lisensi memakai Aksi P-05 yang sama dengan konsol dan
 * `lisensi:atur-integrasi` (kredensial terenkripsi, uji wajib berhasil sebelum aktif). Pelaku konsol `null` hanya
 * diterima Aksi di edisi Lisensi; jejak perubahannya dicatat pemanggil di log audit tenant.
 */
final class PengaturIntegrasiServerLisensi implements PengaturIntegrasiServer
{
    private const ALASAN = 'Diatur Owner dari back-office edisi Lisensi';

    public function __construct(
        private readonly DaftarIntegrasi $daftar,
        private readonly SimpanKonfigurasiIntegrasi $simpan,
        private readonly UjiKoneksiIntegrasi $uji,
        private readonly UbahStatusIntegrasi $ubahStatus,
    ) {}

    public function AmbilDaftar(): array
    {
        $this->WajibLisensi();
        $lingkungan = LingkunganIntegrasi::AmbilSaatIni()->value;

        return array_values(array_filter(
            $this->daftar->Ambil(),
            fn (array $baris): bool => in_array($baris['Jenis'], self::JENIS, true) && $baris['Lingkungan'] === $lingkungan,
        ));
    }

    public function Simpan(string $jenis, string $penyedia, array $pengaturan, array $kredensial): array
    {
        $jenisIntegrasi = $this->AmbilJenis($jenis);
        $penyediaIntegrasi = PenyediaIntegrasi::tryFrom($penyedia);

        if ($penyediaIntegrasi === null || $penyediaIntegrasi->AmbilJenis() !== $jenisIntegrasi) {
            throw new PelanggaranAturanBisnis('PenyediaTidakCocok', 'Pilih penyedia dari daftar.', 'Penyedia');
        }

        $aturan = [];
        $nama = [];

        foreach ($penyediaIntegrasi->AmbilBidangPengaturan() as $bidang) {
            $nama["Pengaturan.{$bidang['Kunci']}"] = $bidang['Label'];
            $aturan["Pengaturan.{$bidang['Kunci']}"] = ! $bidang['Wajib'] ? ['nullable', 'string', 'max:255'] : match ($bidang['Jenis']) {
                'Angka' => ['required', 'integer', 'min:1', 'max:65535'],
                'Email' => ['required', 'email', 'max:150'],
                'Url' => ['required', 'url:https', 'max:255'],
                'Pilihan' => ['required', 'string', Rule::in($bidang['Opsi'] ?? [])],
                default => ['required', 'string', 'max:255'],
            };
        }

        foreach ($penyediaIntegrasi->AmbilBidangKredensial() as $bidang) {
            $nama["Kredensial.{$bidang['Kunci']}"] = $bidang['Label'];
            $aturan["Kredensial.{$bidang['Kunci']}"] = ['nullable', 'string', 'max:'.($bidang['Maks'] ?? 2000)];
        }

        Validator::make(['Pengaturan' => $pengaturan, 'Kredensial' => $kredensial], $aturan, [], $nama)->validate();

        $isiPengaturan = [];

        foreach ($penyediaIntegrasi->AmbilBidangPengaturan() as $bidang) {
            $nilai = trim((string) ($pengaturan[$bidang['Kunci']] ?? ''));
            $isiPengaturan[$bidang['Kunci']] = $bidang['Jenis'] === 'Angka' ? (int) $nilai : $nilai;
        }

        $isiKredensial = [];

        foreach ($penyediaIntegrasi->AmbilBidangKredensial() as $bidang) {
            $nilai = trim((string) ($kredensial[$bidang['Kunci']] ?? ''));

            if ($nilai !== '') {
                $isiKredensial[$bidang['Kunci']] = $nilai;
            }
        }

        $lama = $this->AmbilKonfigurasi($jenisIntegrasi);
        $this->simpan->Jalankan(null, new DataKonfigurasiIntegrasi(
            jenis: $jenisIntegrasi,
            lingkungan: LingkunganIntegrasi::AmbilSaatIni(),
            pengaturan: $isiPengaturan,
            kredensial: $isiKredensial,
            // Pengingat rotasi kredensial 90 hari, sama dengan bawaan konsol & `lisensi:atur-integrasi`.
            rotasiSetiapHari: $lama->RotasiSetiapHari ?? 90,
            alasan: self::ALASAN,
            penyedia: $penyediaIntegrasi,
        ));

        // BR-P05.6: log audit hanya memuat nama kolom kredensial yang diganti, bukan nilainya.
        return ['Jenis' => $jenisIntegrasi->value, 'Penyedia' => $penyediaIntegrasi->value, 'Pengaturan' => $isiPengaturan, 'KredensialDiganti' => array_keys($isiKredensial)];
    }

    public function UjiDanAktifkan(string $jenis): array
    {
        $konfigurasi = $this->AmbilKonfigurasi($this->AmbilJenis($jenis))
            ?? throw new PelanggaranAturanBisnis('BelumDiatur', 'Simpan pengaturannya dulu sebelum menguji.');
        $hasil = $this->uji->Jalankan($konfigurasi)['Hasil'];

        if ($hasil->berhasil && ! $konfigurasi->refresh()->Aktif) {
            $this->ubahStatus->Jalankan(null, $konfigurasi, true, self::ALASAN);
        }

        return ['Berhasil' => $hasil->berhasil, 'Pesan' => $hasil->pesan];
    }

    public function Nonaktifkan(string $jenis): void
    {
        $konfigurasi = $this->AmbilKonfigurasi($this->AmbilJenis($jenis))
            ?? throw new PelanggaranAturanBisnis('BelumDiatur', 'Integrasi ini belum diatur.');
        $this->ubahStatus->Jalankan(null, $konfigurasi, false, self::ALASAN);
    }

    private function AmbilJenis(string $jenis): JenisIntegrasi
    {
        $this->WajibLisensi();

        if (! in_array($jenis, self::JENIS, true)) {
            throw new PelanggaranAturanBisnis('JenisTidakDikenal', 'Jenis integrasi ini tidak bisa diatur dari back-office.');
        }

        return JenisIntegrasi::from($jenis);
    }

    private function AmbilKonfigurasi(JenisIntegrasi $jenis): ?KonfigurasiIntegrasi
    {
        return KonfigurasiIntegrasi::query()
            ->where('Jenis', $jenis->value)
            ->where('Lingkungan', LingkunganIntegrasi::AmbilSaatIni()->value)
            ->first();
    }

    private function WajibLisensi(): void
    {
        if (! EdisiAplikasi::CekLisensi()) {
            throw new PelanggaranAturanBisnis('D-35', 'Di edisi SaaS integrasi server diatur Platform Pengelola lewat konsol.');
        }
    }
}
