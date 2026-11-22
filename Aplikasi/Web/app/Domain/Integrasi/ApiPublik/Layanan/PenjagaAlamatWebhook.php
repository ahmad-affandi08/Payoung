<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\ApiPublik\Layanan;

use Closure;

/**
 * Penjaga SSRF webhook keluar (X7 bagian 2): alamat wajib `https://`, tanpa kredensial di URL, dan setiap IP hasil DNS
 * harus IP publik (bukan loopback, privat, link-local/metadata awan, atau cadangan). Dipanggil saat webhook dibuat dan
 * lagi tepat sebelum tiap kiriman; IP yang lolos dipin ke cURL agar DNS tidak bisa diganti di antara pemeriksaan dan
 * koneksi (DNS rebinding).
 */
final class PenjagaAlamatWebhook
{
    /** @var Closure(string): list<string> */
    private readonly Closure $penyelesaiDns;

    /** @param  (Closure(string): list<string>)|null  $penyelesaiDns  pengganti DNS untuk test */
    public function __construct(?Closure $penyelesaiDns = null)
    {
        $this->penyelesaiDns = $penyelesaiDns ?? static function (string $host): array {
            $ipv4 = gethostbynamel($host) ?: [];
            $ipv6 = array_values(array_filter(array_map(
                static fn (array $r): ?string => is_string($r['ipv6'] ?? null) ? $r['ipv6'] : null,
                @dns_get_record($host, DNS_AAAA) ?: [],
            )));

            return [...$ipv4, ...$ipv6];
        };
    }

    /**
     * @return array{Aman: true, Host: string, Port: int, Ip: string}|array{Aman: false, Alasan: string}
     */
    public function Periksa(string $url): array
    {
        $bagian = parse_url($url);

        if (! is_array($bagian) || strtolower((string) ($bagian['scheme'] ?? '')) !== 'https' || ! isset($bagian['host'])) {
            return ['Aman' => false, 'Alasan' => 'Alamat webhook wajib diawali https:// dan memuat nama host.'];
        }

        if (isset($bagian['user']) || isset($bagian['pass'])) {
            return ['Aman' => false, 'Alasan' => 'Alamat webhook tidak boleh memuat nama pengguna atau kata sandi.'];
        }

        $host = strtolower(trim($bagian['host'], '[]'));
        $port = (int) ($bagian['port'] ?? 443);

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return ['Aman' => false, 'Alasan' => 'Alamat webhook harus alamat publik di internet.'];
        }

        $daftarIp = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->penyelesaiDns)($host);

        if ($daftarIp === []) {
            return ['Aman' => false, 'Alasan' => 'Nama host alamat webhook tidak ditemukan.'];
        }

        foreach ($daftarIp as $ip) {
            if (! self::CekIpPublik($ip)) {
                return ['Aman' => false, 'Alasan' => 'Alamat webhook harus alamat publik di internet.'];
            }
        }

        return ['Aman' => true, 'Host' => $host, 'Port' => $port, 'Ip' => $daftarIp[0]];
    }

    public static function CekIpPublik(string $ip): bool
    {
        // IPv6 yang menyematkan IPv4 (::ffff:a.b.c.d maupun heksadesimal ::ffff:7f00:1, NAT64 64:ff9b::/96, 6to4 2002::/16,
        // kompatibel ::/96) diperiksa sebagai IPv4-nya. Memakai bentuk biner supaya semua penulisan setara.
        $biner = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? @inet_pton($ip) : false;

        if (is_string($biner) && strlen($biner) === 16) {
            $awal80 = substr($biner, 0, 10) === str_repeat("\0", 10);
            $tersemat = null;

            if ($awal80 && (substr($biner, 10, 2) === "\xff\xff" || substr($biner, 10, 2) === "\0\0")) {
                $tersemat = substr($biner, 12, 4);
            } elseif (str_starts_with($biner, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
                $tersemat = substr($biner, 12, 4);
            } elseif (str_starts_with($biner, "\x20\x02")) {
                $tersemat = substr($biner, 2, 4);
            }

            if ($tersemat !== null) {
                $ipv4 = inet_ntop($tersemat);

                return $ipv4 !== false && self::CekIpPublik($ipv4);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Rentang yang tidak ditolak flag PHP: CGNAT 100.64/10, benchmark 198.18/15, IPv6 ULA fc00::/7 & link-local fe80::/10.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $angka = (int) ip2long($ip);

            return ! (($angka & 0xFFC00000) === (int) ip2long('100.64.0.0') || ($angka & 0xFFFE0000) === (int) ip2long('198.18.0.0'));
        }

        $awal = strtolower(substr($ip, 0, 4));

        return ! (str_starts_with($awal, 'fc') || str_starts_with($awal, 'fd') || preg_match('/^fe[89ab]/', $awal) === 1);
    }
}
