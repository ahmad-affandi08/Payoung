import { usePage } from '@inertiajs/react';
import { Check, ChevronDown, Clock, Mail, MapPin, MessageCircle, Phone, Smartphone } from 'lucide-react';

import gambarGooglePlay from '@/Aset/Situs/GooglePlay.png';
import PanggungSpesimen from '@/Komponen/Situs/PanggungSpesimen';
import { SPESIMEN } from '@/Komponen/Situs/SpesimenSitus';
import TeksKaya from '@/Komponen/Situs/TeksKaya';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import type { BagianSitus, DataSitus } from '@/Tipe/Situs';

import { CekGelap, GambarBagian, KelasKartu, KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

/** Kotak ikon berwarna untuk ikon lucide langsung (kartu kontak & unduh), nada sama dengan {@link ChipIkon} (D-93). */
const NADA_KARTU = [
    'bg-sukses text-permukaan',
    'bg-brand text-brand-teks',
    'bg-info text-permukaan',
    'bg-aksen text-teks-utama',
] as const;

function IkonKartu({ ikon: Ikon, nada }: { ikon: typeof Mail; nada: 0 | 1 | 2 | 3 }) {
    return (
        <span className={cn('flex size-12 shrink-0 items-center justify-center rounded-2xl', NADA_KARTU[nada])}>
            <Ikon className="size-6" aria-hidden />
        </span>
    );
}

type PropsBagian<J extends BagianSitus['Jenis']> = {
    bagian: Extract<BagianSitus, { Jenis: J }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
};

/** Gambar di satu sisi, teks + poin + tombol di sisi lain. */
export function BagianGambarTeks({ bagian, latar, garisAtas }: PropsBagian<'GambarTeks'>) {
    const gambarKiri = bagian.PosisiGambar === 'Kiri';
    const gelap = CekGelap(latar);
    // Spesimen keluaran produk dipakai sebagai jangkar visual selama belum ada gambar (D-25).
    const Spesimen = !bagian.Gambar && bagian.Spesimen ? SPESIMEN[bagian.Spesimen] : null;
    const adaVisual = Boolean(bagian.Gambar) || Spesimen !== null;

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <div className={cn('grid items-center gap-10', adaVisual ? 'lg:grid-cols-2' : 'max-w-3xl')}>
                <div className={cn('flex flex-col gap-4', gambarKiri && 'lg:order-2')}>
                    <KepalaBagian
                        label={bagian.Label}
                        judul={bagian.Judul}
                        subjudul={bagian.Subjudul}
                        gelap={gelap}
                        rata="kiri"
                    />
                    {bagian.Teks ? (
                        <TeksKaya
                            teks={bagian.Teks}
                            className={cn(
                                'text-subjudul -mt-6',
                                gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                            )}
                        />
                    ) : null}
                    {bagian.Poin.length > 0 ? (
                        <ul className="flex flex-col gap-3">
                            {bagian.Poin.map((poin, i) => (
                                <li
                                    key={`${poin.Teks}-${i}`}
                                    className={cn(
                                        'text-subjudul flex gap-3',
                                        gelap ? 'text-permukaan' : 'text-teks-utama',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full',
                                            gelap ? 'bg-aksen text-teks-utama' : 'bg-sukses-lembut text-sukses',
                                        )}
                                    >
                                        <Check className="size-4" strokeWidth={3} aria-hidden />
                                    </span>
                                    <span>{poin.Teks}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {bagian.Tombol ? (
                        <TombolSitus
                            href={bagian.Tombol.Tautan}
                            varian={gelap ? 'aksen' : 'utama'}
                            className="self-start"
                        >
                            {bagian.Tombol.Label}
                        </TombolSitus>
                    ) : null}
                </div>
                {bagian.Gambar ? (
                    <GambarBagian gambar={bagian.Gambar} className="h-auto w-full rounded-3xl" />
                ) : Spesimen ? (
                    <PanggungSpesimen warna={gelap ? 'teal' : latar === 'aksen' ? 'teal' : 'aksen'}>
                        <Spesimen />
                    </PanggungSpesimen>
                ) : null}
            </div>
        </WadahBagian>
    );
}

/** Tanya jawab dengan `<details>` bawaan (bisa dibuka tanpa JavaScript, terbaca mesin pencari). */
export function BagianFaq({ bagian, latar, garisAtas }: PropsBagian<'Faq'>) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} id="faq" sempit garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <div className="flex flex-col gap-3">
                {bagian.Item.map((item, i) => (
                    <details
                        key={`${item.Pertanyaan}-${i}`}
                        className={cn(
                            'group rounded-2xl border px-5',
                            gelap ? 'border-brand-gelap-garis' : 'border-garis bg-permukaan open:border-brand',
                        )}
                    >
                        <summary
                            className={cn(
                                'text-subjudul flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-4 font-semibold [&::-webkit-details-marker]:hidden',
                                gelap ? 'text-permukaan' : 'text-teks-utama',
                            )}
                        >
                            {item.Pertanyaan}
                            <span
                                className={cn(
                                    'flex size-8 shrink-0 items-center justify-center rounded-full',
                                    gelap ? 'bg-brand-gelap-sorot' : 'bg-aksen-lembut text-peringatan',
                                )}
                            >
                                <ChevronDown
                                    className="size-5 transition-transform group-open:rotate-180"
                                    aria-hidden
                                />
                            </span>
                        </summary>
                        <TeksKaya
                            teks={item.Jawaban}
                            className={cn('text-isi pb-5', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}
                        />
                    </details>
                ))}
            </div>
        </WadahBagian>
    );
}

/**
 * Ajakan penutup (D-93): pita apricot penuh dengan teks tinta gelap (kontras 7:1) dan dua lingkaran padat sebagai
 * dekorasi. Satu-satunya bagian yang memang dibaca sebagai pengumuman, jadi rata tengah.
 */
export function BagianCta({ bagian }: { bagian: Extract<BagianSitus, { Jenis: 'Cta' }> }) {
    return (
        <section className="relative isolate overflow-hidden bg-aksen py-16 sm:py-24">
            <span aria-hidden className="absolute -top-24 -left-16 -z-10 size-72 rounded-full bg-permukaan/25" />
            <span aria-hidden className="absolute -right-20 -bottom-28 -z-10 size-80 rounded-full bg-brand/25" />
            <div className="muncul-saat-gulir mx-auto flex max-w-3xl flex-col items-center gap-5 px-4 text-center">
                <h2 className="text-judul-bagian-hp font-bold text-teks-utama sm:text-judul-bagian">{bagian.Judul}</h2>
                {bagian.Teks ? (
                    <p className="text-pengantar whitespace-pre-line text-teks-utama">{bagian.Teks}</p>
                ) : null}
                <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                    {bagian.TombolUtama ? (
                        <TombolSitus href={bagian.TombolUtama.Tautan} ukuran="besar" varian="tinta">
                            {bagian.TombolUtama.Label}
                        </TombolSitus>
                    ) : null}
                    {bagian.TombolKedua ? (
                        <TombolSitus href={bagian.TombolKedua.Tautan} ukuran="besar" varian="garis-tinta">
                            {bagian.TombolKedua.Label}
                        </TombolSitus>
                    ) : null}
                </div>
            </div>
        </section>
    );
}

/** Teks panjang (tentang kami, kebijakan singkat, artikel sederhana). */
export function BagianTeksBebas({ bagian, latar, garisAtas }: PropsBagian<'TeksBebas'>) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} sempit garisAtas={garisAtas}>
            <KepalaBagian
                label={bagian.Label}
                judul={bagian.Judul}
                subjudul={bagian.Subjudul}
                gelap={gelap}
                rata="kiri"
            />
            <TeksKaya
                teks={bagian.Isi}
                className={cn('text-subjudul', gelap ? 'text-brand-gelap-teks' : 'text-teks-utama')}
            />
        </WadahBagian>
    );
}

