<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Operasional\Data\DataInsiden;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\KomponenLayanan;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Kueri\StatusPlatformPublik;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Domain\Tenant\Model\PembaruanInsiden;
use Illuminate\Support\Facades\DB;

/**
 * P-11 PGL-21 manajemen insiden (Teknis & Super Admin, `operasional.kelola`):
 * - **Buka**: judul, tingkat, dampak, komponen terdampak, perkiraan % tenant aktif terdampak, kronologi awal. Insiden
 *   yang berdampak > `AMBANG_WAJIB_UMUM_PERSEN` % tenant aktif **selalu langsung diumumkan** di halaman status (BR-P11.2).
 * - **Perbarui**: pembaruan kronologi (append-only) dengan status baru; `Selesai` final dan mengisi `SelesaiPada`.
 * - **Umumkan**: membuka insiden internal ke halaman status publik.
 * - **Post-mortem**: hanya setelah `Selesai`; tampil publik bila diterbitkan (dan insidennya diumumkan).
 * Audit `insiden.buka|perbarui|umumkan|post-mortem`. Halaman status publik dibaca dari tembolok yang dilupakan di sini.
 */
final class KelolaInsiden
{
    public const AMBANG_WAJIB_UMUM_PERSEN = 10;

    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    /**
     * @throws PelanggaranAturanBisnis
     */
    public function Buka(PenggunaPengelola $pelaku, DataInsiden $data): InsidenPlatform
    {
        $judul = trim($data->judul);
        $dampak = trim($data->dampak);
        $kronologi = trim($data->kronologiAwal);

        if (mb_strlen($judul) < 5 || mb_strlen($judul) > 120) {
            throw new PelanggaranAturanBisnis('JudulTidakValid', 'Judul 5 sampai 120 huruf.', 'Judul');
        }

        if (mb_strlen($dampak) < 10 || mb_strlen($dampak) > 1000) {
            throw new PelanggaranAturanBisnis('DampakTidakValid', 'Dampak 10 sampai 1.000 huruf, ditulis dari sudut pandang pengguna.', 'Dampak');
        }

        if (mb_strlen($kronologi) < 10 || mb_strlen($kronologi) > 1000) {
            throw new PelanggaranAturanBisnis('KronologiTidakValid', 'Kronologi awal 10 sampai 1.000 huruf.', 'KronologiAwal');
        }

        $komponen = array_values(array_unique($data->komponen));

        if ($komponen === [] || array_filter($komponen, fn (string $k): bool => KomponenLayanan::tryFrom($k) === null) !== []) {
            throw new PelanggaranAturanBisnis('KomponenTidakValid', 'Pilih sedikitnya satu komponen yang terdampak.', 'Komponen');
        }

        if ($data->persenTenantTerdampak !== null && ($data->persenTenantTerdampak < 0 || $data->persenTenantTerdampak > 100)) {
            throw new PelanggaranAturanBisnis('PersenTidakValid', 'Persentase tenant terdampak 0 sampai 100.', 'PersenTenantTerdampak');
        }

        if ($data->mulaiPada !== null && $data->mulaiPada->greaterThan(now()->addMinutes(5))) {
            throw new PelanggaranAturanBisnis('MulaiDiMasaDepan', 'Waktu mulai insiden tidak boleh di masa depan.', 'MulaiPada');
        }

        $wajibUmum = ($data->persenTenantTerdampak ?? 0) > self::AMBANG_WAJIB_UMUM_PERSEN;

        return DB::transaction(function () use ($pelaku, $data, $judul, $dampak, $kronologi, $komponen, $wajibUmum): InsidenPlatform {
            $sekarang = now();
            $insiden = InsidenPlatform::query()->create([
                'Judul' => $judul,
                'Tingkat' => $data->tingkat,
                'Status' => StatusInsiden::Diselidiki,
                'Dampak' => $dampak,
                'Komponen' => $komponen,
                'PersenTenantTerdampak' => $data->persenTenantTerdampak,
                'MulaiPada' => $data->mulaiPada ?? $sekarang,
                'DiumumkanPada' => $data->diumumkan || $wajibUmum ? $sekarang : null,
                'DibuatOleh' => $pelaku->Id,
            ]);
            $this->TulisPembaruan($insiden, StatusInsiden::Diselidiki, $kronologi, $pelaku);
            $this->audit->Catat('insiden.buka', $insiden, nilaiBaru: [
                'Judul' => $judul,
                'Tingkat' => $data->tingkat->value,
                'Komponen' => $komponen,
                'PersenTenantTerdampak' => $data->persenTenantTerdampak,
                'Diumumkan' => $insiden->DiumumkanPada !== null,
                'DiumumkanOtomatis' => $wajibUmum,
            ], idPelaku: $pelaku->Id);
            DB::afterCommit(fn () => StatusPlatformPublik::LupakanTembolok());

            return $insiden;
        });
    }

