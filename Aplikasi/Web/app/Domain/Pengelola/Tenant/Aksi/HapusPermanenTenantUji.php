<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PenghapusBerkasTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus PERMANEN satu tenant uji beserta seluruh datanya (D-213, BR-P07.9). Khusus tenant berpenanda Uji, Demo,
 * atau Internal: tenant sungguhan tidak pernah bisa dihapus lewat jalur ini (pembukuan wajib disimpan 10 tahun, jalur
 * resminya penghapusan data UU PDP yang menganonimkan, bukan menghapus). Tidak bisa dibalik.
 *
 * Yang dihapus: semua baris yang berkolom `IdTenant`, baris anak yang hanya terhubung lewat foreign key ke baris tenant
 * itu, rujukan foreign key ke `Tenant` berkolom lain, akun pengguna yang HANYA anggota tenant ini (beserta sesi, token,
 * dan perangkatnya), baris `Tenant`, dan semua berkas di penyimpanan. Pengguna yang juga anggota tenant lain hanya
 * dilepas. `LogAuditPengelola` sengaja dipertahankan: jejak bahwa tenant ini pernah ada dan siapa yang menghapusnya
 * harus tetap ada.
 *
 * Penjagaan: penanda wajib ada, slug diketik ulang, tidak boleh punya tagihan langganan berbayar (lunas atau
 * dikembalikan dengan total di atas nol) atau komisi mitra (angka pendapatan platform tidak boleh berubah diam-diam),
 * dan alasan wajib. Foreign key dimatikan sementara pada koneksi ini supaya urutan hapus tidak penting; semuanya satu
 * transaksi sehingga gagal di tengah = tidak ada yang terhapus. Berkas dihapus sesudah transaksi sukses.
 *
 * @phpstan-type Skema array{TabelTenant: list<string>, FkKeTenant: list<array{0: string, 1: string, 2: bool}>, FkAnak: list<array{0: string, 1: string, 2: string, 3: string}>, FkKePengguna: list<array{0: string, 1: string}>}
 */
final class HapusPermanenTenantUji
{
    /** Tabel ber-`IdTenant` yang sengaja tidak ikut dihapus. */
    public const TABEL_DIPERTAHANKAN = ['LogAuditPengelola'];

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenghapusBerkasTenant $penghapusBerkas,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return array{Nama: string, Slug: string, Baris: int, Tabel: int, Pengguna: int, PenggunaDilepas: int, Berkas: int, BerkasGagal: int}
     */
    public function Jalankan(PenggunaPengelola $pelaku, Tenant $tenant, string $konfirmasiSlug, string $alasan): array
    {
        $alasan = trim($alasan);
        $this->Periksa($tenant, $konfirmasiSlug, $alasan);

        $idTenant = $tenant->Id;
        $nama = $tenant->Nama;
        $slug = $tenant->Slug;
        $uuid = $tenant->Uuid;
        $penanda = $tenant->Penanda?->value;

        $hapus = $this->konteks->JalankanLintasTenant(
            'Menghapus permanen tenant uji',
            fn (): array => $this->HapusSemua($idTenant),
        );

        $berkasPribadi = $this->penghapusBerkas->HapusPribadi($idTenant);
        $berkasPembukuan = $this->penghapusBerkas->HapusPembukuan($idTenant);

        $this->audit->Catat(
            'tenant.hapus-permanen',
            nilaiLama: ['Nama' => $nama, 'Slug' => $slug, 'Uuid' => $uuid, 'Penanda' => $penanda],
            nilaiBaru: [
                'Baris' => $hapus['Baris'],
                'Tabel' => $hapus['Tabel'],
                'Pengguna' => $hapus['Pengguna'],
                'PenggunaDilepas' => $hapus['PenggunaDilepas'],
                'Berkas' => $berkasPribadi['Berkas'] + $berkasPembukuan['Berkas'],
                'BerkasGagal' => $berkasPribadi['Gagal'] + $berkasPembukuan['Gagal'],
            ],
            alasan: $alasan,
            idPelaku: $pelaku->Id,
            idTenant: $idTenant,
        );

        return [
            'Nama' => $nama,
            'Slug' => $slug,
            ...$hapus,
            'Berkas' => $berkasPribadi['Berkas'] + $berkasPembukuan['Berkas'],
            'BerkasGagal' => $berkasPribadi['Gagal'] + $berkasPembukuan['Gagal'],
        ];
    }

