import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { AmbilKodeGalat, KirimJson } from '@/Pustaka/PermintaanJson';

/** Kode galat server saat aksi berbahaya butuh kode 2FA baru (`WajibDuaFaktorBaru::KODE_GALAT`). */
export const KODE_PERLU_KONFIRMASI = 'PerluKonfirmasiDuaFaktor';

type KunjunganTertunda = Parameters<typeof router.visit>[1] & { url: URL | string };

function BacaIsiGalat(data: unknown): unknown {
    if (typeof data !== 'string') {
        return data;
    }

    try {
        return JSON.parse(data) as unknown;
    } catch {
        return null;
    }
}

/**
 * D-42: aksi berbahaya di konsol meminta kode 2FA baru bila kode terakhir sudah lewat 15 menit. Dulu anggota
 * dilempar ke halaman lain lalu isiannya hilang; kini server menjawab 423, dialog kode muncul di atas halaman yang
 * sama, dan setelah kode diterima permintaan yang tadi dikirim ulang apa adanya (isian form tetap utuh).
 */
export default function DialogKonfirmasiDuaFaktor() {
    const tertunda = useRef<KunjunganTertunda | null>(null);
    const [terbuka, AturTerbuka] = useState(false);
    const [kode, AturKode] = useState('');
    const [galat, AturGalat] = useState<string | undefined>(undefined);
    const [memproses, AturMemproses] = useState(false);

    useEffect(() => {
        const LepasSebelum = router.on('before', (peristiwa) => {
            const kunjungan = peristiwa.detail.visit;

            if (kunjungan.method !== 'get') {
                tertunda.current = kunjungan;
            }
        });
        const LepasGalat = router.on('httpException', (peristiwa) => {
            const { response } = peristiwa.detail;

            if (response.status !== 423 || AmbilKodeGalat(BacaIsiGalat(response.data)) !== KODE_PERLU_KONFIRMASI) {
                return;
            }

            // Tanpa ini Inertia menampilkan jawaban mentah di dialog bawaannya.
            peristiwa.preventDefault();
            AturKode('');
            AturGalat(undefined);
            AturTerbuka(true);
        });

        return () => {
            LepasSebelum();
            LepasGalat();
        };
    }, []);

    const Tutup = () => {
        AturTerbuka(false);
        tertunda.current = null;
    };

    const Kirim = async (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturMemproses(true);
        AturGalat(undefined);

        try {
            await KirimJson('/dua-faktor/konfirmasi', { Kode: kode });
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Kode belum bisa diperiksa. Coba lagi.');
            AturMemproses(false);

            return;
        }

        const kunjungan = tertunda.current;
        AturMemproses(false);
        AturTerbuka(false);
        tertunda.current = null;

        if (kunjungan !== null) {
            router.visit(kunjungan.url, { ...kunjungan });
        }
    };

    if (!terbuka) {
        return null;
    }

    return (
        <DialogFormulir
            judul="Konfirmasi dengan kode"
            keterangan="Aksi ini penting, jadi kami minta kode dari aplikasi autentikator sekali lagi. Setelah itu aksi tadi langsung dilanjutkan dan isian Anda tetap ada."
            galatUmum={galat}
            saatTutup={Tutup}
        >
            <form onSubmit={(peristiwa) => void Kirim(peristiwa)} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Kode"
                    kode
                    autoComplete="one-time-code"
                    maxLength={11}
                    nilai={kode}
                    saatBerubah={AturKode}
                    autoFocus
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses}>
                        Konfirmasi dan lanjutkan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={Tutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
