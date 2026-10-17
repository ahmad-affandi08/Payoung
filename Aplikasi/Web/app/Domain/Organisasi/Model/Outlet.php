<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Outlet (PRD §15.3, F-02). F-00 membuat "Outlet Utama"; F-02 melengkapi kota, zona waktu, jam tutup buku, dan
 * profil pajak dasar. Outlet tidak pernah dihapus, hanya diarsipkan (dirujuk transaksi & laporan).
 *
 * `ProfilPajak` (disimpan F-02 & F-01, dipakai kalkulasi F-03/F-07): {"Pkp": bool, "Nitku": string|null,
 * "PungutPbjt": bool, "BiayaLayanan": {"Aktif": bool, "Persen": "5.00"}, "HargaTermasukPajak": bool}.
 * `TemplateSektor` + `IdTemplateSektorVersi` = template & versi terbit yang diterapkan F-01 (BR-P03.1); F-05/F-09/
 * F-10/F-14 membaca isi versi itu (alasan, stasiun dapur, laporan unggulan).
 * `KodeDikunciPada` diisi saat outlet mulai bertransaksi/punya perangkat; setelah itu kode tidak bisa diubah (BR-02.2).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdMerek
 * @property string $Kode
 * @property string $Nama
 * @property string|null $Alamat
 * @property string|null $Telepon dicetak di kepala struk bila saklar Telepon outlet menyala
 * @property string|null $KodeKota
 * @property string $ZonaWaktu
 * @property string|null $TemplateSektor
 * @property int|null $IdTemplateSektorVersi
 * @property Carbon|null $TemplateSektorDiterapkanPada
 * @property string $JamTutupBuku
 * @property array<string, mixed>|null $ProfilPajak
 * @property array<string, mixed>|null $PengaturanKasir jenis pesanan kasir (v3.51), null = otomatis menurut mode kasir
 * @property array<string, array{Buka: string, Tutup: string}|null>|null $JamOperasional jam buka per hari (jam lokal), null = tidak diatur (selalu buka)
 * @property StatusOrganisasi $Status
 * @property bool $Kanvas Modul Salesman bagian 3: outlet ini kendaraan kanvas salesman (lokasi stok Toko = kendaraan)
 * @property string|null $NomorKendaraan plat nomor kendaraan kanvas, misal "AD 1234 XY"
 * @property bool $PesanSendiriAktif F-17: tamu boleh memesan lewat QR meja (juga butuh fitur `kanal.self-order`)
 * @property bool $TokoOnlineAktif
 * @property bool $KiosAktif F-17 bagian 4: kios pesan sendiri di layar sentuh outlet (juga butuh fitur `kanal.self-order`)
 * @property string|null $TokenKios token rahasia di URL kios; null sampai pertama kali diaktifkan
 * @property bool $AmbilSendiriAktif
 * @property bool $KirimAktif
 * @property string|null $Lintang F-18 bagian 4 (D-37): titik lokasi absensi web (derajat desimal, 7 angka)
 * @property string|null $Bujur
 * @property int $RadiusAbsensiMeter radius geofence absensi web, bawaan 100 m
 * @property string|null $TokenLayarAbsen rahasia layar QR absensi outlet (terenkripsi): tautan layar & sumber kode berganti
 * @property string|null $HashTokenLayarAbsen
 * @property bool $WajibQrAbsensi absen web di outlet ini wajib menyertakan kode dari layar QR
 * @property Carbon|null $KodeDikunciPada
 * @property Carbon|null $DiarsipkanPada
 * @property-read Merek $Merek
 * @property-read Collection<int, Gudang> $Gudang
 */
final class Outlet extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Outlet';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Alamat' => null,
        'Telepon' => null,
        'KodeKota' => null,
        'ZonaWaktu' => 'Asia/Jakarta',
        'TemplateSektor' => null,
        'IdTemplateSektorVersi' => null,
        'TemplateSektorDiterapkanPada' => null,
        'JamTutupBuku' => '04:00',
        'ProfilPajak' => null,
        'PengaturanKasir' => null,
        'JamOperasional' => null,
        'Status' => 'Aktif',
        'Kanvas' => false,
        'NomorKendaraan' => null,
        'PesanSendiriAktif' => false,
        'TokoOnlineAktif' => false,
        'KiosAktif' => false,
        'TokenKios' => null,
        'AmbilSendiriAktif' => true,
        'KirimAktif' => false,
        'KodeDikunciPada' => null,
        'DiarsipkanPada' => null,
        'Lintang' => null,
        'Bujur' => null,
        'RadiusAbsensiMeter' => 100,
        'TokenLayarAbsen' => null,
        'HashTokenLayarAbsen' => null,
        'WajibQrAbsensi' => false,
    ];

    /** @var list<string> */
    protected $hidden = ['TokenLayarAbsen', 'HashTokenLayarAbsen', 'TokenKios'];

    /**
     * @return BelongsTo<Merek, $this>
     */
    public function Merek(): BelongsTo
    {
        return $this->belongsTo(Merek::class, 'IdMerek', 'Id');
    }

    /**
     * @return HasMany<Gudang, $this>
     */
    public function Gudang(): HasMany
    {
        return $this->hasMany(Gudang::class, 'IdOutlet', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ProfilPajak' => 'array',
            'PengaturanKasir' => 'array',
            'JamOperasional' => 'array',
            'Status' => StatusOrganisasi::class,
            'Kanvas' => 'boolean',
            'PesanSendiriAktif' => 'boolean',
            'TokoOnlineAktif' => 'boolean',
            'KiosAktif' => 'boolean',
            'AmbilSendiriAktif' => 'boolean',
            'KirimAktif' => 'boolean',
            'KodeDikunciPada' => 'datetime',
            'DiarsipkanPada' => 'datetime',
            'TemplateSektorDiterapkanPada' => 'datetime',
            'Lintang' => 'decimal:7',
            'Bujur' => 'decimal:7',
            'RadiusAbsensiMeter' => 'integer',
            'TokenLayarAbsen' => 'encrypted',
            'WajibQrAbsensi' => 'boolean',
        ];
    }
}