    /**
     * Syarat yang sama dipakai aksi massal (tanpa mengetik slug satu per satu) lewat `Periksa` dengan slug tenant itu
     * sendiri; pemeriksaan penanda & tagihan selalu dari basis data, bukan dari model yang dibawa pemanggil.
     */
    private function Periksa(Tenant $tenant, string $konfirmasiSlug, string $alasan): void
    {
        $segar = Tenant::query()->whereKey($tenant->Id)->first();

        if ($segar === null) {
            throw new PelanggaranAturanBisnis('TenantTidakAda', 'Tenant ini sudah tidak ada.');
        }

        if ($segar->Penanda === null) {
            throw new PelanggaranAturanBisnis('BR-P07.9', "{$segar->Nama} bukan tenant uji. Hanya tenant berpenanda Uji, Demo, atau Internal yang bisa dihapus permanen. Tenant sungguhan ditutup lewat penghapusan data UU PDP.");
        }

        if (trim($konfirmasiSlug) !== $segar->Slug) {
            throw new PelanggaranAturanBisnis('KonfirmasiSlugSalah', 'Slug yang diketik tidak sama dengan slug tenant ini.', 'KonfirmasiSlug');
        }

        if (mb_strlen($alasan) < 10) {
            throw new PelanggaranAturanBisnis('AlasanKurang', 'Tulis alasan minimal 10 karakter.', 'Alasan');
        }

        $berbayar = DB::table('TagihanLangganan')
            ->where('IdTenant', $segar->Id)
            ->whereIn('Status', [StatusTagihanLangganan::Lunas->value, StatusTagihanLangganan::Dikembalikan->value])
            ->where('Total', '>', 0)
            ->exists();

        if ($berbayar) {
            throw new PelanggaranAturanBisnis('BR-P07.9', "{$segar->Nama} punya tagihan langganan berbayar. Menghapusnya mengubah pendapatan platform. Hapus dulu penandanya bila memang ini tenant sungguhan.");
        }

        if (DB::table('KomisiMitra')->where('IdTenant', $segar->Id)->exists()) {
            throw new PelanggaranAturanBisnis('BR-P07.9', "{$segar->Nama} punya komisi mitra yang tercatat, jadi tidak bisa dihapus permanen.");
        }
    }

