<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;

/**
 * Identitas teknis seorang petugas pengelola di sisi tenant (PGL-16): satu `Pengguna` tanpa kata sandi yang bisa
 * dipakai masuk dan tanpa keanggotaan tenant mana pun. Hak aksesnya hanya ada selama sesi akses dukungan yang sah.
 * Tabel global (bukan milik tenant).
 *
 * @property int $Id
 * @property int $IdPenggunaPengelola
 * @property int $IdPengguna
 */
final class PenggunaDukungan extends ModelDasar
{
    protected $table = 'PenggunaDukungan';

    protected bool $pakaiUuid = false;
}