    /**
     * @throws PelanggaranAturanBisnis
     */
    public function Perbarui(PenggunaPengelola $pelaku, InsidenPlatform $insiden, StatusInsiden $status, string $isi): InsidenPlatform
    {
        $isi = trim($isi);

        if (mb_strlen($isi) < 5 || mb_strlen($isi) > 1000) {
            throw new PelanggaranAturanBisnis('IsiTidakValid', 'Isi pembaruan 5 sampai 1.000 huruf.', 'Isi');
        }

        return DB::transaction(function () use ($pelaku, $insiden, $status, $isi): InsidenPlatform {
            $terkunci = InsidenPlatform::query()->whereKey($insiden->Id)->lockForUpdate()->firstOrFail();
            $asal = $terkunci->Status;

            if ($asal === StatusInsiden::Selesai) {
                throw new PelanggaranAturanBisnis('InsidenSudahSelesai', 'Insiden ini sudah selesai dan tidak bisa diperbarui.', 'Umum', 409);
            }

            if ($status !== $asal && ! $asal->BisaBerubahKe($status)) {
                throw new PelanggaranAturanBisnis('StatusTidakBisaBerubah', "Status {$asal->AmbilLabel()} tidak bisa langsung berubah ke {$status->AmbilLabel()}.", 'Status');
            }

            $terkunci->Status = $status;

            if ($status === StatusInsiden::Selesai) {
                $terkunci->SelesaiPada = now();
            }

            $terkunci->save();
            $this->TulisPembaruan($terkunci, $status, $isi, $pelaku);
            $this->audit->Catat('insiden.perbarui', $terkunci, ['Status' => $asal->value], ['Status' => $status->value], idPelaku: $pelaku->Id);
            DB::afterCommit(fn () => StatusPlatformPublik::LupakanTembolok());

            return $terkunci;
        });
    }

    /**
     * @throws PelanggaranAturanBisnis
     */
    public function Umumkan(PenggunaPengelola $pelaku, InsidenPlatform $insiden): InsidenPlatform
    {
        return DB::transaction(function () use ($pelaku, $insiden): InsidenPlatform {
            $terkunci = InsidenPlatform::query()->whereKey($insiden->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->DiumumkanPada !== null) {
                throw new PelanggaranAturanBisnis('SudahDiumumkan', 'Insiden ini sudah tampil di halaman status.', 'Umum', 409);
            }

            $terkunci->forceFill(['DiumumkanPada' => now()])->save();
            $this->audit->Catat('insiden.umumkan', $terkunci, nilaiBaru: ['Judul' => $terkunci->Judul], idPelaku: $pelaku->Id);
            DB::afterCommit(fn () => StatusPlatformPublik::LupakanTembolok());

            return $terkunci;
        });
    }

    /**
     * @throws PelanggaranAturanBisnis
     */
    public function TulisPostMortem(PenggunaPengelola $pelaku, InsidenPlatform $insiden, string $teks, bool $terbitkan): InsidenPlatform
    {
        $teks = trim($teks);

        if (mb_strlen($teks) < 50 || mb_strlen($teks) > 5000) {
            throw new PelanggaranAturanBisnis('PostMortemTidakValid', 'Post-mortem 50 sampai 5.000 huruf: apa yang terjadi, penyebab, dan langkah pencegahan.', 'PostMortem');
        }

        return DB::transaction(function () use ($pelaku, $insiden, $teks, $terbitkan): InsidenPlatform {
            $terkunci = InsidenPlatform::query()->whereKey($insiden->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status !== StatusInsiden::Selesai) {
                throw new PelanggaranAturanBisnis('InsidenBelumSelesai', 'Post-mortem ditulis setelah insiden selesai.', 'Umum', 409);
            }

            if ($terbitkan && $terkunci->DiumumkanPada === null) {
                throw new PelanggaranAturanBisnis('InsidenBelumDiumumkan', 'Umumkan insiden ini dulu sebelum menerbitkan post-mortem.', 'Umum', 409);
            }

            if ($terkunci->PostMortemDiterbitkanPada !== null) {
                throw new PelanggaranAturanBisnis('PostMortemSudahTerbit', 'Post-mortem yang sudah terbit tidak diubah lagi.', 'Umum', 409);
            }

            $terkunci->forceFill([
                'PostMortem' => $teks,
                'PostMortemDiterbitkanPada' => $terbitkan ? now() : null,
            ])->save();
            $this->audit->Catat('insiden.post-mortem', $terkunci, nilaiBaru: ['Diterbitkan' => $terbitkan], idPelaku: $pelaku->Id);
            DB::afterCommit(fn () => StatusPlatformPublik::LupakanTembolok());

            return $terkunci;
        });
    }

    private function TulisPembaruan(InsidenPlatform $insiden, StatusInsiden $status, string $isi, PenggunaPengelola $pelaku): void
    {
        PembaruanInsiden::query()->create(['IdInsiden' => $insiden->Id, 'Status' => $status, 'Isi' => $isi, 'DibuatOleh' => $pelaku->Id]);
    }
}
