<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\EvaluasiTierPelanggan;
use App\Domain\Pelanggan\Aksi\HanguskanGiftCardKedaluwarsa;
use App\Domain\Pelanggan\Aksi\HanguskanPoinKedaluwarsa;
use App\Domain\Pelanggan\Aksi\HanguskanSesiKedaluwarsa;
use Illuminate\Console\Command;

/**
 * F-16b: tiap malam menghanguskan poin kedaluwarsa (FIFO) lalu mengevaluasi tier pelanggan; F-16d bagian 2: paket
 * sesi yang lewat masa berlaku dihanguskan; CRM-06: gift card yang lewat masa berlaku dihanguskan. Per tenant dengan
 * `KonteksTenant` diatur sehingga semua kueri tetap lewat scope `MilikTenant`. `--tenant` membatasi tenant.
 */
final class ProsesLoyaltiPelangganPerintah extends Command
{
    protected $signature = 'pelanggan:proses-loyalti {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Menghanguskan poin, paket sesi, dan gift card kedaluwarsa dan mengevaluasi tier pelanggan (F-16b, F-16d, CRM-06).';

    public function handle(
        KeanggotaanPengguna $keanggotaan,
        KonteksTenant $konteks,
        TanggalBisnisOutlet $tanggalBisnis,
        HanguskanPoinKedaluwarsa $hanguskan,
        EvaluasiTierPelanggan $evaluasi,
        HanguskanSesiKedaluwarsa $hanguskanSesi,
        HanguskanGiftCardKedaluwarsa $hanguskanGiftCard,
    ): int {
        $diminta = [];

        foreach ((array) $this->option('tenant') as $nilai) {
            $teks = is_scalar($nilai) ? trim((string) $nilai) : '';

            if (preg_match('/^[1-9]\d*$/', $teks) !== 1) {
                $this->error('Id tenant tidak valid: '.($teks === '' ? '(kosong)' : $teks));

                return self::FAILURE;
            }

            $diminta[] = (int) $teks;
        }

        $semua = $keanggotaan->AmbilSemuaIdTenant();
        $daftar = $diminta === [] ? $semua : array_values(array_intersect(array_unique($diminta), $semua));
        $sebelumnya = $konteks->Ambil();
        $poin = 0;
        $tier = 0;
        $sesi = 0;
        $giftCard = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);
                $hariIni = $tanggalBisnis->Hitung(null);
                $poin += $hanguskan->Jalankan($hariIni);
                $tier += $evaluasi->Jalankan($hariIni);
                $sesi += $hanguskanSesi->Jalankan($hariIni);
                $giftCard += $hanguskanGiftCard->Jalankan($hariIni);
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diproses, {$poin} poin dihanguskan, {$tier} tier pelanggan berubah, {$sesi} paket sesi hangus, {$giftCard} gift card hangus.");

        return self::SUCCESS;
    }
}
