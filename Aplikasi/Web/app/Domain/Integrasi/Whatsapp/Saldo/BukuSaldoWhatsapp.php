<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp\Saldo;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\JenisMutasiSaldoWhatsapp;
use App\Domain\Integrasi\Model\KreditBroadcastOutlet;
use App\Domain\Integrasi\Model\MutasiSaldoWhatsapp;
use App\Domain\Integrasi\Model\SaldoWhatsapp;
use Illuminate\Support\Facades\DB;

/**
 * Buku saldo WhatsApp tenant (D-300). Dua kolam: saldo utama (`SaldoWhatsapp`) dan kredit broadcast per outlet
 * (`KreditBroadcastOutlet`, hasil alokasi). Semua mutasi memakai kunci baris kolam, menolak saldo minus, dan menulis
 * `MutasiSaldoWhatsapp` di transaksi yang sama. `(Jenis, Referensi)` unik membuat top-up dan pemakaian per pesan
 * idempoten: pemanggilan ulang tidak memotong dua kali.
 */
final class BukuSaldoWhatsapp
{
    public function __construct(private readonly KonteksTenant $konteks) {}

    public function AmbilSaldo(): Uang
    {
        return Uang::Dari((string) (SaldoWhatsapp::query()->value('Saldo') ?? '0'));
    }

    /** Kredit outlet; null bila outlet tidak punya kolam sendiri (memakai saldo utama). */
    public function AmbilKreditOutlet(int $idOutlet): ?Uang
    {
        $saldo = KreditBroadcastOutlet::query()->where('IdOutlet', $idOutlet)->value('Saldo');

        return $saldo === null ? null : Uang::Dari((string) $saldo);
    }

    public function TopUp(Uang $jumlah, string $referensi, ?int $idPengguna = null, ?string $keterangan = null): ?MutasiSaldoWhatsapp
    {
        return $this->Mutasi(null, $jumlah, JenisMutasiSaldoWhatsapp::TopUp, $referensi, $idPengguna, $keterangan);
    }

    /**
     * Potong satu pemakaian. Outlet dengan kolam kredit memakai kolam itu, selain itu saldo utama. Null bila saldo
     * tidak cukup (tidak ada yang dipotong). Pemakaian dengan referensi yang sama tidak dipotong dua kali.
     */
    public function Pakai(?int $idOutlet, Uang $jumlah, string $referensi, ?string $keterangan = null): ?MutasiSaldoWhatsapp
    {
        $pakaiKolamOutlet = $idOutlet !== null && KreditBroadcastOutlet::query()->where('IdOutlet', $idOutlet)->exists();

        return $this->Mutasi($pakaiKolamOutlet ? $idOutlet : null, Uang::Nol()->Kurangi($jumlah), JenisMutasiSaldoWhatsapp::Pemakaian, $referensi, null, $keterangan);
    }

    /** Kembalikan pemakaian yang pesannya gagal terkirim ke kolam yang sama. */
    public function Kembalikan(MutasiSaldoWhatsapp $pemakaian, string $referensi): ?MutasiSaldoWhatsapp
    {
        return $this->Mutasi($pemakaian->IdOutlet, Uang::Nol()->Kurangi(Uang::Dari($pemakaian->Jumlah)), JenisMutasiSaldoWhatsapp::Pengembalian, $referensi, null, 'Pesan gagal terkirim');
    }

    /** Pindahkan saldo utama ke kredit satu outlet (alokasi). */
    public function Alokasikan(int $idOutlet, Uang $jumlah, string $referensi, ?int $idPengguna): void
    {
        DB::transaction(function () use ($idOutlet, $jumlah, $referensi, $idPengguna): void {
            if ($this->Mutasi(null, Uang::Nol()->Kurangi($jumlah), JenisMutasiSaldoWhatsapp::AlokasiKeluar, $referensi, $idPengguna, 'Alokasi ke outlet') === null) {
                throw new PelanggaranAturanBisnis('SaldoKurang', 'Saldo utama tidak cukup untuk alokasi ini.', 'Jumlah');
            }

            $this->Mutasi($idOutlet, $jumlah, JenisMutasiSaldoWhatsapp::AlokasiMasuk, $referensi, $idPengguna, 'Kredit dari saldo utama');
        });
    }

