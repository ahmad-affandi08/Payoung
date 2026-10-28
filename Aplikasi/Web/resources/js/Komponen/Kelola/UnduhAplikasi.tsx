import Panel from '@/Komponen/Kelola/Panel';
import { Button } from '@/Komponen/Ui/button';
import type { TautanUnduhAplikasi } from '@/Tipe/Aplikasi';

type PropsUnduhAplikasi = {
    /** Dari `PenyusunUnduhanAplikasi` (P-10). Kosong = tombol unduh disembunyikan, bukan kotak kosong. */
    Unduhan: TautanUnduhAplikasi[];
};

/**
 * v7.35 (F-02b langkah 5, P-10): tempat mengunduh aplikasi kasir di halaman Perangkat & langkah Perangkat panduan
 * awal. Halaman itu meminta kode aktivasi, padahal aplikasinya belum tentu terpasang — dulu tidak ada satu pun
 * tautan unduh di back-office, jadi pemilik usaha harus mencari sendiri di Play Store.
 *
 * Tombol hanya untuk platform yang benar-benar punya tautan dari server. QR ditampilkan di layar lebar supaya
 * pemasangan di HP bisa langsung dipindai dari komputer, dan disembunyikan di HP karena tidak bisa memindai layarnya
 * sendiri. Tautan memakai `Button asChild` (tautan, bukan aksi bisnis) dengan ukuran yang sama dengan `Tombol`.
 */
export default function UnduhAplikasi({ Unduhan }: PropsUnduhAplikasi) {
    if (Unduhan.length === 0) {
        return null;
    }

    const qr = Unduhan.find((baris) => baris.QrSvg !== null) ?? null;
    const versi = Unduhan[0]?.Versi ?? '';

    return (
        <Panel
            judul="Aplikasi kasir"
            keterangan="Pasang di HP, tablet, atau komputer kasir. Setelah terpasang, aktifkan perangkat dengan kode dari halaman ini."
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        {Unduhan.map((baris) => (
                            <Button
                                key={baris.Platform}
                                asChild
                                variant="outline"
                                className="h-8 px-4 text-label font-semibold pointer-coarse:h-11"
                            >
                                <a href={baris.Url} target="_blank" rel="noopener noreferrer">
                                    Unduh untuk {baris.Label}
                                </a>
                            </Button>
                        ))}
                    </div>
                    {versi !== '' ? (
                        <span className="text-keterangan text-teks-sekunder">Versi terbaru {versi}</span>
                    ) : null}
                </div>
                {qr?.QrSvg ? (
                    <figure className="hidden shrink-0 flex-col items-center gap-1 sm:flex">
                        <img
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qr.QrSvg)}`}
                            alt={`QR unduh aplikasi kasir untuk ${qr.Label}`}
                            width={120}
                            height={120}
                            className="rounded-kontrol border border-garis bg-permukaan"
                        />
                        <figcaption className="text-keterangan text-teks-sekunder">Pindai dengan HP</figcaption>
                    </figure>
                ) : null}
            </div>
        </Panel>
    );
}
