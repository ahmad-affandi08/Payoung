<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisPemenuhanOnline;
use App\Domain\Penjualan\Enum\JenisSantapKios;
use App\Domain\Penjualan\Enum\MetodePembayaranOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property string $KodeAkses
 * @property string $Nomor
 * @property int|null $IdPelanggan
 * @property JenisPemenuhanOnline $JenisPemenuhan
 * @property MetodePembayaranOnline $MetodePembayaran
 * @property string $NamaPelanggan
 * @property string $NoHp
 * @property SumberPesananOnline $Sumber
 * @property JenisSantapKios|null $JenisSantap
 * @property int|null $NomorAntrian
 * @property int|null $IdMeja
 * @property string|null $Email
 * @property string|null $Alamat
 * @property string|null $KodePos
 * @property int|null $IdZonaPengiriman
 * @property string|null $Catatan
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $BiayaLayanan
 * @property string $Pajak
 * @property string $Ongkir ongkir kotor (tarif zona); yang dibayar pembeli = Ongkir − DiskonOngkir
 * @property string $DiskonOngkir potongan promo gratis ongkir (F-16c); pasangan `Penjualan.DiskonKirim`
 * @property string|null $KodeVoucher kode voucher checkout (v3.46)
 * @property string $Total
 * @property array<string, mixed> $Perkiraan
 * @property StatusPesananOnline $Status
 * @property int|null $IdPenjualan
 * @property Carbon|null $DibayarPada
 * @property string|null $JumlahDibayar
 * @property string $UangMukaTerpakai
 * @property int|null $IdJurnal
 * @property Carbon|null $DikembalikanPada
 * @property int|null $IdJurnalRefund
 * @property string|null $HashNoHp
 * @property string|null $HashIp
 * @property int|null $DikonfirmasiOleh
 * @property Carbon|null $DikonfirmasiPada
 * @property Carbon|null $SelesaiPada
 * @property int|null $DiubahOleh
 * @property string|null $Alasan
 * @property Carbon|null $DibuatPada
 * @property-read Collection<int, PesananOnlineDetail> $Detail
 */
final class PesananOnline extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PesananOnline';

    protected $table = 'PesananOnline';

    protected $hidden = ['KodeAkses', 'NoHp', 'Email', 'Alamat', 'HashNoHp', 'HashIp'];

    protected $attributes = [
        'Sumber' => 'Web', 'JenisSantap' => null, 'NomorAntrian' => null, 'IdMeja' => null,
        'IdPelanggan' => null, 'Email' => null, 'Alamat' => null, 'Kelurahan' => null, 'Kecamatan' => null,
        'Kota' => null, 'Provinsi' => null, 'KodePos' => null, 'IdZonaPengiriman' => null, 'Catatan' => null,
        'Diskon' => '0.00', 'BiayaLayanan' => '0.00', 'Pajak' => '0.00', 'Ongkir' => '0.00', 'DiskonOngkir' => '0.00',
        'KodeVoucher' => null, 'IdPenjualan' => null, 'DibayarPada' => null, 'JumlahDibayar' => null, 'UangMukaTerpakai' => '0.00',
        'IdJurnal' => null, 'DikembalikanPada' => null, 'IdJurnalRefund' => null, 'HashNoHp' => null, 'HashIp' => null, 'DikonfirmasiOleh' => null, 'DikonfirmasiPada' => null,
        'SelesaiPada' => null, 'DiubahOleh' => null, 'Alasan' => null,
    ];

    /** @return HasMany<PesananOnlineDetail, $this> */
    public function Detail(): HasMany
    {
        return $this->hasMany(PesananOnlineDetail::class, 'IdPesananOnline', 'Id')->orderBy('Urutan');
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    /** Uang pelanggan yang sudah diterima di muka (J-17.1); nol untuk bayar saat ambil/COD. */
    public function AmbilJumlahDibayar(): Uang
    {
        return Uang::Dari($this->JumlahDibayar ?? '0.00');
    }

    /** Uang muka yang belum dipakai penjualan mana pun dan belum dikembalikan: inilah kewajiban toko yang tersisa. */
    public function AmbilSisaUangMuka(): Uang
    {
        if ($this->DikembalikanPada !== null) {
            return Uang::Nol();
        }

        return $this->AmbilJumlahDibayar()->Kurangi(Uang::Dari($this->UangMukaTerpakai));
    }

    public function UbahStatus(StatusPesananOnline $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status pesanan {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    protected function casts(): array
    {
        return [
            'JenisPemenuhan' => JenisPemenuhanOnline::class,
            'Sumber' => SumberPesananOnline::class,
            'JenisSantap' => JenisSantapKios::class,
            'MetodePembayaran' => MetodePembayaranOnline::class,
            'NoHp' => 'encrypted', 'Email' => 'encrypted', 'Alamat' => 'encrypted',
            'Subtotal' => 'decimal:2', 'Diskon' => 'decimal:2', 'BiayaLayanan' => 'decimal:2', 'Pajak' => 'decimal:2',
            'Ongkir' => 'decimal:2', 'DiskonOngkir' => 'decimal:2', 'Total' => 'decimal:2', 'Perkiraan' => 'array',
            'JumlahDibayar' => 'decimal:2', 'UangMukaTerpakai' => 'decimal:2',
            'DibayarPada' => 'datetime', 'DikembalikanPada' => 'datetime',
            'Status' => StatusPesananOnline::class,
            'DikonfirmasiPada' => 'datetime', 'SelesaiPada' => 'datetime',
        ];
    }
}
