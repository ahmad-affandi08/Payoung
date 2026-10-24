import { useForm } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';

import RiwayatAlatDukungan from '@/Komponen/Dukungan/RiwayatAlatDukungan';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { AmbilJson } from '@/Pustaka/PermintaanJson';
import type { RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

type PropsPanelAlat = {
    uuidTenant: string;
    data: RiwayatAksesDukunganTenant;
};

type PerangkatAlat = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Outlet: string;
    Jenis: string;
    Platform: string | null;
    VersiAplikasi: string | null;
    Status: 'Aktif' | 'Dicabut' | 'BelumDiaktifkan';
    TerakhirAktifPada: string | null;
    JumlahOutboxTertunda: number;
    MintaLogPada: string | null;
    LogDiunggahPada: string | null;
    PermintaanLogBerjalan: boolean;
    Perhatian: string | null;
};

type PesanGagal = {
    Uuid: string;
    Kanal: string;
    TujuanTersamar: string;
    Percobaan: number;
    PesanGalat: string | null;
    DibuatPada: string | null;
    SudahDikirimUlang: boolean;
};

type AnggotaBelumVerifikasi = { Uuid: string; Nama: string; EmailTersamar: string; Pemilik: boolean };

type LogPerangkat = {
    Log: { Kode: string; DiunggahPada: string; VersiAplikasi: string | null; JumlahBaris: number; Isi: string };
};

type Tindakan = {
    judul: string;
    keterangan: string;
    labelKirim: string;
    url: string;
    bahaya?: boolean;
};

const jenisStatusPerangkat = { Aktif: 'sukses', Dicabut: 'bahaya', BelumDiaktifkan: 'netral' } as const;
const labelStatusPerangkat = { Aktif: 'Aktif', Dicabut: 'Dicabut', BelumDiaktifkan: 'Belum diaktifkan' } as const;

function PesanDari(galat: unknown): string {
    return galat instanceof Error ? galat.message : 'Gagal memuat. Coba lagi.';
}

/**
 * PGL-16 bagian 2 (P-09, BR-P09.3): alat bantu dukungan di tampilan 360° tenant. Hanya terbuka bila ada izin akses yang
 * berlaku; alat yang mengubah data hanya bila cakupannya "Baca & ubah". Semua daftar dimuat dari server saat panel dibuka
 * (nomor dan email selalu tersamar); hasil tiap alat tercatat dengan keadaan sebelum dan sesudah.
 */
