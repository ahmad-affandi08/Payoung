<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Kueri\MenuPesanSendiri;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Kueri\ProfilPembeliOnline;
use App\Domain\Pelanggan\Layanan\SesiPembeliOnline;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pemenuhan\Model\Kurir;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Aksi\BuatPesananOnline;
use App\Domain\Penjualan\Aksi\BuatTagihanQrisPesananOnline;
use App\Domain\Penjualan\Enum\JenisPemenuhanOnline;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Kueri\RiwayatBelanjaPembeliOnline;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Layanan\PembuatQrTagihanQris;
use App\Domain\Penjualan\Layanan\PenentuAkunTokoOnline;
use App\Domain\Penjualan\Layanan\PenentuKonteksTokoOnline;
use App\Domain\Penjualan\Layanan\PenghitungPesanSendiri;
use App\Domain\Penjualan\Layanan\PenghitungTokoOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PenyimpanLogoTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Publik\TokoOnlinePermintaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TokoOnlineKontroler extends Kontroler
{
    public const MAKS_GAGAL_VOUCHER = 10;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PenentuKonteksTokoOnline $penentu,
        private readonly PenentuAkunTokoOnline $akun,
        private readonly SesiPembeliOnline $sesi,
    ) {}

    public function Tampilkan(string $slugTenant, Request $request, MenuPesanSendiri $menu, ProfilPembeliOnline $profilPembeli, RiwayatBelanjaPembeliOnline $riwayat): SymfonyResponse
    {
        $uuidDipilih = is_string($request->query('outlet')) ? strtoupper($request->query('outlet')) : null;
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $uuidDipilih, $menu, $request, $profilPembeli, $riwayat): ?array {
            $k = $this->penentu->Cari($uuidDipilih) ?? $this->penentu->Cari();
            if ($k === null) {
                return null;
            }
            $outlet = Outlet::query()->findOrFail($k->idOutlet);
            $atur = PengaturanTokoOnline::query()->first() ?? new PengaturanTokoOnline;
            $daftarOutlet = Outlet::query()->where('Status', 'Aktif')->where('TokoOnlineAktif', true)->orderBy('Nama')->get(['Uuid', 'Nama']);
            // QRIS bisa dipakai untuk ambil sendiri maupun kirim, jadi sakelarnya menghidupkan keduanya.
            // Sakelar QRIS tidak cukup: gerbang bisa dimatikan setelahnya (KesiapanQrisOnline).
            $qrisSiap = $atur->QrisAktif && app(KesiapanQrisOnline::class)->CekSiap();
            $bisaAmbil = $outlet->AmbilSendiriAktif && ($atur->BayarSaatAmbilAktif || $qrisSiap);
            $bisaKirim = $outlet->KirimAktif && ($atur->CodAktif || $qrisSiap);
            $akunAktif = $this->akun->CekAktif();
            $pembeli = $akunAktif ? $this->CariPembeli($request) : null;

            return [
                'Aktif' => $k->aktif && ($bisaAmbil || $bisaKirim),
                'Slug' => $slugTenant,
                'Toko' => [
                    'Nama' => app(NamaTampilUsaha::class)->UntukOutlet($k->idOutlet, $idTenant),
                    'NamaOutlet' => $k->namaOutlet,
                    'Alamat' => $outlet->Alamat,
                    // Logo outlet (bila ada) menimpa logo usaha; tanpa keduanya null dan kepala toko memakai inisial.
                    'UrlLogo' => app(PengaturanStrukTenant::class)->AmbilPathLogoUsaha($idTenant, $k->idOutlet) === null
                        ? null
                        : url("/{$slugTenant}/logo").'?outlet='.$outlet->Uuid,
                ],
                'Outlet' => $daftarOutlet->map(fn (Outlet $o): array => ['Uuid' => $o->Uuid, 'Nama' => $o->Nama])->values()->all(),
                'OutletDipilih' => $outlet->Uuid,
                'Pemenuhan' => ['AmbilSendiri' => $bisaAmbil, 'Kirim' => $bisaKirim],
                'Pembayaran' => ['BayarSaatAmbil' => $atur->BayarSaatAmbilAktif, 'Cod' => $atur->CodAktif, 'QrisOnline' => $qrisSiap],
                'MinimalPesanan' => $atur->MinimalPesanan,
                // Pesan tutup manual pemilik lebih dulu; bila tidak ada, kalimat "Buka lagi …" dari jam operasional outlet.
                'PesanTutup' => $atur->PesanTutup ?? $this->penentu->AmbilPesanTutupJam($k->idOutlet),
                'Menu' => $k->aktif && ($bisaAmbil || $bisaKirim) ? $menu->Ambil($k->idOutlet, url("/{$slugTenant}/gambar/{uuid}"), KanalPenjualan::Online, true) : ['Kategori' => [], 'Produk' => []],
                // F-17 bagian 3: akun pembeli opsional. Harga di `Menu` tetap harga umum; harga tier pembeli yang
                // masuk dihitung server di keranjang/checkout.
                'Akun' => [
                    'Aktif' => $akunAktif,
                    'Pelanggan' => $pembeli === null ? null : $profilPembeli->Ambil($pembeli),
                    'AlamatTerakhir' => $pembeli === null ? null : $riwayat->AmbilAlamatTerakhir($pembeli->Id),
                ],
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/TokoOnline', $props ?? [
            'Aktif' => false, 'Slug' => $slugTenant, 'Toko' => null,
            'Outlet' => [], 'OutletDipilih' => '',
            'Pemenuhan' => ['AmbilSendiri' => false, 'Kirim' => false],
            'Pembayaran' => ['BayarSaatAmbil' => false, 'Cod' => false, 'QrisOnline' => false],
            'MinimalPesanan' => '0.00', 'PesanTutup' => null, 'Menu' => ['Kategori' => [], 'Produk' => []],
            'Akun' => ['Aktif' => false, 'Pelanggan' => null, 'AlamatTerakhir' => null],
        ])->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    public function Hitung(string $slugTenant, TokoOnlinePermintaan $permintaan, PenghitungTokoOnline $penghitung): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($permintaan, $penghitung): JsonResponse {
            $jenis = JenisPemenuhanOnline::from((string) $permintaan->validated('JenisPemenuhan'));
            $hasil = self::DenganBatasVoucher($permintaan, fn (): array => $penghitung->Hitung(
                $this->penentu->WajibAktif((string) $permintaan->validated('Outlet')),
                $permintaan->AmbilBaris(),
                $jenis,
                $permintaan->validated('KodePos'),
                $this->CariIdPembeli($permintaan),
                $permintaan->validated('KodeVoucher'),
            ));

            return response()->json([
                'Baris' => array_map(fn (array $b): array => ['UuidProduk' => $b['UuidProduk'], 'NamaProduk' => $b['NamaProduk'], 'Jumlah' => $b['Jumlah']->KeString(), 'Total' => $b['Total']->KeString()], $hasil['Baris']),
                'Subtotal' => $hasil['Subtotal']->KeString(), ...PenghitungPesanSendiri::KeLarik($hasil['Perkiraan']),
                'Ongkir' => $hasil['Ongkir']->KeString(), 'DiskonOngkir' => $hasil['DiskonOngkir']->KeString(), 'Total' => $hasil['Total']->KeString(),
                'Zona' => $hasil['Zona'] === null ? null : ['Nama' => $hasil['Zona']->Nama, 'EstimasiHariMin' => $hasil['Zona']->EstimasiHariMin, 'EstimasiHariMaks' => $hasil['Zona']->EstimasiHariMaks],
                'Voucher' => $hasil['Voucher'] === null ? null : ['Kode' => $hasil['Voucher']['Kode'], 'NamaPromo' => $hasil['Voucher']['NamaPromo']],
            ]);
        });
    }

    public function Pesan(string $slugTenant, TokoOnlinePermintaan $permintaan, BuatPesananOnline $buat): JsonResponse
    {
        $hashIp = hash_hmac('sha256', (string) $permintaan->ip(), (string) config('app.key'));

        return $this->DalamTenant($slugTenant, function () use ($permintaan, $buat, $hashIp): JsonResponse {
            [$pesanan, $baru] = self::DenganBatasVoucher($permintaan, fn (): array => $buat->Jalankan($this->penentu->WajibAktif((string) $permintaan->validated('Outlet')), $permintaan->validated(), $hashIp, $this->CariIdPembeli($permintaan)));

            return response()->json([
                'KodeAkses' => $pesanan->KodeAkses, 'Nomor' => $pesanan->Nomor,
                'Status' => $pesanan->Status->value, 'UrlStatus' => url('/'.request()->route('slugTenant').'/pesanan/'.$pesanan->KodeAkses),
            ], $baru ? 201 : 200);
        });
    }

    public function Status(string $slugTenant, string $kodeAkses): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($kodeAkses): ?array {
            $pesanan = PesananOnline::query()->with('Detail')->where('KodeAkses', strtoupper($kodeAkses))->first();
            if (! $pesanan instanceof PesananOnline) {
                return null;
            }
            $pengiriman = PengirimanPesanan::query()->where('IdPesananOnline', $pesanan->Id)->first();
            $namaKurir = $pengiriman?->IdKurir === null ? null : Kurir::query()->whereKey($pengiriman->IdKurir)->value('Nama');

            return [
                'Toko' => ['Nama' => app(NamaTampilUsaha::class)->UntukTenant($idTenant)],
                'Pesanan' => [
                    'Nomor' => $pesanan->Nomor, 'NamaPelanggan' => $pesanan->NamaPelanggan,
                    'JenisPemenuhan' => $pesanan->JenisPemenuhan->AmbilLabel(), 'Status' => $pesanan->Status->value,
                    'LabelStatus' => $pesanan->Status->AmbilLabel(), 'Total' => $pesanan->Total,
                    'Ongkir' => $pesanan->Ongkir, 'DiskonOngkir' => $pesanan->DiskonOngkir, 'DibuatPada' => $pesanan->DibuatPada?->toIso8601String(),
                    'MetodePembayaran' => $pesanan->MetodePembayaran->AmbilLabel(),
                    'PerluBayar' => $pesanan->Status === StatusPesananOnline::MenungguPembayaran,
                    'SudahDibayar' => $pesanan->DibayarPada !== null,
                    'JumlahDibayar' => $pesanan->JumlahDibayar,
                    'Baris' => $pesanan->Detail->map(fn ($d): array => ['Nama' => $d->NamaProduk, 'Jumlah' => $d->Jumlah, 'Total' => $d->TotalBaris])->values()->all(),
                    'Pengiriman' => $pengiriman === null ? null : [
                        'Status' => $pengiriman->Status->value,
                        'LabelStatus' => $pengiriman->Status->AmbilLabel(),
                        'NomorResi' => $pengiriman->NomorResi,
                        'Kurir' => is_string($namaKurir) ? $namaKurir : $pengiriman->NamaPenyedia,
                        'PerkiraanTibaPada' => $pengiriman->PerkiraanTibaPada?->toIso8601String(),
                    ],
                ],
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/StatusPesananOnline', ['Ditemukan' => $props !== null, 'Slug' => $slugTenant, 'KodeAkses' => strtoupper($kodeAkses), ...($props ?? [])])
            ->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    /**
     * Pelanggan meminta QRIS untuk pesanannya sendiri. Idempoten per pesanan: memuat ulang halaman bayar tidak pernah
     * membuat tagihan kedua, jadi satu pesanan tidak bisa dibayar dua kali.
     */
    public function Bayar(string $slugTenant, string $kodeAkses, BuatTagihanQrisPesananOnline $buat, PembuatQrTagihanQris $qr): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($kodeAkses, $buat, $qr): JsonResponse {
            $this->penentu->WajibAktif();
            [$tagihan, $baru] = $buat->Jalankan($this->WajibPesanan($kodeAkses));

            return response()->json(self::TagihanKeLarik($tagihan, $qr), $baru ? 201 : 200);
        });
    }

    /** Dipantau halaman bayar tiap beberapa detik; webhook gerbang yang memindahkan statusnya, bukan halaman ini. */
    public function StatusBayar(string $slugTenant, string $kodeAkses): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($kodeAkses): JsonResponse {
            $pesanan = $this->WajibPesanan($kodeAkses);
            $tagihan = TagihanQris::query()->where('IdPesananOnline', $pesanan->Id)->orderByDesc('Id')->first();

            return response()->json([
                'Status' => $pesanan->Status->value,
                'LabelStatus' => $pesanan->Status->AmbilLabel(),
                'SudahDibayar' => $pesanan->DibayarPada !== null,
                'StatusTagihan' => $tagihan?->Status->value,
                'KedaluwarsaPada' => $tagihan?->KedaluwarsaPada->toIso8601String(),
            ]);
        });
    }

    /** Logo toko untuk kepala halaman dan pratinjau tautan; 404 bila toko tak aktif atau belum punya logo. */
    public function Logo(string $slugTenant, Request $request, PengaturanStrukTenant $pengaturan, PenyimpanLogoTenant $penyimpan): StreamedResponse
    {
        $uuidDipilih = is_string($request->query('outlet')) ? strtoupper($request->query('outlet')) : null;

        return $this->DalamTenant($slugTenant, function (int $idTenant) use ($uuidDipilih, $pengaturan, $penyimpan): StreamedResponse {
            // Logo adalah aset merek publik: dipakai juga halaman reservasi & self-order yang outletnya belum tentu
            // membuka toko online, jadi outlet dicari dari yang aktif (terpilih dulu, lalu yang pertama).
            $idOutlet = ($uuidDipilih === null ? null : Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->where('Uuid', $uuidDipilih)->value('Id'))
                ?? Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->orderBy('Id')->value('Id');
            abort_if($idOutlet === null, 404);
            $path = $pengaturan->AmbilPathLogoUsaha($idTenant, (int) $idOutlet);
            abort_if($path === null, 404);

            return $penyimpan->UnduhPublik($path);
        }, fn (): never => abort(404));
    }

    public function Gambar(string $slugTenant, string $produk, Request $permintaan, MenuPesanSendiri $menu, PenyimpanGambarProduk $penyimpan): StreamedResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($produk, $permintaan, $menu, $penyimpan): StreamedResponse {
            $this->penentu->WajibAktif();
            $baris = $menu->CariProduk(strtoupper($produk), true);
            abort_if($baris === null, 404);

            return $penyimpan->Unduh($baris, $permintaan->query('ukuran') === 'besar' ? 'besar' : 'kecil');
        });
    }

    /** Pembeli yang sudah masuk (F-17 bagian 3); null = tamu, atau akun pembeli sedang dimatikan toko. */
    /**
     * v3.46: kode voucher bisa ditebak lewat rute publik, jadi kode yang salah dibatasi `MAKS_GAGAL_VOUCHER` kali per
     * 10 menit per IP (percobaan sah tidak dihitung). Galat voucher selain itu diteruskan apa adanya.
     *
     * @template T
     *
     * @param  Closure(): T  $kerja
     * @return T
     */
    private static function DenganBatasVoucher(TokoOnlinePermintaan $permintaan, Closure $kerja): mixed
    {
        $kode = $permintaan->validated('KodeVoucher');

        if (! is_string($kode) || $kode === '') {
            return $kerja();
        }

        $kunci = 'voucher-toko-online:'.hash_hmac('sha256', (string) $permintaan->ip(), (string) config('app.key'));

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL_VOUCHER)) {
            throw new PelanggaranAturanBisnis('TerlaluBanyakPercobaanVoucher', 'Terlalu banyak kode voucher yang salah. Coba lagi beberapa menit lagi.', 'KodeVoucher', 429);
        }

        try {
            return $kerja();
        } catch (PelanggaranAturanBisnis $galat) {
            if ($galat->kode === 'VoucherTidakDitemukan') {
                RateLimiter::hit($kunci, 600);
            }

            throw $galat;
        }
    }

    private function CariPembeli(Request $request): ?Pelanggan
    {
        return $this->sesi->CariPelanggan($request->cookie(SesiPembeliOnline::NAMA_COOKIE));
    }

    private function CariIdPembeli(Request $request): ?int
    {
        return $this->akun->CekAktif() ? $this->CariPembeli($request)?->Id : null;
    }

    private function WajibPesanan(string $kodeAkses): PesananOnline
    {
        return PesananOnline::query()->where('KodeAkses', strtoupper($kodeAkses))->first()
            ?? throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan tidak ditemukan.', 'Umum', 404);
    }

    /**
     * Gerbang yang memakai halaman bayar sendiri mengirim URL di `IsiQr`, bukan muatan QRIS; halaman bayar
     * mengarahkan pelanggan ke sana alih-alih menggambar QR yang tidak bisa dipindai.
     *
     * @return array<string, mixed>
     */
    private static function TagihanKeLarik(TagihanQris $tagihan, PembuatQrTagihanQris $qr): array
    {
        $lunas = $tagihan->Status === StatusTagihanQris::Lunas;

        return [
            'Jumlah' => $tagihan->Jumlah,
            'Status' => $tagihan->Status->value,
            'SudahDibayar' => $lunas,
            'KedaluwarsaPada' => $tagihan->KedaluwarsaPada->toIso8601String(),
            'UrlBayar' => $tagihan->HalamanBayar ? $tagihan->IsiQr : null,
            'Qr' => $tagihan->HalamanBayar || $lunas ? null : $qr->BuatSvg($tagihan->IsiQr),
        ];
    }

    private function DalamTenant(string $slugTenant, Closure $kerja, ?Closure $tidakDikenal = null): mixed
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);
        if ($idTenant === null) {
            return $tidakDikenal !== null ? $tidakDikenal() : throw new PelanggaranAturanBisnis('TokoTidakDitemukan', 'Toko tidak ditemukan.', 'Umum', 404);
        }
        $this->konteks->Atur($idTenant);
        try {
            return $kerja($idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