    /** Tarik seluruh kredit outlet kembali ke saldo utama dan hapus kolam outlet (outlet kembali memakai saldo utama). */
    public function TarikKreditOutlet(int $idOutlet, string $referensi, ?int $idPengguna): Uang
    {
        return DB::transaction(function () use ($idOutlet, $referensi, $idPengguna): Uang {
            $kolam = KreditBroadcastOutlet::query()->where('IdOutlet', $idOutlet)->lockForUpdate()->first();

            if ($kolam === null) {
                return Uang::Nol();
            }

            $sisa = Uang::Dari($kolam->Saldo);

            if (! $sisa->BernilaiNol()) {
                if ($this->Mutasi($idOutlet, Uang::Nol()->Kurangi($sisa), JenisMutasiSaldoWhatsapp::AlokasiKeluar, $referensi, $idPengguna, 'Kredit outlet ditarik') === null) {
                    throw new PelanggaranAturanBisnis('SaldoKurang', 'Kredit outlet tidak cukup.');
                }

                $this->Mutasi(null, $sisa, JenisMutasiSaldoWhatsapp::AlokasiMasuk, $referensi, $idPengguna, 'Kredit outlet kembali ke saldo utama');
            }

            $kolam->delete();

            return $sisa;
        });
    }

    /** Pastikan outlet punya kolam kredit (dibuat kosong) sebelum alokasi pertama. */
    public function SiapkanKolamOutlet(int $idOutlet): void
    {
        KreditBroadcastOutlet::query()->firstOrCreate(['IdOutlet' => $idOutlet], ['IdTenant' => $this->konteks->Wajib(), 'Saldo' => '0.00']);
    }

    private function Mutasi(?int $idOutlet, Uang $jumlah, JenisMutasiSaldoWhatsapp $jenis, string $referensi, ?int $idPengguna, ?string $keterangan): ?MutasiSaldoWhatsapp
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idOutlet, $jumlah, $jenis, $referensi, $idPengguna, $keterangan): ?MutasiSaldoWhatsapp {
            $ada = MutasiSaldoWhatsapp::query()->where('Jenis', $jenis->value)->where('Referensi', $referensi)->where('IdOutlet', $idOutlet)->first();

            if ($ada !== null) {
                return $ada;
            }

            $kolam = $idOutlet === null
                ? (SaldoWhatsapp::query()->lockForUpdate()->first() ?? $this->BuatKolamUtama($idTenant))
                : KreditBroadcastOutlet::query()->where('IdOutlet', $idOutlet)->lockForUpdate()->first();

            if ($kolam === null) {
                throw new PelanggaranAturanBisnis('KolamTidakAda', 'Kredit outlet belum disiapkan.');
            }

            $baru = Uang::Dari($kolam->Saldo)->Tambah($jumlah);

            if ($baru->BernilaiNegatif()) {
                return null;
            }

            $kolam->update(['Saldo' => $baru->KeString()]);

            return MutasiSaldoWhatsapp::query()->create([
                'IdTenant' => $idTenant, 'IdOutlet' => $idOutlet, 'Jenis' => $jenis, 'Jumlah' => $jumlah->KeString(),
                'SaldoSetelah' => $baru->KeString(), 'Referensi' => $referensi, 'Keterangan' => $keterangan, 'IdPengguna' => $idPengguna,
            ]);
        });
    }

    private function BuatKolamUtama(int $idTenant): SaldoWhatsapp
    {
        SaldoWhatsapp::query()->insertOrIgnore(['IdTenant' => $idTenant, 'Saldo' => '0.00', 'DibuatPada' => now(), 'DiubahPada' => now()]);

        return SaldoWhatsapp::query()->lockForUpdate()->firstOrFail();
    }
}