export default function PanelAlatBantuDukungan({ uuidTenant, data }: PropsPanelAlat) {
    const aktif = data.Aktif;
    const boleUbah = aktif?.Cakupan === 'BacaUbah';
    const [tindakan, AturTindakan] = useState<Tindakan | null>(null);
    const [formRingkasan, AturFormRingkasan] = useState(false);
    const [uuidLog, AturUuidLog] = useState<string | null>(null);
    const dasar = `/tenant/${uuidTenant}/alat-dukungan`;

    const perangkat = useQuery({
        queryKey: KunciKueri.Dukungan.AlatPerangkat(uuidTenant),
        queryFn: ({ signal }) => AmbilJson<{ Perangkat: PerangkatAlat[] }>(`${dasar}/perangkat`, signal),
        enabled: aktif !== null,
        retry: false,
    });
    const kirimUlang = useQuery({
        queryKey: KunciKueri.Dukungan.AlatKirimUlang(uuidTenant),
        queryFn: ({ signal }) =>
            AmbilJson<{ PesanGagal: PesanGagal[]; AnggotaBelumVerifikasi: AnggotaBelumVerifikasi[] }>(
                `${dasar}/kirim-ulang`,
                signal,
            ),
        enabled: aktif !== null,
        retry: false,
    });

    return (
        <Panel judul="Alat bantu dukungan" tingkat="h2">
            <div className="grid gap-4">
                <p className="text-isi text-teks-sekunder">
                    Alat untuk membantu tenant tanpa masuk ke back-office. Hanya berjalan selama izin akses dukungan
                    berlaku, dan setiap pemakaian tercatat lengkap dengan keadaan sebelum dan sesudahnya.
                </p>

                {aktif === null ? (
                    <Pemberitahuan jenis="info">
                        Belum ada izin akses dukungan yang berlaku. Minta Owner memberi izin di Pengaturan › Akses
                        dukungan, atau buka akses darurat (Super Admin).
                    </Pemberitahuan>
                ) : (
                    <>
                        {boleUbah ? null : (
                            <Pemberitahuan jenis="info">
                                Izin ini baca saja, jadi alat yang mengubah data tidak tersedia. Minta Owner memberi
                                izin &quot;Baca &amp; ubah&quot; bila perlu.
                            </Pemberitahuan>
                        )}

                        <section className="grid gap-2" aria-labelledby="alat-perangkat">
                            <h3 id="alat-perangkat" className="text-subjudul font-semibold text-teks-utama">
                                Perangkat & antrean kirim
                            </h3>
                            {perangkat.isPending ? (
                                <p className="text-isi text-teks-sekunder">Memuat perangkat...</p>
                            ) : null}
                            {perangkat.isError ? (
                                <Pemberitahuan jenis="bahaya">{PesanDari(perangkat.error)}</Pemberitahuan>
                            ) : null}
                            {perangkat.data && perangkat.data.Perangkat.length === 0 ? (
                                <p className="text-isi text-teks-sekunder">Tenant ini belum punya perangkat.</p>
                            ) : null}
                            {perangkat.data && perangkat.data.Perangkat.length > 0 ? (
                                <ul className="grid gap-2" aria-label="Perangkat tenant">
                                    {perangkat.data.Perangkat.map((p) => (
                                        <li key={p.Uuid} className="grid gap-2 rounded-panel border border-garis p-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <LabelStatus
                                                    jenis={jenisStatusPerangkat[p.Status]}
                                                    teks={labelStatusPerangkat[p.Status]}
                                                />
                                                <span className="text-isi font-semibold text-teks-utama">{p.Nama}</span>
                                                <span className="font-mono text-keterangan text-teks-sekunder">
                                                    {p.Kode}
                                                </span>
                                            </div>
                                            <p className="text-keterangan text-teks-sekunder">
                                                {p.Outlet} | {p.Platform ?? '-'} {p.VersiAplikasi ?? ''} | terakhir
                                                terhubung{' '}
                                                {p.TerakhirAktifPada
                                                    ? FormatTanggalWaktu(p.TerakhirAktifPada)
                                                    : 'belum pernah'}{' '}
                                                | {p.JumlahOutboxTertunda} item belum terkirim
                                            </p>
                                            {p.Perhatian ? (
                                                <Pemberitahuan jenis="peringatan">{p.Perhatian}</Pemberitahuan>
                                            ) : null}
                                            {p.PermintaanLogBerjalan ? (
                                                <p className="text-keterangan text-teks-sekunder">
                                                    Log diminta{' '}
                                                    {p.MintaLogPada ? FormatTanggalWaktu(p.MintaLogPada) : ''}, menunggu
                                                    perangkat terhubung.
                                                </p>
                                            ) : null}
                                            <div className="flex flex-wrap gap-2">
                                                {p.LogDiunggahPada ? (
                                                    <Tombol varian="sekunder" onClick={() => AturUuidLog(p.Uuid)}>
                                                        Baca log ({FormatTanggalWaktu(p.LogDiunggahPada)})
                                                    </Tombol>
                                                ) : null}
                                                {boleUbah && p.Status === 'Aktif' && !p.PermintaanLogBerjalan ? (
                                                    <Tombol
                                                        varian="sekunder"
                                                        onClick={() =>
                                                            AturTindakan({
                                                                judul: `Minta log ${p.Kode}`,
                                                                keterangan:
                                                                    'Perangkat mengunggah log lokalnya (sudah terfilter dari data pribadi) saat terhubung ke server. Log disimpan 30 hari.',
                                                                labelKirim: 'Minta log',
                                                                url: `${dasar}/perangkat/${p.Uuid}/minta-log`,
                                                            })
                                                        }
                                                    >
                                                        Minta log
                                                    </Tombol>
                                                ) : null}
                                                {boleUbah && p.Status !== 'Dicabut' ? (
                                                    <Tombol
                                                        varian="bahaya"
                                                        onClick={() =>
                                                            AturTindakan({
                                                                judul: `Cabut perangkat ${p.Kode}`,
                                                                keterangan:
                                                                    'Perangkat langsung ditolak server dan kode aktivasi yang belum dipakai dibatalkan. Tidak bisa dibatalkan; Owner perlu membuat kode aktivasi baru bila perangkat dipakai lagi.',
                                                                labelKirim: 'Cabut perangkat',
                                                                url: `${dasar}/perangkat/${p.Uuid}/cabut`,
                                                                bahaya: true,
                                                            })
                                                        }
                                                    >
                                                        Cabut perangkat
                                                    </Tombol>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </section>

                        {boleUbah ? (
                            <section className="grid gap-2" aria-labelledby="alat-bangun-ulang">
                                <h3 id="alat-bangun-ulang" className="text-subjudul font-semibold text-teks-utama">
                                    Bangun ulang data turunan
                                </h3>
                                <p className="text-keterangan text-teks-sekunder">
                                    Saldo stok dan ringkasan harian adalah turunan dari buku stok dan dokumen penjualan;
                                    dokumen sumbernya tidak berubah.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Tombol
                                        varian="sekunder"
                                        onClick={() =>
                                            AturTindakan({
                                                judul: 'Bangun ulang saldo stok',
                                                keterangan:
                                                    'Saldo stok dan sisa batch dihitung ulang dari buku stok (MutasiStok). Hanya yang menyimpang yang diperbaiki; hasil sebelum dan sesudah dicatat.',
                                                labelKirim: 'Bangun ulang saldo',
                                                url: `${dasar}/saldo-stok/bangun-ulang`,
                                                bahaya: true,
                                            })
                                        }
                                    >
                                        Bangun ulang saldo stok
                                    </Tombol>
                                    <Tombol varian="sekunder" onClick={() => AturFormRingkasan(true)}>
                                        Bangun ulang ringkasan harian
                                    </Tombol>
                                </div>
                            </section>
                        ) : null}

                        <section className="grid gap-2" aria-labelledby="alat-kirim-ulang">
                            <h3 id="alat-kirim-ulang" className="text-subjudul font-semibold text-teks-utama">
                                Kirim ulang pesan
                            </h3>
                            {kirimUlang.isPending ? (
                                <p className="text-isi text-teks-sekunder">Memuat daftar...</p>
                            ) : null}
                            {kirimUlang.isError ? (
                                <Pemberitahuan jenis="bahaya">{PesanDari(kirimUlang.error)}</Pemberitahuan>
                            ) : null}
                            {kirimUlang.data ? (
                                <>
                                    <h4 className="text-label font-semibold text-teks-utama">
                                        Pesan WhatsApp gagal (14 hari terakhir)
                                    </h4>
                                    {kirimUlang.data.PesanGagal.length === 0 ? (
                                        <p className="text-isi text-teks-sekunder">
                                            Tidak ada pesan yang gagal terkirim.
                                        </p>
                                    ) : (
                                        <ul className="grid gap-2" aria-label="Pesan gagal terkirim">
                                            {kirimUlang.data.PesanGagal.map((pesan) => (
                                                <li
                                                    key={pesan.Uuid}
                                                    className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder"
                                                >
                                                    <span className="font-mono text-teks-utama">
                                                        {pesan.TujuanTersamar}
                                                    </span>
                                                    <span>
                                                        {pesan.Percobaan}x dicoba |{' '}
                                                        {pesan.PesanGalat ?? 'tanpa keterangan'}
                                                        {pesan.DibuatPada
                                                            ? ` | ${FormatTanggalWaktu(pesan.DibuatPada)}`
                                                            : ''}
                                                    </span>
                                                    {pesan.SudahDikirimUlang ? (
                                                        <LabelStatus jenis="netral" teks="Sudah dikirim ulang" />
                                                    ) : boleUbah ? (
                                                        <Tombol
                                                            varian="sekunder"
                                                            onClick={() =>
                                                                AturTindakan({
                                                                    judul: 'Kirim ulang pesan WhatsApp',
                                                                    keterangan: `Pesan ke ${pesan.TujuanTersamar} dikirim ulang lewat antrean. Kuota pesan WhatsApp tenant tetap berlaku.`,
                                                                    labelKirim: 'Kirim ulang',
                                                                    url: `${dasar}/pesan/${pesan.Uuid}/kirim-ulang`,
                                                                })
                                                            }
                                                        >
                                                            Kirim ulang
                                                        </Tombol>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <h4 className="text-label font-semibold text-teks-utama">
                                        Email belum terverifikasi
                                    </h4>
                                    {kirimUlang.data.AnggotaBelumVerifikasi.length === 0 ? (
                                        <p className="text-isi text-teks-sekunder">
                                            Semua email anggota sudah terverifikasi.
                                        </p>
                                    ) : (
                                        <ul className="grid gap-2" aria-label="Anggota belum verifikasi email">
                                            {kirimUlang.data.AnggotaBelumVerifikasi.map((anggota) => (
                                                <li
                                                    key={anggota.Uuid}
                                                    className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder"
                                                >
                                                    <span className="font-semibold text-teks-utama">
                                                        {anggota.Nama}
                                                    </span>
                                                    <span className="font-mono">{anggota.EmailTersamar}</span>
                                                    {anggota.Pemilik ? (
                                                        <LabelStatus jenis="netral" teks="Owner" />
                                                    ) : null}
                                                    {boleUbah ? (
                                                        <Tombol
                                                            varian="sekunder"
                                                            onClick={() =>
                                                                AturTindakan({
                                                                    judul: 'Kirim ulang email verifikasi',
                                                                    keterangan: `Tautan verifikasi dikirim ke ${anggota.EmailTersamar}. Paling sering sekali per 10 menit.`,
                                                                    labelKirim: 'Kirim ulang email',
                                                                    url: `${dasar}/pengguna/${anggota.Uuid}/kirim-ulang-verifikasi`,
                                                                })
                                                            }
                                                        >
                                                            Kirim ulang email
                                                        </Tombol>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </>
                            ) : null}
                        </section>
                    </>
                )}

                <section className="grid gap-2" aria-labelledby="alat-riwayat">
                    <h3 id="alat-riwayat" className="text-subjudul font-semibold text-teks-utama">
                        Riwayat alat bantu
                    </h3>
                    <RiwayatAlatDukungan alat={data.Alat} />
                </section>
            </div>

            {tindakan ? (
                <FormKonfirmasiAlat
                    uuidTenant={uuidTenant}
                    tindakan={tindakan}
                    saatSelesai={() => AturTindakan(null)}
                />
            ) : null}
            {formRingkasan ? (
                <FormRingkasanHarian
                    url={`${dasar}/ringkasan-harian/bangun-ulang`}
                    saatSelesai={() => AturFormRingkasan(false)}
                />
            ) : null}
            {uuidLog ? (
                <DialogLog uuidTenant={uuidTenant} uuidPerangkat={uuidLog} saatTutup={() => AturUuidLog(null)} />
            ) : null}
        </Panel>
    );
}

function FormKonfirmasiAlat({
    uuidTenant,
    tindakan,
    saatSelesai,
}: {
    uuidTenant: string;
    tindakan: Tindakan;
    saatSelesai: () => void;
}) {
    const klien = useQueryClient();
    const formulir = useForm({});
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(tindakan.url, {
            preserveScroll: true,
            onSuccess: () => {
                void klien.invalidateQueries({ queryKey: ['Dukungan'] });
                saatSelesai();
            },
        });
    };

    return (
        <KerangkaForm
            judul={tindakan.judul}
            keterangan={<p>{tindakan.keterangan}</p>}
            labelKirim={tindakan.labelKirim}
            varianKirim={tindakan.bahaya ? 'bahaya' : 'utama'}
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <input type="hidden" name="tenant" value={uuidTenant} />
            <p className="text-isi text-teks-sekunder">Lanjutkan?</p>
        </KerangkaForm>
    );
}

function FormRingkasanHarian({ url, saatSelesai }: { url: string; saatSelesai: () => void }) {
    const klien = useQueryClient();
    const formulir = useForm({ Dari: '', Sampai: '' });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                void klien.invalidateQueries({ queryKey: ['Dukungan'] });
                saatSelesai();
            },
        });
    };

    return (
        <KerangkaForm
            judul="Bangun ulang ringkasan harian"
            keterangan={
                <p>
                    Ringkasan penjualan harian dihitung ulang dari dokumen penjualan, void, dan retur untuk rentang
                    tanggal bisnis ini (paling lama 31 hari, tidak melewati hari ini).
                </p>
            }
            labelKirim="Bangun ulang"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(galat)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <PemilihTanggal
                label="Dari tanggal"
                nilai={formulir.data.Dari}
                saatBerubah={(nilai) => formulir.setData('Dari', nilai)}
                galat={galat.Dari}
                required
            />
            <PemilihTanggal
                label="Sampai tanggal"
                nilai={formulir.data.Sampai}
                saatBerubah={(nilai) => formulir.setData('Sampai', nilai)}
                galat={galat.Sampai}
                required
            />
        </KerangkaForm>
    );
}

function DialogLog({
    uuidTenant,
    uuidPerangkat,
    saatTutup,
}: {
    uuidTenant: string;
    uuidPerangkat: string;
    saatTutup: () => void;
}) {
    const log = useQuery({
        queryKey: KunciKueri.Dukungan.AlatLog(uuidTenant, uuidPerangkat),
        queryFn: ({ signal }) =>
            AmbilJson<LogPerangkat>(`/tenant/${uuidTenant}/alat-dukungan/perangkat/${uuidPerangkat}/log`, signal),
        retry: false,
    });

    return (
        <KerangkaForm
            judul={log.data ? `Log ${log.data.Log.Kode}` : 'Log perangkat'}
            keterangan={
                <p>
                    Log lokal aplikasi kasir yang diunggah atas permintaan dukungan. Email, token, dan deret angka
                    panjang sudah disamarkan.
                </p>
            }
            labelKirim="Tutup"
            memproses={false}
            galatUmum={undefined}
            saatKirim={(peristiwa) => {
                peristiwa.preventDefault();
                saatTutup();
            }}
            saatBatal={saatTutup}
        >
            {log.isPending ? <p className="text-isi text-teks-sekunder">Memuat log...</p> : null}
            {log.isError ? <Pemberitahuan jenis="bahaya">{PesanDari(log.error)}</Pemberitahuan> : null}
            {log.data ? (
                <div className="grid gap-2">
                    <p className="text-keterangan text-teks-sekunder">
                        Diunggah {FormatTanggalWaktu(log.data.Log.DiunggahPada)} | versi{' '}
                        {log.data.Log.VersiAplikasi ?? '-'} | {log.data.Log.JumlahBaris} baris
                    </p>
                    <pre
                        className="max-h-80 overflow-auto rounded-kontrol border border-garis bg-permukaan-redup p-3 font-mono text-keterangan text-teks-utama"
                        tabIndex={0}
                        aria-label="Isi log perangkat"
                    >
                        {log.data.Log.Isi}
                    </pre>
                </div>
            ) : null}
        </KerangkaForm>
    );
}
