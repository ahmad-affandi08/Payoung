import DialogRahasia, { NilaiRahasia } from '@/Komponen/Umpan/DialogRahasia';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { TautanUnduhAplikasi } from '@/Tipe/Aplikasi';
import type { KodeAktivasiBaru } from '@/Tipe/PanduanAwal';

/**
 * Kode aktivasi 8 karakter + QR untuk perangkat baru (F-02b BR-02.3), dipakai di halaman Perangkat dan langkah 6 F-01.
 * v7.35: bila aplikasinya belum terpasang, tautan unduhnya ada di dialog ini — kode aktivasi tidak berguna tanpa
 * aplikasi, dan dialog inilah tempat kasir baru pertama kali dibuat.
 */
export default function DialogKodeAktivasi({
    kode,
    Unduhan,
    saatSelesai,
}: {
    kode: KodeAktivasiBaru;
    Unduhan: TautanUnduhAplikasi[];
    saatSelesai: () => void;
}) {
    const sumberQr = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(kode.QrSvg)}`;

    return (
        <DialogRahasia
            judul={`Aktifkan ${kode.NamaPerangkat} (${kode.KodePerangkat})`}
            peringatan={
                <>
                    Kode berlaku sampai {FormatTanggalWaktu(kode.KedaluwarsaPada)} dan hanya bisa dipakai sekali.
                    Biarkan dialog ini terbuka sampai perangkat aktif, karena kode tidak bisa dilihat lagi setelah
                    ditutup.
                </>
            }
            saatSelesai={saatSelesai}
        >
            <p>
                Buka aplikasi kasir, pilih Aktifkan perangkat, lalu pindai QR ini atau ketik kodenya. Di Windows, ketik
                kodenya:
            </p>
            {Unduhan.length > 0 ? (
                <p>
                    Belum punya aplikasi kasirnya? Unduh untuk{' '}
                    {Unduhan.map((baris, indeks) => (
                        <span key={baris.Platform}>
                            <a
                                href={baris.Url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-semibold text-brand underline"
                            >
                                {baris.Label}
                            </a>
                            {indeks < Unduhan.length - 1 ? ' atau ' : ''}
                        </span>
                    ))}
                    .
                </p>
            ) : null}
            <img
                src={sumberQr}
                alt={`Kode QR aktivasi ${kode.NamaPerangkat}`}
                width={176}
                height={176}
                className="mx-auto shrink-0 rounded-kontrol border border-garis bg-permukaan"
            />
            {/*
             * Ditampilkan apa adanya tanpa tanda hubung supaya persis sama dengan yang diketik dan dengan isi QR;
             * keterbacaannya dijaga jarak huruf, bukan pemisah yang ikut tersalin.
             */}
            <NilaiRahasia nilai={kode.Kode} jenis="kode" besar />
        </DialogRahasia>
    );
}
