<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Data\DataAksesAnggota;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Layanan\PemeriksaAksesAnggota;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Organisasi\Surel\UndanganAnggota;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * F-02 langkah 3: mengundang pengguna lewat email dengan peran & outlet yang ditugaskan.
 * - Berlaku 72 jam (konfigurasi `organisasi.JamBerlakuUndangan`), sekali pakai; token hanya disimpan sebagai hash.
 * - Undangan lama yang belum dipakai untuk email yang sama di tenant ini otomatis dibatalkan.
 * - BR-02.1: kursi pengguna (anggota aktif + undangan berlaku) dibatasi paket (`BatasPengguna`).
 * - Email yang sudah punya akun (termasuk anggota tenant lain) boleh diundang; akun ditautkan saat diterima (BR-00.1).
 */
final class UndangPengguna
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaAksesAnggota $pemeriksaAkses,
        private readonly PastikanBatasPaket $batasPaket,
        private readonly PemakaianBatasOrganisasi $pemakaian,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(Pengguna $pengundang, string $email, DataAksesAnggota $akses, string $namaTenant): UndanganPengguna
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);
        $idTenant = $this->konteks->Wajib();

        $undangan = DB::transaction(function () use ($pengundang, $email, $akses, $token, $idTenant): UndanganPengguna {
            $hasilAkses = $this->pemeriksaAkses->Periksa($pengundang->Id, $akses);
            $this->PastikanBelumAnggota($idTenant, $email);
            // Undangan lama untuk email ini dibatalkan di bawah, jadi kursinya tidak dihitung dua kali.
            $this->batasPaket->Pastikan($idTenant, 'BatasPengguna', fn (): int => $this->pemakaian->HitungPengguna($idTenant, kecualiEmail: $email));

            $undanganLama = UndanganPengguna::query()
                ->where('IdTenant', $idTenant)
                ->where('Email', $email)
                ->whereNull('DiterimaPada')
                ->whereNull('DibatalkanPada')
                ->lockForUpdate()
                ->get();

            foreach ($undanganLama as $lama) {
                $lama->update(['DibatalkanPada' => now()]);
                $this->audit->Catat('pengguna.undangan-batal', $lama, nilaiBaru: ['Email' => $email, 'Alasan' => 'Diganti undangan baru']);
            }

            $undangan = UndanganPengguna::query()->create([
                'IdTenant' => $idTenant,
                'Email' => $email,
                'HashToken' => UndanganPengguna::BuatHashToken($token),
                'IdPeran' => $hasilAkses['Peran']->Id,
                'SemuaOutlet' => $hasilAkses['SemuaOutlet'],
                'DaftarIdOutlet' => $hasilAkses['SemuaOutlet'] ? null : $hasilAkses['IdOutlet'],
                'IdPenggunaPengundang' => $pengundang->Id,
                'BerlakuSampai' => now()->addHours((int) config('organisasi.JamBerlakuUndangan')),
            ]);

            $this->audit->Catat('pengguna.undang', $undangan, nilaiBaru: [
                'Email' => $email,
                'Peran' => $hasilAkses['Peran']->Nama,
                'SemuaOutlet' => $hasilAkses['SemuaOutlet'],
                'IdOutlet' => $hasilAkses['IdOutlet'],
                'BerlakuSampai' => $undangan->BerlakuSampai->toIso8601String(),
            ]);

            return $undangan;
        });

        // Dikirim setelah commit dan SINKRON (bukan `queue`), agar token asli tidak tersimpan di payload tabel jobs atau
        // antrean Redis. Bila pengiriman gagal, undangan tetap ada; mengundang ulang membatalkan yang lama dan membuat token baru.
        try {
            Mail::to($email)->send(new UndanganAnggota($undangan, $token, $pengundang->Nama, $namaTenant));
        } catch (Throwable $galat) {
            Log::error('Email undangan anggota gagal dikirim.', ['IdTenant' => $idTenant, 'IdUndangan' => $undangan->Id, 'Galat' => $galat::class]);

            throw new PelanggaranAturanBisnis('SurelUndanganGagal', 'Undangan dibuat tetapi email gagal dikirim. Coba undang ulang sebentar lagi.', 'Email', 502);
        }

        return $undangan;
    }

    private function PastikanBelumAnggota(int $idTenant, string $email): void
    {
        $idPengguna = Pengguna::query()->where('Email', $email)->value('Id');

        if ($idPengguna === null) {
            return;
        }

        $anggota = TenantPengguna::query()->where('IdTenant', $idTenant)->where('IdPengguna', $idPengguna)->first();

        if ($anggota?->Status === StatusKeanggotaan::Aktif) {
            throw new PelanggaranAturanBisnis('SudahAnggota', "{$email} sudah menjadi anggota usaha ini.", 'Email');
        }

        if ($anggota?->Status === StatusKeanggotaan::Nonaktif) {
            throw new PelanggaranAturanBisnis('AnggotaNonaktif', "{$email} pernah menjadi anggota dan sedang nonaktif. Aktifkan kembali dari daftar pengguna.", 'Email');
        }
    }
}
