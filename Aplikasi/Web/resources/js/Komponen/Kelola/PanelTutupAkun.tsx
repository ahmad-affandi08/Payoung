import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';

export type StatusPenghapusanData = {
    Tersedia: boolean;
    HariTunggu: number;
    EksporSudahDiunduh: boolean;
    Menunggu: { Uuid: string; BerlakuPada: string; DiajukanPada: string | null; Alasan: string | null } | null;
    Riwayat: {
        Uuid: string;
        Status: 'Dibatalkan' | 'Ditolak' | 'Dilaksanakan';
        DiajukanPada: string | null;
        DiputuskanPada: string | null;
        CatatanPemutus: string | null;
    }[];
};

export const FRASA_KONFIRMASI = 'HAPUS DATA';

/**
 * UU PDP, P-07: Owner menutup akun dan meminta penghapusan data. Alurnya sengaja berlapis: ekspor data harus sudah
 * diunduh, kata sandi diminta ulang, frasa konfirmasi diketik, lalu ada masa tunggu yang bisa dibatalkan sebelum Super
 * Admin melaksanakan. Hanya di edisi SaaS (`Tersedia`).
 */
export default function PanelTutupAkun({
    status,
    perluKataSandi,
}: {
    status: StatusPenghapusanData;
    perluKataSandi: boolean;
}) {
    const [terbuka, AturTerbuka] = useState(false);
    const batal = useForm({});

    if (!status.Tersedia) {
        return null;
    }

    const menunggu = status.Menunggu;

    return (
        <Panel
            judul="Tutup akun dan hapus data"
            tingkat="h2"
            keterangan="Hapus data pribadi usaha Anda dan tutup akunnya. Tindakan ini tidak bisa dibalik setelah dilaksanakan."
        >
            <div className="grid gap-3">
                {menunggu ? (
                    <Pemberitahuan jenis="peringatan" judul="Permintaan penghapusan data sedang menunggu">
                        <p>
                            Diajukan {FormatTanggalWaktu(menunggu.DiajukanPada)}. Paling cepat dilaksanakan{' '}
                            <strong>{FormatTanggal(menunggu.BerlakuPada)}</strong>. Sampai saat itu usaha Anda berjalan
                            seperti biasa dan Anda bisa membatalkannya.
                        </p>
                        <div className="mt-2">
                            <Tombol
                                varian="sekunder"
                                memproses={batal.processing}
                                onClick={() =>
                                    batal.delete('/kelola/pengaturan/data-saya/penghapusan', { preserveScroll: true })
                                }
                            >
                                Batalkan permintaan
                            </Tombol>
                        </div>
                    </Pemberitahuan>
                ) : (
                    <>
                        <div className="grid gap-2 text-isi text-teks-sekunder">
                            <p>
                                <strong className="text-teks-utama">Yang dihapus:</strong> data pelanggan, karyawan,
                                pemasok, foto, kredensial integrasi, dan akun pengguna usaha ini.
                            </p>
                            <p>
                                <strong className="text-teks-utama">Yang tetap disimpan:</strong> dokumen transaksi dan
                                pembukuan sesuai ketentuan perpajakan, dengan identitas dianonimkan.
                            </p>
                            <p>
                                Sisa masa langganan berbayar tidak dikembalikan, dan langganan berhenti saat penghapusan
                                dilaksanakan.
                            </p>
                            <p>
                                Setelah diajukan ada masa tunggu {status.HariTunggu} hari yang bisa Anda batalkan.
                                Seluruh Pemilik usaha diberi tahu lewat email.
                            </p>
                        </div>
                        {status.EksporSudahDiunduh ? null : (
                            <Pemberitahuan jenis="info" judul="Unduh ekspor data dulu">
                                Sebelum mengajukan, unduh ekspor data usaha Anda di bagian Ekspor data usaha di atas.
                                Setelah akun ditutup, data tidak bisa dikembalikan.
                            </Pemberitahuan>
                        )}
                        <div>
                            <Tombol
                                varian="bahaya"
                                disabled={!status.EksporSudahDiunduh}
                                onClick={() => AturTerbuka(true)}
                            >
                                Ajukan penghapusan data
                            </Tombol>
                        </div>
                    </>
                )}

                {status.Riwayat.length > 0 ? (
                    <ul className="grid gap-1" aria-label="Riwayat permintaan penghapusan data">
                        {status.Riwayat.map((baris) => (
                            <li
                                key={baris.Uuid}
                                className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder"
                            >
                                <LabelStatus
                                    jenis={
                                        baris.Status === 'Dilaksanakan'
                                            ? 'netral'
                                            : baris.Status === 'Ditolak'
                                              ? 'bahaya'
                                              : 'peringatan'
                                    }
                                    teks={baris.Status}
                                />
                                <span>
                                    {FormatTanggalWaktu(baris.DiajukanPada)}
                                    {baris.CatatanPemutus ? ` | ${baris.CatatanPemutus}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
            {terbuka ? <FormAjukan perluKataSandi={perluKataSandi} saatSelesai={() => AturTerbuka(false)} /> : null}
        </Panel>
    );
}

function FormAjukan({ perluKataSandi, saatSelesai }: { perluKataSandi: boolean; saatSelesai: () => void }) {
    const formulir = useForm({ KataSandi: '', Konfirmasi: '', Alasan: '' });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pengaturan/data-saya/penghapusan', {
            preserveScroll: true,
            onSuccess: saatSelesai,
            onFinish: () => formulir.reset('KataSandi'),
        });
    };

    return (
        <KerangkaForm
            judul="Ajukan penghapusan data"
            keterangan={
                <p>
                    Tim kami melaksanakannya setelah masa tunggu berakhir. Data pribadi dihapus, transaksi tetap
                    disimpan dengan identitas dianonimkan, dan usaha tidak bisa dibuka kembali.
                </p>
            }
            labelKirim="Ajukan penghapusan data"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(galat)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            {perluKataSandi ? (
                <BidangTeks
                    label="Kata sandi Anda"
                    jenis="password"
                    nilai={formulir.data.KataSandi}
                    saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                    galat={galat.KataSandi}
                    autoComplete="current-password"
                    required
                />
            ) : null}
            <BidangTeks
                label={`Ketik ${FRASA_KONFIRMASI} untuk melanjutkan`}
                nilai={formulir.data.Konfirmasi}
                saatBerubah={(nilai) => formulir.setData('Konfirmasi', nilai)}
                galat={galat.Konfirmasi}
                autoComplete="off"
                required
            />
            <BidangTeksPanjang
                label="Alasan (opsional)"
                nilai={formulir.data.Alasan}
                baris={2}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={galat.Alasan}
            />
        </KerangkaForm>
    );
}
