import ChipIkon from '@/Komponen/Situs/ChipIkon';
import TautanSitus from '@/Komponen/Situs/TautanSitus';
import { cn } from '@/Komponen/Ui/utils';
import type { BagianSitus } from '@/Tipe/Situs';

import { CekGelap, GambarBagian, KelasKartu, KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

const KOLOM = {
    '2': 'sm:grid-cols-2',
    '3': 'sm:grid-cols-2 lg:grid-cols-3',
    '4': 'sm:grid-cols-2 lg:grid-cols-4',
} as const;

/**
 * Judul item dengan ikon garis 24px berwarna merek di atasnya (D-39), tetap tanpa kotak ikon berwarna (D-25).
 * Ikon raster lama yang kecil dan buram diganti ikon garis lucide yang tajam di layar retina.
 */
function JudulItem({
    ikon,
    judul,
    gelap,
    indeks = 0,
}: {
    ikon: string | null;
    judul: string;
    gelap: boolean;
    indeks?: number;
}) {
    return (
        <div className="flex flex-col gap-4">
            <ChipIkon nama={ikon} indeks={indeks} gelap={gelap} />
            <h3 className={cn('text-subjudul font-semibold', gelap ? 'text-permukaan' : 'text-teks-utama')}>{judul}</h3>
        </div>
    );
}

function TeksItem({ teks, gelap }: { teks: string; gelap: boolean }) {
    return (
        <p className={cn('text-isi whitespace-pre-line', gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder')}>
            {teks}
        </p>
    );
}

type PropsKeunggulan = {
    bagian: Extract<BagianSitus, { Jenis: 'Keunggulan' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
};

/**
 * Keunggulan/fitur dengan tiga tata letak (D-25) agar dua blok sejenis tidak pernah terlihat sama:
 *
 * - `Grid` — kartu ringkas sejajar, ikon 20px sebaris dengan judul (tanpa kotak ikon berwarna);
 * - `Daftar` — dua kolom mengalir tanpa bingkai, dipisah garis 1px; cocok untuk daftar panjang;
 * - `Sorot` — item pertama besar, sisanya ringkas di sampingnya.
 */
export function BagianKeunggulan({ bagian, latar, garisAtas }: PropsKeunggulan) {
    const gelap = CekGelap(latar);
    const tataLetak = bagian.TataLetak ?? 'Grid';

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            {tataLetak === 'Daftar' ? (
                <ul className="grid gap-x-10 sm:grid-cols-2">
                    {bagian.Item.map((item, i) => (
                        <li
                            key={`${item.Judul}-${i}`}
                            className={cn(
                                'flex flex-col gap-2 border-t py-5',
                                gelap ? 'border-brand-gelap-garis' : 'border-garis',
                            )}
                        >
                            <JudulItem ikon={item.Ikon} judul={item.Judul} gelap={gelap} indeks={i} />
                            {item.Teks ? <TeksItem teks={item.Teks} gelap={gelap} /> : null}
                        </li>
                    ))}
                </ul>
            ) : tataLetak === 'Sorot' ? (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {bagian.Item.map((item, i) => (
                        <li
                            key={`${item.Judul}-${i}`}
                            className={cn(
                                'relative isolate flex flex-col gap-3 overflow-hidden',
                                KelasKartu(latar),
                                // Kartu utama berlatar merek gelap (jangkar visual), supaya ruang kosongnya terbaca
                                // sebagai kartu yang disengaja dan bukan lubang di tengah grid.
                                i === 0 && !gelap && 'border-brand-gelap bg-brand-gelap',
                                // D-39: item pertama 2 kolom × 2 baris di grid tiga kolom, sehingga enam item pas
                                // memenuhi 3×3 tanpa kartu yatim (versi lama: satu baris penuh + 5 kartu dua kolom).
                                i === 0 && 'justify-between sm:col-span-2 lg:col-span-2 lg:row-span-2 lg:gap-8 lg:p-8',
                            )}
                        >
                            {i === 0 ? (
                                <>
                                    <span
                                        aria-hidden
                                        className="absolute -right-16 -bottom-16 -z-10 size-72 rounded-full bg-brand-gelap-sorot"
                                    />
                                    <span
                                        aria-hidden
                                        className="absolute top-10 right-10 -z-10 size-20 rounded-full bg-aksen"
                                    />
                                    <ChipIkon nama={item.Ikon} indeks={1} gelap ukuran="besar" />
                                    <div className="flex flex-col gap-3">
                                        <h3 className="text-judul font-bold text-permukaan">{item.Judul}</h3>
                                        {item.Teks ? (
                                            <p className="text-subjudul max-w-3xl whitespace-pre-line text-brand-gelap-teks">
                                                {item.Teks}
                                            </p>
                                        ) : null}
                                    </div>
                                </>
                            ) : (
                                <>
                                    <JudulItem ikon={item.Ikon} judul={item.Judul} gelap={gelap} indeks={i} />
                                    {item.Teks ? <TeksItem teks={item.Teks} gelap={gelap} /> : null}
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <ul className={`grid gap-4 ${KOLOM[bagian.Kolom ?? '3']}`}>
                    {bagian.Item.map((item, i) => (
                        <li key={`${item.Judul}-${i}`} className={cn('flex flex-col gap-3', KelasKartu(latar))}>
                            <JudulItem ikon={item.Ikon} judul={item.Judul} gelap={gelap} indeks={i} />
                            {item.Teks ? <TeksItem teks={item.Teks} gelap={gelap} /> : null}
                        </li>
                    ))}
                </ul>
            )}
        </WadahBagian>
    );
}

/**
 * Jenis usaha sebagai kartu bergambar (D-25): gambar besar di atas, nama dan teks di bawah. Bentuknya
 * sengaja berbeda dari blok keunggulan supaya dua blok berurutan tidak terbaca sebagai grid yang sama.
 * Ikon hanya dipakai bila item belum punya gambar.
 */
export function BagianSektor({
    bagian,
    latar,
    garisAtas,
}: {
    bagian: Extract<BagianSitus, { Jenis: 'Sektor' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
}) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                {bagian.Item.map((item, i) => {
                    const isi = (
                        <>
                            {item.Gambar ? (
                                <GambarBagian
                                    gambar={item.Gambar}
                                    className={cn(
                                        'aspect-[4/3] w-full rounded-panel border object-cover',
                                        gelap ? 'border-brand-gelap-garis' : 'border-garis',
                                    )}
                                />
                            ) : null}
                            <div className="flex flex-col gap-2">
                                <JudulItem
                                    ikon={item.Gambar ? null : item.Ikon}
                                    judul={item.Nama}
                                    gelap={gelap}
                                    indeks={i}
                                />
                                {item.Teks ? <TeksItem teks={item.Teks} gelap={gelap} /> : null}
                            </div>
                            {item.Tautan ? (
                                <span
                                    className={cn(
                                        'text-isi mt-auto font-semibold',
                                        gelap ? 'text-permukaan' : 'text-brand',
                                    )}
                                >
                                    Selengkapnya →
                                </span>
                            ) : null}
                        </>
                    );
                    const kelas = 'flex h-full flex-col gap-4';

                    return (
                        <li key={`${item.Nama}-${i}`}>
                            {item.Tautan ? (
                                <TautanSitus
                                    href={item.Tautan}
                                    className={cn(kelas, 'group', gelap ? 'hover:text-permukaan' : 'hover:text-brand')}
                                >
                                    {isi}
                                </TautanSitus>
                            ) : (
                                <div className={kelas}>{isi}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </WadahBagian>
    );
}

/** Angka statistik; isinya ditulis pengelola (jangan mengarang angka, D-21). */
export function BagianStatistik({ bagian }: { bagian: Extract<BagianSitus, { Jenis: 'Statistik' }> }) {
    return (
        <WadahBagian latar="brand">
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap />
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
                {bagian.Item.map((item, i) => (
                    <div
                        key={`${item.Angka}-${i}`}
                        className="flex flex-col-reverse justify-end gap-2 rounded-3xl bg-brand-gelap p-6"
                    >
                        <dt className="text-isi text-brand-gelap-teks">{item.Keterangan}</dt>
                        <dd className="text-sorotan-hp font-bold text-aksen sm:text-sorotan">{item.Angka}</dd>
                    </div>
                ))}
            </dl>
        </WadahBagian>
    );
}

/** Testimoni pelanggan nyata yang dimasukkan pengelola. */
export function BagianTestimoni({
    bagian,
    latar,
    garisAtas,
}: {
    bagian: Extract<BagianSitus, { Jenis: 'Testimoni' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
}) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {bagian.Item.map((item, i) => (
                    <li
                        key={`${item.Nama}-${i}`}
                        className={cn(
                            'flex flex-col gap-4 border-l-[3px] pl-5',
                            gelap ? 'border-permukaan' : 'border-aksen',
                        )}
                    >
                        {item.Bintang ? (
                            <p
                                className={cn('text-subjudul', gelap ? 'text-permukaan' : 'text-peringatan')}
                                aria-label={`${item.Bintang} dari 5 bintang`}
                            >
                                {'★'.repeat(item.Bintang)}
                                <span className={gelap ? 'text-brand-gelap-garis' : 'text-garis'}>
                                    {'★'.repeat(5 - item.Bintang)}
                                </span>
                            </p>
                        ) : null}
                        <blockquote
                            className={cn(
                                'text-subjudul whitespace-pre-line',
                                gelap ? 'text-permukaan' : 'text-teks-utama',
                            )}
                        >
                            “{item.Kutipan}”
                        </blockquote>
                        <div className="mt-auto flex items-center gap-3">
                            {item.Foto ? (
                                <GambarBagian gambar={item.Foto} className="size-11 rounded-full object-cover" />
                            ) : null}
                            <div>
                                <p
                                    className={cn(
                                        'text-isi font-semibold',
                                        gelap ? 'text-permukaan' : 'text-teks-utama',
                                    )}
                                >
                                    {item.Nama}
                                </p>
                                {item.Usaha ? (
                                    <p
                                        className={cn(
                                            'text-label',
                                            gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                                        )}
                                    >
                                        {item.Usaha}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </WadahBagian>
    );
}

/** Logo mitra/klien. */
export function BagianLogoMitra({
    bagian,
    latar,
    garisAtas,
}: {
    bagian: Extract<BagianSitus, { Jenis: 'LogoMitra' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
}) {
    const gelap = CekGelap(latar);

    return (
        <WadahBagian latar={latar} garisAtas={garisAtas}>
            <KepalaBagian label={bagian.Label} judul={bagian.Judul} subjudul={bagian.Subjudul} gelap={gelap} />
            <ul className="flex flex-wrap items-center gap-x-10 gap-y-6">
                {bagian.Item.map((item, i) => {
                    const logo = item.Gambar ? (
                        <GambarBagian gambar={{ ...item.Gambar, Alt: item.Nama }} className="h-10 w-auto sm:h-12" />
                    ) : (
                        <span
                            className={cn(
                                'text-subjudul font-semibold',
                                gelap ? 'text-brand-gelap-teks' : 'text-teks-sekunder',
                            )}
                        >
                            {item.Nama}
                        </span>
                    );

                    return (
                        <li key={`${item.Nama}-${i}`}>
                            {item.Tautan ? <TautanSitus href={item.Tautan}>{logo}</TautanSitus> : logo}
                        </li>
                    );
                })}
            </ul>
        </WadahBagian>
    );
}