    /**
     * @return array{Baris: int, Tabel: int, Pengguna: int, PenggunaDilepas: int}
     */
    private function HapusSemua(int $idTenant): array
    {
        $skema = $this->BacaSkema();

        return DB::transaction(function () use ($idTenant, $skema): array {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                return $this->Hapus($idTenant, $skema);
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    /**
     * @param  Skema  $skema
     * @return array{Baris: int, Tabel: int, Pengguna: int, PenggunaDilepas: int}
     */
    private function Hapus(int $idTenant, array $skema): array
    {
        $anggota = array_map('intval', DB::table('TenantPengguna')->where('IdTenant', $idTenant)->pluck('IdPengguna')->all());
        $diTenantLain = $anggota === [] ? [] : array_map('intval', DB::table('TenantPengguna')
            ->whereIn('IdPengguna', $anggota)->where('IdTenant', '<>', $idTenant)->distinct()->pluck('IdPengguna')->all());
        $yatim = array_values(array_diff($anggota, $diTenantLain));

        $baris = 0;
        $tabel = [];

        // Anak yang tidak punya `IdTenant` sendiri (hanya terhubung lewat foreign key) dihapus lebih dulu, berurutan dari
        // yang terdalam, selagi induknya masih ada untuk dicocokkan.
        foreach ($skema['FkAnak'] as [$anak, $kolom, $induk, $kolomInduk]) {
            $jumlah = $this->HapusAnak($anak, $kolom, $induk, $kolomInduk, $skema, $idTenant);
            $baris += $jumlah;

            if ($jumlah > 0) {
                $tabel[$anak] = true;
            }
        }

        foreach ($skema['TabelTenant'] as $nama) {
            $jumlah = DB::table($nama)->where('IdTenant', $idTenant)->delete();
            $baris += $jumlah;

            if ($jumlah > 0) {
                $tabel[$nama] = true;
            }
        }

        // Rujukan ke `Tenant` lewat kolom bernama lain: dikosongkan bila boleh null (baris itu bisa saja milik pihak lain,
        // misalnya akun bersama), dihapus bila wajib terisi.
        foreach ($skema['FkKeTenant'] as [$nama, $kolom, $boleh]) {
            $jumlah = $boleh
                ? DB::table($nama)->where($kolom, $idTenant)->update([$kolom => null])
                : DB::table($nama)->where($kolom, $idTenant)->delete();
            $baris += $jumlah;

            if ($jumlah > 0) {
                $tabel[$nama] = true;
            }
        }

        $baris += $this->HapusPengguna($yatim, $skema, $tabel);
        $baris += DB::table('Tenant')->where('Id', $idTenant)->delete();

        return ['Baris' => $baris, 'Tabel' => count($tabel) + 1, 'Pengguna' => count($yatim), 'PenggunaDilepas' => count($diTenantLain)];
    }

    /**
     * @param  list<int>  $idPengguna
     * @param  Skema  $skema
     * @param  array<string, bool>  $tabel
     */
    private function HapusPengguna(array $idPengguna, array $skema, array &$tabel): int
    {
        if ($idPengguna === []) {
            return 0;
        }

        $baris = 0;

        foreach (array_chunk($idPengguna, 500) as $kelompok) {
            $surel = DB::table('Pengguna')->whereIn('Id', $kelompok)->whereNotNull('Email')->pluck('Email')->all();

            foreach ($skema['FkKePengguna'] as [$nama, $kolom]) {
                $jumlah = DB::table($nama)->whereIn($kolom, $kelompok)->delete();
                $baris += $jumlah;

                if ($jumlah > 0) {
                    $tabel[$nama] = true;
                }
            }

            $baris += DB::table('sessions')->whereIn('user_id', $kelompok)->delete();

            if ($surel !== []) {
                $baris += DB::table('password_reset_tokens')->whereIn('email', $surel)->delete();
            }

            $baris += DB::table('Pengguna')->whereIn('Id', $kelompok)->delete();
        }

        return $baris;
    }

    /**
     * Menghapus baris tabel `$anak` yang kolom `$kolom`-nya menunjuk baris tenant ini, baik langsung (induk ber-`IdTenant`)
     * maupun lewat rantai induk yang juga tanpa `IdTenant`.
     *
     * @param  Skema  $skema
     */
    private function HapusAnak(string $anak, string $kolom, string $induk, string $kolomInduk, array $skema, int $idTenant): int
    {
        [$sql, $ikat] = $this->PilihanInduk($induk, $kolomInduk, $skema, $idTenant);

        return DB::affectingStatement(
            sprintf('DELETE FROM %s WHERE %s IN (%s)', self::Kutip($anak), self::Kutip($kolom), $sql),
            $ikat,
        );
    }

    /**
     * SQL pemilih nilai kolom induk milik tenant: langsung bila induk ber-`IdTenant`, atau bersarang lewat rantai
     * foreign key. Rantai dibatasi kedalaman supaya siklus skema tidak membuat rekursi tak berujung.
     *
     * @param  Skema  $skema
     * @return array{0: string, 1: list<int>}
     */
    private function PilihanInduk(string $induk, string $kolomInduk, array $skema, int $idTenant, int $kedalaman = 0): array
    {
        if (in_array($induk, $skema['TabelTenant'], true)) {
            return [sprintf('SELECT %s FROM %s WHERE `IdTenant` = ?', self::Kutip($kolomInduk), self::Kutip($induk)), [$idTenant]];
        }

        if ($kedalaman >= 6) {
            return ['SELECT NULL FROM DUAL WHERE 1 = 0', []];
        }

        foreach ($skema['FkAnak'] as [$anak, $kolom, $indukAnak, $kolomIndukAnak]) {
            if ($anak !== $induk) {
                continue;
            }

            [$sqlInduk, $ikat] = $this->PilihanInduk($indukAnak, $kolomIndukAnak, $skema, $idTenant, $kedalaman + 1);

            return [
                sprintf('SELECT %s FROM %s WHERE %s IN (%s)', self::Kutip($kolomInduk), self::Kutip($induk), self::Kutip($kolom), $sqlInduk),
                $ikat,
            ];
        }

        return ['SELECT NULL FROM DUAL WHERE 1 = 0', []];
    }

    /**
     * Peta tabel dari `information_schema` (pengenal bukan masukan pengguna). `FkAnak` diurutkan dari yang terdalam
     * (tabel yang tidak dirujuk anak tanpa `IdTenant` lain) supaya penghapusan berantai menemukan induknya.
     *
     * @return Skema
     */
    private function BacaSkema(): array
    {
        $semuaTabel = array_map('strval', array_column(DB::select(
            'SELECT TABLE_NAME AS Nama FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
            ['BASE TABLE'],
        ), 'Nama'));
        $berTenant = array_map('strval', array_column(DB::select(
            'SELECT TABLE_NAME AS Nama FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
            ['IdTenant'],
        ), 'Nama'));
        $tabelTenant = array_values(array_diff($berTenant, ['Tenant'], self::TABEL_DIPERTAHANKAN));
        $adaTenant = array_flip($berTenant);

        $fkKeTenant = [];
        $fkKePengguna = [];
        $kandidatAnak = [];

        foreach (DB::select(
            'SELECT TABLE_NAME AS Tabel, COLUMN_NAME AS Kolom, REFERENCED_TABLE_NAME AS Induk, REFERENCED_COLUMN_NAME AS KolomInduk
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, COLUMN_NAME',
        ) as $fk) {
            $tabel = (string) $fk->Tabel;
            $kolom = (string) $fk->Kolom;
            $induk = (string) $fk->Induk;

            if ($induk === 'Tenant' && $kolom !== 'IdTenant' && ! in_array($tabel, self::TABEL_DIPERTAHANKAN, true) && $tabel !== 'Tenant') {
                $fkKeTenant[] = [$tabel, $kolom, $this->BolehNull($tabel, $kolom)];
            }

            if ($induk === 'Pengguna' && ! isset($adaTenant[$tabel]) && ! in_array($tabel, ['Pengguna', 'TenantPengguna'], true)) {
                $fkKePengguna[] = [$tabel, $kolom];
            }

            if (! isset($adaTenant[$tabel]) && $tabel !== 'Tenant' && $tabel !== $induk && $induk !== 'Tenant' && $induk !== 'Pengguna' && in_array($tabel, $semuaTabel, true)) {
                $kandidatAnak[] = [$tabel, $kolom, $induk, (string) $fk->KolomInduk];
            }
        }

        // Hanya anak yang rantai induknya berujung di tabel ber-`IdTenant` yang ikut dihapus.
        $sah = array_flip($tabelTenant);
        $anak = [];
        $berubah = true;

        while ($berubah) {
            $berubah = false;

            foreach ($kandidatAnak as $kandidat) {
                [$tabel, , $induk] = $kandidat;

                if (isset($sah[$induk]) && ! isset($sah[$tabel])) {
                    $sah[$tabel] = true;
                    $anak[] = $kandidat;
                    $berubah = true;
                }
            }
        }

        // Anak terdalam lebih dulu: urutan balik dari penemuannya (induk ditemukan sebelum anaknya).
        return [
            'TabelTenant' => $tabelTenant,
            'FkKeTenant' => $fkKeTenant,
            'FkAnak' => array_reverse($anak),
            'FkKePengguna' => $fkKePengguna,
        ];
    }

    private function BolehNull(string $tabel, string $kolom): bool
    {
        $baris = DB::selectOne(
            'SELECT IS_NULLABLE AS Boleh FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabel, $kolom],
        );

        return $baris !== null && $baris->Boleh === 'YES';
    }

    private static function Kutip(string $nama): string
    {
        return '`'.str_replace('`', '``', $nama).'`';
    }
}
