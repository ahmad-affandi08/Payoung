<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Layanan\PenandaVerifikasiEmail;
use App\Domain\Organisasi\Layanan\PencatatAuditAkun;
use App\Domain\Organisasi\Layanan\SandiEmailBaru;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * Menerapkan ganti email dari tautan bertanda tangan (BR-00.5). Membuka tautan membuktikan alamat baru bisa menerima
 * pesan, jadi email akun langsung terverifikasi. Tanda tangan & masa berlaku diperiksa perantara `signed`.
 */
final class TerapkanGantiEmail
{
    public function __construct(
        private readonly PenandaVerifikasiEmail $penanda,
        private readonly SandiEmailBaru $sandi,
        private readonly PencatatAuditAkun $auditAkun,
    ) {}

    public function Jalankan(Pengguna $pengguna, string $hash, string $sandiEmail): void
    {
        // Hash email lama: tautan yang sudah dipakai (email sudah berubah) atau milik email lain tidak berlaku lagi.
        if (! $this->penanda->CekCocok($pengguna, $hash)) {
            throw new PelanggaranAturanBisnis('TautanTidakValid', 'Tautan ganti email tidak berlaku lagi. Minta tautan baru dari halaman Keamanan akun.');
        }

        $emailBaru = $this->sandi->Urai($sandiEmail);

        if ($emailBaru === null || filter_var($emailBaru, FILTER_VALIDATE_EMAIL) === false) {
            throw new PelanggaranAturanBisnis('TautanTidakValid', 'Tautan ganti email tidak valid. Minta tautan baru dari halaman Keamanan akun.');
        }

        $emailBaru = mb_strtolower($emailBaru);

        DB::transaction(function () use ($pengguna, $emailBaru): void {
            $sudahDipakai = Pengguna::query()->where('Email', $emailBaru)->where('Id', '!=', $pengguna->Id)->lockForUpdate()->exists();

            if ($sudahDipakai) {
                throw new PelanggaranAturanBisnis('EmailTidakDapatDipakai', 'Email ini tidak dapat dipakai. Minta tautan untuk alamat lain dari halaman Keamanan akun.');
            }

            $pengguna->forceFill(['Email' => $emailBaru, 'EmailDiverifikasiPada' => now(), 'EmailBelumDibuktikan' => false])->save();
            $this->auditAkun->Catat('akun.email-diganti', $pengguna);
        });
    }
}
