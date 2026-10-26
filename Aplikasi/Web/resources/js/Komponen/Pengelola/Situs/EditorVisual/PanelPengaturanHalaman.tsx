import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';

import PemilihGambarSitus from '../PemilihGambarSitus';
import type { Draf } from './Tipe';

type PropsPanelPengaturanHalaman = {
    terbuka: boolean;
    saatTutup: () => void;
    draf: Draf;
    saatUbah: (bagian: Partial<Draf>, gabung?: boolean) => void;
    galat: Record<string, string>;
    bawaan: boolean;
    jalurAsli: string;
    namaSitus: string;
    bolehUbah: boolean;
};

/** Panel samping "Pengaturan halaman": judul, alamat, SEO, dan pratinjau tampilan di hasil pencarian Google. */
export default function PanelPengaturanHalaman({
    terbuka,
    saatTutup,
    draf,
    saatUbah,
    galat,
    bawaan,
    jalurAsli,
    namaSitus,
    bolehUbah,
}: PropsPanelPengaturanHalaman) {
    const judulGoogle = draf.JudulSeo !== '' ? draf.JudulSeo : `${draf.Judul} | ${namaSitus}`;
    const jalur = draf.Slug === 'beranda' ? '' : `/${draf.Slug}`;

    return (
        <Sheet open={terbuka} onOpenChange={(buka) => (buka ? undefined : saatTutup())}>
            <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle className="text-judul font-semibold text-teks-utama">Pengaturan halaman</SheetTitle>
                    <SheetDescription className="text-isi text-teks-sekunder">
                        Judul, alamat, dan tampilan halaman ini di Google atau saat tautannya dibagikan.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-4 px-4 pb-6">
                    <BidangTeks
                        label="Judul halaman"
                        nilai={draf.Judul}
                        saatBerubah={(v) => saatUbah({ Judul: v }, true)}
                        galat={galat.Judul}
                        maxLength={150}
                        disabled={!bolehUbah}
                        required
                    />
                    <BidangTeks
                        label="Alamat halaman"
                        kode
                        keterangan={bawaan ? 'Halaman bawaan. Alamat tetap.' : 'Huruf kecil, angka, tanda hubung.'}
                        nilai={draf.Slug}
                        saatBerubah={(v) => saatUbah({ Slug: v.toLowerCase() }, true)}
                        galat={galat.Slug}
                        maxLength={100}
                        disabled={bawaan || !bolehUbah}
                        required
                    />
                    <div
                        className="rounded-panel border border-garis bg-latar p-3"
                        aria-label="Contoh tampilan di Google"
                    >
                        <p className="mb-1 text-keterangan font-semibold text-teks-sekunder">Tampilan di Google</p>
                        <p className="truncate text-keterangan text-teks-sekunder">
                            {jalurAsli.replace(/\/$/, '')}
                            {jalur}
                        </p>
                        <p className="truncate text-subjudul font-semibold text-info">{judulGoogle}</p>
                        <p className="line-clamp-2 text-isi text-teks-sekunder">
                            {draf.DeskripsiSeo !== '' ? draf.DeskripsiSeo : 'Deskripsi umum situs akan dipakai.'}
                        </p>
                    </div>
                    <BidangTeks
                        label="Judul di Google (opsional)"
                        keterangan={`${String(draf.JudulSeo.length)}/70. Kosong = judul halaman | nama situs.`}
                        nilai={draf.JudulSeo}
                        saatBerubah={(v) => saatUbah({ JudulSeo: v }, true)}
                        galat={galat.JudulSeo}
                        maxLength={70}
                        disabled={!bolehUbah}
                    />
                    <BidangTeksPanjang
                        label="Deskripsi di Google (opsional)"
                        keterangan={`${String(draf.DeskripsiSeo.length)}/170. Kosong = deskripsi umum situs.`}
                        nilai={draf.DeskripsiSeo}
                        saatBerubah={(v) => saatUbah({ DeskripsiSeo: v }, true)}
                        galat={galat.DeskripsiSeo}
                        maksimal={170}
                        baris={3}
                    />
                    <PemilihGambarSitus
                        label="Gambar saat dibagikan (opsional)"
                        keterangan="Tampil di WhatsApp/Facebook saat tautan dibagikan. Ukuran ideal 1200×630."
                        nilai={draf.UuidGambarOg}
                        saatBerubah={(v) => saatUbah({ UuidGambarOg: v })}
                        galat={galat.UuidGambarOg}
                        bolehUbah={bolehUbah}
                    />
                    <KotakCentang
                        label="Tampilkan di peta situs (/peta-situs) untuk mesin pencari"
                        nilai={draf.TampilDiSitemap}
                        saatBerubah={(v) => saatUbah({ TampilDiSitemap: v })}
                    />
                </div>
            </SheetContent>
        </Sheet>
    );
}
