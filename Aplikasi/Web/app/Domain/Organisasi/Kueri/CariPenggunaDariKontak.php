<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mencari akun pengguna dari alamat email atau nomor WhatsApp pengirim pesan masuk (dukungan PGL-15). Kecocokan harus
 * tepat satu akun: email dibandingkan tanpa beda huruf besar/kecil, nomor dinormalisasi ke `628…`. Nomor yang dipakai dua
 * akun atau lebih dianggap ambigu (null), karena pesan WhatsApp tidak punya bukti kepemilikan selain nomornya. Nomor
 * yang tersimpan dengan pemisah ("0812-3456-7890") tidak dikenali; akun itu membuat tiket dari aplikasi.
 */
final class CariPenggunaDariKontak
{
    /**
     * @return array{Id: int, Nama: string}|null
     */
    public function DariEmail(string $email): ?array
    {
        $rapi = mb_strtolower(trim($email));

        if ($rapi === '' || ! filter_var($rapi, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $cocok = Pengguna::query()->whereRaw('LOWER(`Email`) = ?', [$rapi])->limit(2)->get(['Id', 'Nama']);

        return self::TunggalAtauNull($cocok);
    }

    /**
     * @return array{Id: int, Nama: string}|null
     */
    public function DariNoHp(string $noHp): ?array
    {
        $nomor = PesanWhatsapp::AmbilNomorSah($noHp);

        if ($nomor === null) {
            return null;
        }

        // `Pengguna.NoHp` disimpan sebagaimana diketik (08…, +628…, 628…), jadi dicocokkan ke semua bentuk umumnya.
        $lokal = substr($nomor, 2);
        $cocok = Pengguna::query()->whereIn('NoHp', [$nomor, '+'.$nomor, '0'.$lokal, $lokal])->limit(2)->get(['Id', 'Nama']);

        return self::TunggalAtauNull($cocok);
    }

    /**
     * @param  Collection<int, Pengguna>  $cocok
     * @return array{Id: int, Nama: string}|null
     */
    private static function TunggalAtauNull(Collection $cocok): ?array
    {
        $satu = $cocok->first();

        return $cocok->count() === 1 && $satu !== null ? ['Id' => $satu->Id, 'Nama' => $satu->Nama] : null;
    }
}
