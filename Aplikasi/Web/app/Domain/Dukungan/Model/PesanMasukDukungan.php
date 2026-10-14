<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Catatan satu pesan masuk (email/WhatsApp) yang sudah diproses menjadi tiket baru, balasan, atau penolakan (PGL-15).
 * Kunci unik (`Kanal`, `IdPesan`) membuat pengiriman ulang penyedia idempoten. Sengaja tanpa isi pesan, pengirim, dan
 * tenant: isi pesan hanya ada di `TiketDukunganPesan`.
 *
 * @property int $Id
 * @property string $Kanal
 * @property string $IdPesan
 * @property string $Hasil
 * @property string|null $KodeTolak
 * @property string|null $NomorTiket
 * @property Carbon|null $DibuatPada
 */
final class PesanMasukDukungan extends ModelDasar
{
    public const HASIL_TIKET_BARU = 'TiketBaru';

    public const HASIL_BALASAN = 'Balasan';

    public const HASIL_DITOLAK = 'Ditolak';

    protected $table = 'PesanMasukDukungan';

    protected bool $pakaiUuid = false;
}
