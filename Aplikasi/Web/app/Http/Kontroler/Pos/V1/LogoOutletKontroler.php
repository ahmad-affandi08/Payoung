<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Layanan\PenyimpanLogoTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/pos/v1/logo-outlet` (D-16, §17.2.7): logo merek outlet untuk bilah atas Ruang Kerja Kasir (disimpan
 * offline di perangkat). Logo outlet menimpa logo usaha; berbeda dari `/logo-struk`, saklar `TampilkanLogo` di
 * pengaturan struk **tidak** berlaku — bilah atas selalu memakai logo kalau ada. Tanpa logo = 404.
 */
final class LogoOutletKontroler extends Kontroler
{
    public function Unduh(Request $permintaan, PengaturanStrukTenant $pengaturan, PenyimpanLogoTenant $penyimpan, OutletPenjualan $outlet): StreamedResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $path = $pengaturan->AmbilPathLogoUsaha($perangkat->IdTenant, $outlet->Ambil($perangkat->IdOutlet, $perangkat->Id)?->idOutlet);
        abort_if($path === null, 404);

        return $penyimpan->Unduh($path);
    }
}
