<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Data\DataAkunBaru;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Kueri\UndanganBerlaku;
use App\Domain\Organisasi\Layanan\PenugasanOutlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use Illuminate\Support\Facades\DB;

/**
 * F-02 langkah 3 (sisi penerima): undangan diterima dengan membuat akun baru atau menautkan akun yang sudah ada
 * (BR-00.1: satu pengguna boleh menjadi anggota beberapa tenant).
 * - Akun yang sudah ada wajib masuk dulu dengan email undangan (bukti kepemilikan akun).
 * - Akun baru: email diambil dari undangan dan dianggap terverifikasi karena token dikirim ke email itu.
 * - BR-02.1: batas pengguna diperiksa lagi (paket bisa turun setelah undangan dikirim).
 */
final class TerimaUndanganAnggota
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly UndanganBerlaku $undanganBerlaku,
        private readonly PastikanBatasPaket $batasPaket,
        private readonly PemakaianBatasOrganisasi $pemakaian,
        private readonly PenugasanOutlet $penugasan,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return array{Pengguna: Pengguna, IdTenant: int}
     */
    public function Jalankan(string $token, ?Pengguna $penggunaMasuk, ?DataAkunBaru $akunBaru): array
    {
        return DB::transaction(function () use ($token, $penggunaMasuk, $akunBaru): array {
            $awal = $this->CariUndangan($token, kunci: false);
            $idTenant = $awal->IdTenant;
            $this->konteks->Atur($idTenant);
            // Urutan kunci sama dengan UndangPengguna: langganan dulu, lalu undangan & keanggotaan.
            $this->batasPaket->Pastikan($idTenant, 'BatasPengguna', fn (): int => $this->pemakaian->HitungPengguna($idTenant, kecualiIdUndangan: $awal->Id));
            $undangan = $this->CariUndangan($token, kunci: true);

            $akunLama = Pengguna::query()->where('Email', $undangan->Email)->first();
            $pengguna = $this->TentukanPengguna($undangan->Email, $akunLama, $penggunaMasuk, $akunBaru);

            $anggota = TenantPengguna::query()->where('IdTenant', $idTenant)->where('IdPengguna', $pengguna->Id)->lockForUpdate()->first();

            if ($anggota?->Status === StatusKeanggotaan::Aktif) {
                throw new PelanggaranAturanBisnis('SudahAnggota', 'Anda sudah menjadi anggota usaha ini. Pilih usaha tersebut setelah masuk.');
            }

            $peran = Peran::query()->findOrFail($undangan->IdPeran);
            $isian = [
                'Pemilik' => $peran->CekPemilik(),
                'IdPeran' => $peran->Id,
                'SemuaOutlet' => $undangan->SemuaOutlet || $peran->CekPemilik(),
                'Status' => StatusKeanggotaan::Aktif,
                'DinonaktifkanPada' => null,
            ];
            $anggota === null
                ? TenantPengguna::query()->create(['IdTenant' => $idTenant, 'IdPengguna' => $pengguna->Id, ...$isian])
                : $anggota->update($isian);
            $this->penugasan->Sinkronkan($pengguna->Id, $peran->Id, $isian['SemuaOutlet'], $undangan->DaftarIdOutlet ?? []);

            $undangan->update(['DiterimaPada' => now(), 'IdPenggunaPenerima' => $pengguna->Id]);

            $this->audit->Catat('pengguna.terima-undangan', $undangan, nilaiBaru: [
                'Email' => $undangan->Email,
                'Peran' => $peran->Nama,
                'AkunBaru' => $akunLama === null,
            ], idTenant: $idTenant, idPengguna: $pengguna->Id);

            return ['Pengguna' => $pengguna, 'IdTenant' => $idTenant];
        });
    }

    private function CariUndangan(string $token, bool $kunci): UndanganPengguna
    {
        return $this->undanganBerlaku->Cari($token, $kunci)
            ?? throw new PelanggaranAturanBisnis('UndanganTidakBerlaku', 'Undangan ini tidak berlaku lagi (kedaluwarsa, dibatalkan, atau sudah dipakai). Minta pengundang mengirim undangan baru.');
    }

    private function TentukanPengguna(string $email, ?Pengguna $akunLama, ?Pengguna $penggunaMasuk, ?DataAkunBaru $akunBaru): Pengguna
    {
        if ($penggunaMasuk !== null) {
            if (mb_strtolower((string) $penggunaMasuk->Email) !== $email) {
                throw new PelanggaranAturanBisnis('EmailBerbeda', "Undangan ini untuk {$email}. Keluar, lalu masuk dengan email tersebut.");
            }

            if ($penggunaMasuk->EmailDiverifikasiPada === null || $penggunaMasuk->EmailBelumDibuktikan) {
                $penggunaMasuk->forceFill(['EmailDiverifikasiPada' => $penggunaMasuk->EmailDiverifikasiPada ?? now(), 'EmailBelumDibuktikan' => false])->save();
            }

            return $penggunaMasuk;
        }

        if ($akunLama !== null) {
            throw new PelanggaranAturanBisnis('PerluMasuk', "Email {$email} sudah punya akun. Masuk dulu, lalu buka lagi tautan undangan.");
        }

        if ($akunBaru === null) {
            throw new PelanggaranAturanBisnis('DataAkunKosong', 'Lengkapi nama dan kata sandi untuk membuat akun.', 'Nama');
        }

        if ($akunBaru->noHp !== null && Pengguna::query()->where('NoHp', $akunBaru->noHp)->exists()) {
            throw new PelanggaranAturanBisnis('BR-00.1', 'Nomor WhatsApp ini sudah dipakai akun lain.', 'NoHp');
        }

        return Pengguna::query()->create([
            'Nama' => $akunBaru->nama,
            'Email' => $email,
            'NoHp' => $akunBaru->noHp,
            'KataSandi' => $akunBaru->kataSandi,
            'EmailDiverifikasiPada' => now(),
        ]);
    }
}
