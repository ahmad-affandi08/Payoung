<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\AnalitikSitus\Layanan;

use RuntimeException;

/** Kegagalan membaca Google Analytics. Pesannya sudah aman ditampilkan (tidak memuat kunci atau token). */
final class GalatAnalitikGoogle extends RuntimeException {}