/** Video YouTube lewat youtube-nocookie (tanpa cookie pelacak sampai diputar). */
export function BagianVideo({ bagian, latar, garisAtas }: PropsBagian<'Video'>) {
    if (!bagian.IdYoutube) {
        return null;
    }

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian
                label={bagian.Label}
                judul={bagian.Judul}
                subjudul={bagian.Subjudul}
                gelap={CekGelap(latar)}
            />
            <div className="aspect-video w-full overflow-hidden rounded-panel border border-garis bg-teks-utama">
                <iframe
                    src={`https://www.youtube-nocookie.com/embed/${bagian.IdYoutube}`}
                    title={bagian.Judul ?? 'Video'}
                    loading="lazy"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                    referrerPolicy="strict-origin-when-cross-origin"
                    className="size-full"
                />
            </div>
        </WadahBagian>
    );
}

const PLATFORM_UNDUH = [
    { Kunci: 'Android', Label: 'Unduh di Google Play', Keterangan: 'Android 8 ke atas' },
    { Kunci: 'Ios', Label: 'Unduh di App Store', Keterangan: 'iPhone & iPad' },
    { Kunci: 'Windows', Label: 'Unduh untuk Windows', Keterangan: 'Windows 10 ke atas' },
] as const;

/** Tautan unduh aplikasi dari pengaturan situs; platform tanpa tautan tidak ditampilkan. */
export function BagianUnduhAplikasi({ bagian, latar, garisAtas }: PropsBagian<'UnduhAplikasi'>) {
    const { props } = usePage<{ Situs: DataSitus }>();
    const tersedia = PLATFORM_UNDUH.filter((p) => props.Situs.TautanUnduh[p.Kunci]);
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            {tersedia.length === 0 ? (
                <div className={cn('flex items-start gap-4', KelasKartu(latar))}>
                    <IkonKartu ikon={Smartphone} nada={1} />
                    <p className={cn('text-isi', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
                        Tautan unduhan segera tersedia. Hubungi kami untuk mendapatkan aplikasinya.
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col items-stretch gap-3 sm:flex-row sm:items-start">
                    {tersedia.map((p) => (
                        <li key={p.Kunci} className="flex flex-col gap-1">
                            {/* Badge resmi Google Play (versi Indonesia) untuk Android: paling dikenal pengguna HP,
                                dan Google mewajibkan badge resmi dipakai apa adanya. Platform lain tetap tombol situs. */}
                            {p.Kunci === 'Android' ? (
                                <a
                                    href={props.Situs.TautanUnduh[p.Kunci] ?? '#'}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Dapatkan aplikasi Payoung di Google Play"
                                    className="inline-flex w-fit rounded-lg focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <img
                                        src={gambarGooglePlay}
                                        alt="Dapatkan di Google Play"
                                        width={646}
                                        height={250}
                                        loading="lazy"
                                        className="h-12 w-auto"
                                    />
                                </a>
                            ) : (
                                <TombolSitus
                                    href={props.Situs.TautanUnduh[p.Kunci] ?? '#'}
                                    ukuran="besar"
                                    varian={gelap ? 'terang' : 'utama'}
                                    className="w-full sm:w-auto"
                                >
                                    {p.Label}
                                </TombolSitus>
                            )}
                            <span className={cn('text-label', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
                                {p.Keterangan}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </WadahBagian>
    );
}

/** Kartu kontak dari pengaturan situs. */
export function BagianKontak({ bagian, latar, garisAtas }: PropsBagian<'Kontak'>) {
    const { props } = usePage<{ Situs: DataSitus }>();
    const k = props.Situs.Kontak;
    const gelap = CekGelap(latar);
    const kartu = cn('flex gap-4', KelasKartu(latar));
    const judulKartu = cn('text-subjudul font-semibold', gelap ? 'text-permukaan' : 'text-teks-utama');
    const tautanKartu = cn('text-isi font-semibold underline', gelap ? 'text-permukaan' : 'text-brand');
    const ada = k.TautanWhatsApp || k.Email || k.Telepon || k.Alamat;

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            {!ada ? (
                <div className={cn('flex items-start gap-4', KelasKartu(latar))}>
                    <IkonKartu ikon={MessageCircle} nada={0} />
                    <p className={cn('text-isi', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
                        Kontak sedang disiapkan. Sementara itu, tinggalkan nomor WhatsApp Anda di formulir demo.
                    </p>
                </div>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2">
                    {k.TautanWhatsApp ? (
                        <li className={kartu}>
                            <IkonKartu ikon={MessageCircle} nada={0} />
                            <div className="flex flex-col gap-2">
                                <h3 className={judulKartu}>WhatsApp</h3>
                                {k.WhatsApp ? (
                                    <p
                                        className={cn(
                                            'text-isi',
                                            gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                                        )}
                                    >
                                        {k.WhatsApp}
                                    </p>
                                ) : null}
                                <TombolSitus
                                    href={k.TautanWhatsApp}
                                    varian={gelap ? 'terang' : 'utama'}
                                    className="self-start"
                                >
                                    Chat sekarang
                                </TombolSitus>
                            </div>
                        </li>
                    ) : null}
                    {k.Email ? (
                        <li className={kartu}>
                            <IkonKartu ikon={Mail} nada={1} />
                            <div className="flex flex-col gap-1">
                                <h3 className={judulKartu}>Email</h3>
                                <a href={`mailto:${k.Email}`} className={cn(tautanKartu, 'break-all')}>
                                    {k.Email}
                                </a>
                            </div>
                        </li>
                    ) : null}
                    {k.Telepon ? (
                        <li className={kartu}>
                            <IkonKartu ikon={Phone} nada={2} />
                            <div className="flex flex-col gap-1">
                                <h3 className={judulKartu}>Telepon</h3>
                                <a href={`tel:${k.Telepon.replace(/[^\d+]/g, '')}`} className={tautanKartu}>
                                    {k.Telepon}
                                </a>
                            </div>
                        </li>
                    ) : null}
                    {k.Alamat ? (
                        <li className={kartu}>
                            <IkonKartu ikon={MapPin} nada={3} />
                            <div className="flex flex-col gap-1">
                                <h3 className={judulKartu}>Alamat</h3>
                                <p
                                    className={cn(
                                        'text-isi whitespace-pre-line',
                                        gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                                    )}
                                >
                                    {k.Alamat}
                                </p>
                            </div>
                        </li>
                    ) : null}
                </ul>
            )}
            {k.JamLayanan ? (
                <p
                    className={cn(
                        'text-isi mt-6 flex items-center gap-2',
                        gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                    )}
                >
                    <Clock className="size-4" aria-hidden /> Jam layanan: {k.JamLayanan}
                </p>
            ) : null}
        </WadahBagian>
    );
}
