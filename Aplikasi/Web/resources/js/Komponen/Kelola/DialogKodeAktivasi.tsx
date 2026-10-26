import DialogRahasia, { NilaiRahasia } from '@/Komponen/Umpan/DialogRahasia';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { KodeAktivasiBaru } from '@/Tipe/PanduanAwal';

/** Kode aktivasi 8 karakter + QR untuk perangkat baru (F-02b BR-02.3), dipakai di halaman Perangkat dan langkah 6 F-01. */
export default function DialogKodeAktivasi({ kode, saatSelesai }: { kode: KodeAktivasiBaru; saatSelesai: () => void }) {
    const sumberQr = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(kode.QrSvg)}`;

    return (
        <DialogRahasia
            judul={`Aktifkan ${kode.NamaPerangkat} (${kode.KodePerangkat})`}
            peringatan={
                <>
                    Kode berlaku sampai {FormatTanggalWaktu(kode.KedaluwarsaPada)} dan hanya bisa dipakai sekali.
                    Biarkan dialog ini terbuka sampai perangkat selesai diaktifkan. Setelah ditutup kodenya tidak bisa
                    dilihat lagi, dan Anda perlu membuat kode baru. Kode lama untuk perangkat ini (bila ada) tidak
                    berlaku lagi.
                </>
            }
            saatSelesai={saatSelesai}
        >
            <p>
                Buka aplikasi kasir, pilih &quot;Aktifkan perangkat&quot;, lalu pindai QR ini atau ketik kodenya.
                Perangkat Windows tidak punya pemindai, jadi kodenya diketik:
            </p>
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
