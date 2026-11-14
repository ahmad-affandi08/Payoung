<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Model\KodeAlokasiKredit;
use App\Domain\Integrasi\Whatsapp\Saldo\BukuSaldoWhatsapp;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * D-300: mengalokasikan saldo utama ke kredit broadcast satu outlet setelah kode OTP benar. Kode terkait jumlah dan
 * outlet yang diminta (tidak bisa dipakai untuk alokasi lain), sekali pakai, tiga salah mematikannya. Menarik kredit
 * outlet kembali ke saldo utama tidak memakai OTP (uang kembali ke kolam yang sama pemiliknya).
 */
final class AlokasikanKreditBroadcast
{
    public function __construct(private readonly BukuSaldoWhatsapp $buku, private readonly PencatatAudit $audit) {}

    public function Jalankan(Pengguna $pelaku, int $idOutlet, Uang $jumlah, string $kode): void
    {
        // Percobaan salah harus tersimpan walau permintaan ditolak, jadi galat dilempar setelah transaksi selesai.
        $galat = DB::transaction(function () use ($pelaku, $idOutlet, $jumlah, $kode): ?PelanggaranAturanBisnis {
            $baris = KodeAlokasiKredit::query()->where('IdPengguna', $pelaku->Id)->whereNull('DigunakanPada')->orderByDesc('Id')->lockForUpdate()->first();

            if ($baris === null || $baris->KedaluwarsaPada->isPast()) {
                return new PelanggaranAturanBisnis('KodeTidakAda', 'Kode tidak ada atau sudah kedaluwarsa. Minta kode baru.', 'Kode');
            }

            if ($baris->IdOutlet !== $idOutlet || ! Uang::Dari($baris->Jumlah)->SamaDengan($jumlah)) {
                return new PelanggaranAturanBisnis('KodeTidakCocok', 'Kode ini diminta untuk alokasi lain. Minta kode baru.', 'Kode');
            }

            if (! hash_equals($baris->KodeHash, MintaKodeAlokasiKredit::Hitung(trim($kode)))) {
                $baris->Percobaan++;

                if ($baris->Percobaan >= KodeAlokasiKredit::MAKS_PERCOBAAN) {
                    $baris->DigunakanPada = now();
                }

                $baris->save();

                return new PelanggaranAturanBisnis('KodeSalah', $baris->DigunakanPada !== null ? 'Kode salah tiga kali dan dimatikan. Minta kode baru.' : 'Kode salah.', 'Kode');
            }

            if ($this->buku->AmbilSaldo()->Bandingkan($jumlah) < 0) {
                return new PelanggaranAturanBisnis('SaldoKurang', 'Saldo utama tidak cukup untuk alokasi ini.', 'Jumlah');
            }

            $baris->update(['DigunakanPada' => now()]);
            $this->buku->SiapkanKolamOutlet($idOutlet);
            $this->buku->Alokasikan($idOutlet, $jumlah, 'AL-'.$baris->Id, $pelaku->Id);
            $this->audit->Catat('saldo-whatsapp.alokasi', null, nilaiBaru: ['IdOutlet' => $idOutlet, 'Jumlah' => $jumlah->KeString()], idPengguna: $pelaku->Id);

            return null;
        });

        if ($galat !== null) {
            throw $galat;
        }
    }

    public function Tarik(Pengguna $pelaku, int $idOutlet): Uang
    {
        $sisa = $this->buku->TarikKreditOutlet($idOutlet, 'TR-'.$idOutlet.'-'.now()->format('YmdHis'), $pelaku->Id);
        $this->audit->Catat('saldo-whatsapp.tarik', null, nilaiBaru: ['IdOutlet' => $idOutlet, 'Jumlah' => $sisa->KeString()], idPengguna: $pelaku->Id);

        return $sisa;
    }
}
