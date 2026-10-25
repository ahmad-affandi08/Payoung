<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Kueri;

use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menentukan penerima siaran dari aturan segmen (D-210): tenant aktif yang bukan Uji/Demo/Internal, disaring menurut
 * status langganan, paket, kesehatan, atau daftar tenant tertentu; penerimanya Owner aktif tenant itu. Satu tujuan
 * (alamat email atau nomor) hanya menerima satu pesan per siaran, walau memiliki beberapa tenant.
 */
final class SegmenSiaran
{
    private const UKURAN_POTONGAN = 500;

    /**
     * Estimasi penerima untuk formulir (tanpa menyimpan apa pun).
     *
     * @param  array<string, mixed>  $segmen
     * @return array{TenantCocok: int, Penerima: int, TanpaKontak: int, Contoh: list<string>}
     */
    public function Hitung(array $segmen, KanalSiaran $kanal): array
    {
        $tenantCocok = $this->Kueri($segmen)->count();
        $penerima = 0;
        $tenantBerkontak = [];
        $contoh = [];

        foreach ($this->AmbilPenerima($segmen, $kanal) as $baris) {
            $penerima++;
            $tenantBerkontak[$baris['IdTenant']] = true;

            if (count($contoh) < 5) {
                $contoh[] = $baris['Nama'];
            }
        }

        return [
            'TenantCocok' => $tenantCocok,
            'Penerima' => $penerima,
            'TanpaKontak' => max(0, $tenantCocok - count($tenantBerkontak)),
            'Contoh' => $contoh,
        ];
    }

    /**
     * @param  array<string, mixed>  $segmen
     * @return iterable<array{IdTenant: int, Nama: string, Tujuan: string}>
     */
    public function AmbilPenerima(array $segmen, KanalSiaran $kanal): iterable
    {
        $sudah = [];

        foreach ($this->Kueri($segmen)->orderBy('Id')->select('Id')->pluck('Id')->chunk(self::UKURAN_POTONGAN) as $potongan) {
            $anggota = TenantPengguna::query()
                ->with('Pengguna:Id,Nama,Email,NoHp')
                ->whereIn('IdTenant', $potongan->all())
                ->where('Pemilik', true)
                ->where('Status', StatusKeanggotaan::Aktif->value)
                ->orderBy('Id')
                ->get();

            foreach ($anggota as $satu) {
                $tujuan = $kanal === KanalSiaran::Email
                    ? trim((string) $satu->Pengguna->Email)
                    : PesanWhatsapp::RapikanNomor((string) $satu->Pengguna->NoHp);

                if ($tujuan === '' || isset($sudah[$tujuan])) {
                    continue;
                }

                $sudah[$tujuan] = true;

                yield ['IdTenant' => $satu->IdTenant, 'Nama' => $satu->Pengguna->Nama, 'Tujuan' => $tujuan];
            }
        }
    }

    /**
     * Membersihkan segmen mentah menjadi hanya nilai yang dikenal, supaya yang tersimpan selalu valid.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{Status: list<string>, Paket: list<string>, Kesehatan: list<string>, Tenant: list<string>}
     */
    public static function Bersihkan(array $mentah): array
    {
        $ambil = static fn (string $kunci): array => array_values(array_unique(array_filter(
            is_array($mentah[$kunci] ?? null) ? $mentah[$kunci] : [],
            'is_string',
        )));

        return [
            'Status' => array_values(array_intersect($ambil('Status'), array_map(fn (StatusLangganan $s): string => $s->value, StatusLangganan::cases()))),
            'Paket' => $ambil('Paket'),
            'Kesehatan' => array_values(array_intersect($ambil('Kesehatan'), array_map(fn (KategoriKesehatan $k): string => $k->value, KategoriKesehatan::cases()))),
            'Tenant' => $ambil('Tenant'),
        ];
    }

    /**
     * @param  array<string, mixed>  $segmen
     * @return Builder<Tenant>
     */
    private function Kueri(array $segmen): Builder
    {
        $bersih = self::Bersihkan($segmen);

        return Tenant::query()
            ->where('Status', StatusTenant::Aktif->value)
            ->whereNull('Penanda')
            ->when($bersih['Status'] !== [], fn (Builder $k) => $k->whereIn('Id', Langganan::query()->select('IdTenant')->whereIn('Status', $bersih['Status'])))
            ->when($bersih['Paket'] !== [], fn (Builder $k) => $k->whereIn('Id', Langganan::query()->select('IdTenant')->whereIn('IdPaket', Paket::query()->select('Id')->whereIn('Kode', $bersih['Paket']))))
            ->when($bersih['Kesehatan'] !== [], fn (Builder $k) => $k->whereIn('Id', SkorKesehatanTenant::query()
                ->select('IdTenant')
                ->where('Tanggal', SkorKesehatanTenant::query()->max('Tanggal'))
                ->whereIn('Kategori', $bersih['Kesehatan'])))
            ->when($bersih['Tenant'] !== [], fn (Builder $k) => $k->whereIn('Uuid', $bersih['Tenant']));
    }
}
