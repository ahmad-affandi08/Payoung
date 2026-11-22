<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Data\DataAksesAnggota;
use App\Domain\Organisasi\Data\DataPenggunaBaru;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Layanan\PemeriksaAksesAnggota;
use App\Domain\Organisasi\Layanan\PenjagaPin;
use App\Domain\Organisasi\Layanan\PenugasanOutlet;
use App\Domain\Organisasi\Layanan\VerifierPinOffline;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * D-22 (F-02 langkah 3, alternatif undangan): admin tenant menambah pengguna langsung, seperti aplikasi kasir lain.
 * - Dengan email: akun dibuat dengan kata sandi awal yang diketik admin, wajib diganti saat pertama masuk. Email yang
 *   sudah punya akun Payoung (misal anggota usaha lain) tidak bisa ditambah langsung; pakai undangan agar pemiliknya setuju.
 * - Tanpa email: karyawan hanya kasir, wajib PIN; masuk aplikasi kasir dengan PIN, tidak bisa membuka back-office.
 * - BR-02.1: kursi pengguna dibatasi paket (`BatasPengguna`); aturan peran & outlet sama dengan undangan (anti-eskalasi).
 */
final class TambahPengguna
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaAksesAnggota $pemeriksaAkses,
        private readonly PastikanBatasPaket $batasPaket,
        private readonly PemakaianBatasOrganisasi $pemakaian,
        private readonly PenugasanOutlet $penugasan,
        private readonly PenjagaPin $penjagaPin,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(Pengguna $pelaku, DataPenggunaBaru $data, DataAksesAnggota $akses): Pengguna
    {
        $idTenant = $this->konteks->Wajib();
        $email = $data->email === null || trim($data->email) === '' ? null : Str::lower(trim($data->email));
        $pin = $data->pin === null || $data->pin === '' ? null : $data->pin;

        if ($email === null && $pin === null) {
            throw new PelanggaranAturanBisnis('PinWajib', 'Karyawan tanpa email wajib diberi PIN untuk masuk aplikasi kasir.', 'Pin');
        }

        if ($email !== null && ($data->kataSandi === null || $data->kataSandi === '')) {
            throw new PelanggaranAturanBisnis('KataSandiWajib', 'Isi kata sandi awal untuk pengguna yang memakai email.', 'KataSandi');
        }

        if ($pin !== null) {
            $this->penjagaPin->PastikanKuat($pin);
        }

        return DB::transaction(function () use ($pelaku, $data, $akses, $idTenant, $email, $pin): Pengguna {
            $hasilAkses = $this->pemeriksaAkses->Periksa($pelaku->Id, $akses);
            $this->batasPaket->Pastikan($idTenant, 'BatasPengguna', fn (): int => $this->pemakaian->HitungPengguna($idTenant, kecualiEmail: $email));

            if ($email !== null && Pengguna::query()->where('Email', $email)->lockForUpdate()->exists()) {
                throw new PelanggaranAturanBisnis('EmailSudahPunyaAkun', "{$email} sudah punya akun Payoung. Pakai \"Undang lewat email\" agar pemilik akun menyetujuinya.", 'Email');
            }

            $noHp = $data->noHp === null || trim($data->noHp) === '' ? null : trim($data->noHp);

            if ($noHp !== null && Pengguna::query()->where('NoHp', $noHp)->exists()) {
                throw new PelanggaranAturanBisnis('BR-00.1', 'Nomor WhatsApp ini sudah dipakai akun lain.', 'NoHp');
            }

            $pengguna = Pengguna::query()->create([
                'Nama' => trim($data->nama),
                'Email' => $email,
                'NoHp' => $noHp,
                // Tanpa email: kata sandi acak yang tidak pernah diberikan (akun tidak bisa masuk back-office).
                'KataSandi' => $email === null ? Str::random(64) : (string) $data->kataSandi,
                'WajibGantiKataSandi' => $email !== null,
                // Email diisi & dijamin admin tenant; tidak perlu verifikasi ulang agar bisa langsung masuk.
                'EmailDiverifikasiPada' => $email === null ? null : now(),
                // D-309: dijamin admin, bukan dibuktikan pemilik email. Bila Google ditautkan lewat kecocokan email, kata sandi diganti.
                'EmailBelumDibuktikan' => $email !== null,
            ]);

            $peran = $hasilAkses['Peran'];
            $semuaOutlet = $hasilAkses['SemuaOutlet'] || $peran->CekPemilik();
            $anggota = TenantPengguna::query()->create([
                'IdTenant' => $idTenant,
                'IdPengguna' => $pengguna->Id,
                'Pemilik' => $peran->CekPemilik(),
                'IdPeran' => $peran->Id,
                'SemuaOutlet' => $semuaOutlet,
                'Status' => StatusKeanggotaan::Aktif,
                'HashPin' => $pin === null ? null : Hash::make($pin),
                'VerifierPinOffline' => $pin === null ? null : VerifierPinOffline::Buat($pin),
            ]);
            $this->penugasan->Sinkronkan($pengguna->Id, $peran->Id, $semuaOutlet, $hasilAkses['IdOutlet']);

            if ($email !== null) {
                UndanganPengguna::query()->where('IdTenant', $idTenant)->where('Email', $email)
                    ->whereNull('DiterimaPada')->whereNull('DibatalkanPada')
                    ->update(['DibatalkanPada' => now()]);
            }

            $this->audit->Catat('pengguna.tambah', $anggota, nilaiBaru: [
                'Nama' => $pengguna->Nama,
                'Email' => $email,
                'HanyaKasir' => $email === null,
                'Peran' => $peran->Nama,
                'SemuaOutlet' => $semuaOutlet,
                'IdOutlet' => $hasilAkses['IdOutlet'],
                'PinDiatur' => $pin !== null,
            ]);

            return $pengguna;
        });
    }
}
