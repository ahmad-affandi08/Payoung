<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pelaksana teknis penghapusan data satu tenant (UU PDP, P-07), mengikuti `RegistriAnonimisasi`: kolom data pribadi
 * dikosongkan atau diganti, baris kredensial/token/pesan dihapus, pengguna yang hanya milik tenant ini dianonimkan, dan
 * jejak audit dibersihkan dari alamat IP serta nilai data pribadi. Dokumen transaksi dan pembukuan tidak disentuh.
 * Memakai kueri tabel langsung (bukan model) karena dokumen tercatat memang tidak boleh diubah lewat model; yang
 * diubah hanya kolom identitas yang terdaftar. Idempoten: dijalankan ulang menghasilkan keadaan yang sama.
 * Pemanggil membungkusnya dalam satu transaksi.
 */
final class PenganonimDataTenant
{
    /**
     * @return array{Dianonimkan: array<string, int>, Dihapus: array<string, int>, PenggunaDianonimkan: int, AnggotaDilepas: int, AuditDibersihkan: int}
     */
    public function Jalankan(int $idTenant): array
    {
        $ringkasan = ['Dianonimkan' => [], 'Dihapus' => [], 'PenggunaDianonimkan' => 0, 'AnggotaDilepas' => 0, 'AuditDibersihkan' => 0];
        $idPengguna = array_values(array_map('intval', DB::table('TenantPengguna')->where('IdTenant', $idTenant)->pluck('IdPengguna')->all()));

        foreach (RegistriAnonimisasi::ANONIMKAN as $tabel => $kolom) {
            $jumlah = $this->Anonimkan($tabel, RegistriAnonimisasi::KUNCI_KHUSUS[$tabel] ?? 'IdTenant', $idTenant, $kolom);

            if ($jumlah > 0) {
                $ringkasan['Dianonimkan'][$tabel] = $jumlah;
            }
        }

        $ringkasan['AuditDibersihkan'] = $this->BersihkanAudit($idTenant);
        [$ringkasan['PenggunaDianonimkan'], $ringkasan['AnggotaDilepas']] = $this->AnonimkanPengguna($idTenant, $idPengguna);

        foreach (RegistriAnonimisasi::HAPUS_BARIS as $tabel) {
            $jumlah = DB::table($tabel)->where('IdTenant', $idTenant)->delete();

            if ($jumlah > 0) {
                $ringkasan['Dihapus'][$tabel] = $jumlah;
            }
        }

        return $ringkasan;
    }

    /**
     * Satu tabel: kolom bernilai tetap diubah dalam satu pernyataan; kolom `unik:` (nilai tetap + Id baris) dan `sekarang`
     * (waktu bila masih kosong) masing-masing satu pernyataan karena nilainya bergantung pada barisnya.
     *
     * @param  array<string, string>  $kolom
     * @return int jumlah baris yang disentuh
     */
    private function Anonimkan(string $tabel, string $kunci, int $idTenant, array $kolom): int
    {
        $tetap = [];
        $jumlah = 0;

        foreach ($kolom as $nama => $strategi) {
            if (str_starts_with($strategi, 'unik:')) {
                // Pengenal tabel/kolom berasal dari konstanta registri (dijaga test terhadap skema), bukan masukan pengguna.
                $jumlah = max($jumlah, DB::affectingStatement(
                    "UPDATE `{$tabel}` SET `{$nama}` = CONCAT(?, `Id`) WHERE `{$kunci}` = ?",
                    [substr($strategi, 5), $idTenant],
                ));
            } elseif ($strategi === 'sekarang') {
                $jumlah = max($jumlah, DB::table($tabel)->where($kunci, $idTenant)->whereNull($nama)->update([$nama => now()]));
            } else {
                $tetap[$nama] = $this->NilaiTetap($strategi);
            }
        }

        if ($tetap !== []) {
            $jumlah = max($jumlah, DB::table($tabel)->where($kunci, $idTenant)->update($tetap));
        }

        return $jumlah;
    }

    private function NilaiTetap(string $strategi): mixed
    {
        return match (true) {
            $strategi === 'null' => null,
            $strategi === 'nol' => 0,
            $strategi === 'enkripsi' => Crypt::encryptString('Data dihapus'),
            str_starts_with($strategi, 'teks:') => substr($strategi, 5),
            default => throw new InvalidArgumentException("Strategi anonimisasi tidak dikenal: {$strategi}"),
        };
    }

    /** Nilai lama/baru jejak audit objek yang memuat data pribadi; IP dan agen pengguna dibersihkan lewat registri. */
    private function BersihkanAudit(int $idTenant): int
    {
        return DB::table('LogAudit')->where('IdTenant', $idTenant)
            ->whereIn('JenisObjek', RegistriAnonimisasi::JENIS_OBJEK_AUDIT_PRIBADI)
            ->where(function ($saring): void {
                $saring->whereNotNull('NilaiLama')->orWhereNotNull('NilaiBaru');
            })->update(['NilaiLama' => null, 'NilaiBaru' => null]);
    }

    /**
     * Pengguna yang hanya menjadi anggota tenant ini dianonimkan dan semua akses (sesi, token, perangkat push, tautan
     * atur ulang kata sandi) dicabut. Pengguna yang juga anggota tenant lain hanya dilepas dari tenant ini.
     *
     * @param  list<int>  $idPengguna
     * @return array{0: int, 1: int} [pengguna dianonimkan, anggota dilepas]
     */
    private function AnonimkanPengguna(int $idTenant, array $idPengguna): array
    {
        if ($idPengguna === []) {
            return [0, 0];
        }

        $diTenantLain = array_map('intval', DB::table('TenantPengguna')->whereIn('IdPengguna', $idPengguna)
            ->where('IdTenant', '<>', $idTenant)->distinct()->pluck('IdPengguna')->all());
        $anonim = array_values(array_diff($idPengguna, $diTenantLain));
        $dianonimkan = 0;

        foreach (array_chunk($anonim, 500) as $kelompok) {
            $surel = DB::table('Pengguna')->whereIn('Id', $kelompok)->whereNotNull('Email')->pluck('Email')->all();

            DB::table('PerangkatPengguna')->whereIn('IdPengguna', $kelompok)->delete();
            DB::table('TokenAksesPengguna')->whereIn('IdPengguna', $kelompok)->delete();
            DB::table('sessions')->whereIn('user_id', $kelompok)->delete();

            if ($surel !== []) {
                DB::table('password_reset_tokens')->whereIn('email', $surel)->delete();
            }

            // Email unik per pengguna (kolom unik): alamat `.invalid` tidak pernah bisa menerima surat.
            DB::affectingStatement(
                "UPDATE `Pengguna` SET `Email` = CONCAT('terhapus-', `Id`, '@payoung.invalid') WHERE `Id` IN (".implode(',', array_fill(0, count($kelompok), '?')).')',
                $kelompok,
            );
            $dianonimkan += DB::table('Pengguna')->whereIn('Id', $kelompok)->update([
                'Nama' => 'Pengguna Terhapus',
                'GoogleSub' => null,
                'GoogleDitautkanPada' => null,
                'NoHp' => null,
                'EmailDiverifikasiPada' => null,
                'KataSandi' => Hash::make(Str::random(64)),
                'WajibGantiKataSandi' => 0,
                'KataSandiOtomatis' => 1,
                'Rahasia2fa' => null,
                'KodePemulihan2fa' => null,
                'DuaFaktorAktifPada' => null,
                'TokenIngat' => null,
            ]);
        }

        return [$dianonimkan, count($idPengguna) - count($anonim)];
    }
}
