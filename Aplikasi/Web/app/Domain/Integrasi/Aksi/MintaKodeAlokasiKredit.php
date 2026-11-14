<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Model\KodeAlokasiKredit;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Integrasi\Whatsapp\Saldo\BukuSaldoWhatsapp;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D-300: meminta kode OTP 6 digit untuk mengalokasikan saldo ke kredit broadcast satu outlet. Kode dikirim lewat nomor
 * WhatsApp Payoung ke nomor HP pengguna yang meminta (bukan lewat nomor milik tenant), hanya hash yang disimpan, berlaku
 * 10 menit, dan satu permintaan per menit. Permintaan baru mematikan kode lama yang belum dipakai.
 */
final class MintaKodeAlokasiKredit
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly KetersediaanWhatsapp $ketersediaan,
        private readonly PembuatPengirimWhatsapp $pembuat,
        private readonly BukuSaldoWhatsapp $buku,
    ) {}

    /** @return string nomor tujuan tersamar (misal ••••7890) untuk ditampilkan */
    public function Jalankan(Pengguna $pelaku, int $idOutlet, Uang $jumlah): string
    {
        $idTenant = $this->konteks->Wajib();

        if (! $this->ketersediaan->TersediaUntuk($idTenant)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakTersedia', 'Layanan WhatsApp belum aktif untuk usaha ini.', statusHttp: 409);
        }

        if ($jumlah->BernilaiNol() || $jumlah->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('JumlahTidakSah', 'Jumlah alokasi harus lebih dari nol.', 'Jumlah');
        }

        if ($jumlah->Bandingkan($this->buku->AmbilSaldo()) > 0) {
            throw new PelanggaranAturanBisnis('SaldoKurang', 'Saldo utama tidak cukup untuk alokasi ini. Saldo saat ini '.$this->buku->AmbilSaldo()->FormatRupiah().'.', 'Jumlah');
        }

        $nomor = PesanWhatsapp::AmbilNomorSah($pelaku->NoHp)
            ?? throw new PelanggaranAturanBisnis('NomorHpKosong', 'Kode verifikasi dikirim lewat WhatsApp. Isi nomor HP akun Anda dulu di profil.', 'Jumlah', 409);
        $pengirim = $this->pembuat->AmbilAktifPlatform()
            ?? throw new PelanggaranAturanBisnis('PengirimBelumSiap', 'Layanan pengirim kode belum siap. Hubungi dukungan Payoung.', 'Jumlah', 409);

        if (! Cache::add("alokasi-kredit.kode.{$pelaku->Id}", 1, 60)) {
            throw new PelanggaranAturanBisnis('TungguSebentar', 'Kode baru bisa diminta satu menit lagi.', 'Jumlah', 429);
        }

        $kode = (string) random_int(100000, 999999);

        DB::transaction(function () use ($idTenant, $pelaku, $idOutlet, $jumlah, $kode): void {
            KodeAlokasiKredit::query()->where('IdPengguna', $pelaku->Id)->whereNull('DigunakanPada')->update(['DigunakanPada' => now()]);
            KodeAlokasiKredit::query()->create([
                'IdTenant' => $idTenant, 'IdPengguna' => $pelaku->Id, 'IdOutlet' => $idOutlet, 'Jumlah' => $jumlah->KeString(),
                'KodeHash' => self::Hitung($kode), 'KedaluwarsaPada' => now()->addMinutes(KodeAlokasiKredit::MENIT_BERLAKU),
            ]);
        });

        $hasil = $pengirim->Kirim(new PesanWhatsapp($nomor, "Kode alokasi kredit broadcast Payoung: {$kode}. Berlaku ".KodeAlokasiKredit::MENIT_BERLAKU.' menit. Jangan berikan kode ini kepada siapa pun.'));

        if (! $hasil->berhasil) {
            KodeAlokasiKredit::query()->where('IdPengguna', $pelaku->Id)->whereNull('DigunakanPada')->update(['DigunakanPada' => now()]);

            throw new PelanggaranAturanBisnis('KodeGagalTerkirim', 'Kode belum terkirim. Coba lagi sebentar.', 'Jumlah', 409);
        }

        return '••••'.substr($nomor, -4);
    }

    public static function Hitung(string $kode): string
    {
        return hash_hmac('sha256', $kode, (string) config('app.key'));
    }
}
