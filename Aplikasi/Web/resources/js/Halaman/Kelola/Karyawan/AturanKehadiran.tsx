import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { AturanKehadiran, PropsAturanKehadiran } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/aturan-kehadiran';

type Isian = {
    [K in keyof AturanKehadiran]: AturanKehadiran[K] extends boolean ? boolean : string;
};

function BuatIsian(a: AturanKehadiran): Isian {
    return {
        WajibJadwal: a.WajibJadwal,
        MasukPalingAwalMenit: String(a.MasukPalingAwalMenit),
        ToleransiTerlambatMenit: String(a.ToleransiTerlambatMenit),
        ToleransiPulangCepatMenit: String(a.ToleransiPulangCepatMenit),
        LemburSetelahMenit: String(a.LemburSetelahMenit),
        PengingatShiftAktif: a.PengingatShiftAktif,
        PengingatShiftMenitSebelum: String(a.PengingatShiftMenitSebelum),
        PeringatanPengelolaAktif: a.PeringatanPengelolaAktif,
        PeringatanPengelolaSetelahMenit: String(a.PeringatanPengelolaSetelahMenit),
        SimpanFotoBukti: a.SimpanFotoBukti,
        TolakDiLuarRadius: a.TolakDiLuarRadius,
        TutupOtomatisAktif: a.TutupOtomatisAktif,
        TutupOtomatisSetelahMenit: String(a.TutupOtomatisSetelahMenit),
    };
}

/**
 * Aturan kehadiran (F-18 bagian 5, D-44): bagaimana jadwal kerja dipakai absensi dan notifikasi. Bawaannya semua
 * longgar (absen tanpa jadwal tetap diterima, notifikasi mati) supaya usaha yang sudah berjalan tidak berubah.
 */
export default function HalamanAturanKehadiran({ Aturan, WhatsappAktif, Izin }: PropsAturanKehadiran) {
    const formulir = useForm<Isian>(BuatIsian(Aturan));
    const Menit = (kunci: keyof Isian, label: string, keterangan: string, wajib = true) => (
        <BidangTeks
            label={`${label} (menit)`}
            nilai={String(formulir.data[kunci])}
            saatBerubah={(nilai) => formulir.setData(kunci, nilai.replace(/\D/g, '') as never)}
            galat={formulir.errors[kunci]}
            keterangan={keterangan}
            inputMode="numeric"
            maxLength={3}
            disabled={!Izin.Kelola}
            required={wajib}
        />
    );

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(alamat, { preserveScroll: true });
    };

    return (
        <TataLetakAplikasi judul="Aturan kehadiran">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Mengatur bagaimana jadwal kerja dipakai saat karyawan absen, cara menilai terlambat, pulang cepat, dan
                lembur (dasar rekap gaji), serta kapan karyawan dan pengelola diingatkan lewat WhatsApp.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="karyawan.kelola" objek="aturan kehadiran" /> : null}

            <form onSubmit={Kirim} className="flex flex-col gap-4" aria-label="Aturan kehadiran" noValidate>
                <Panel
                    judul="Bukti kehadiran"
                    keterangan="Karyawan absen dari HP setelah login atau lewat tautan absen pribadinya. Wajahnya selalu dicocokkan dengan wajah terdaftar. Di sini Anda mengatur foto dan lokasinya."
                >
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <KotakCentang
                                label="Simpan foto bukti tiap absen"
                                nilai={formulir.data.SimpanFotoBukti}
                                saatBerubah={(nilai) => formulir.setData('SimpanFotoBukti', nilai)}
                                disabled={!Izin.Kelola}
                            />
                            <p className="text-keterangan text-teks-sekunder">
                                Foto bisa dilihat di rekap absensi. Jika dimatikan, wajah tetap dicocokkan tetapi
                                fotonya tidak disimpan, hanya skor kemiripannya.
                            </p>
                        </div>
                        <div className="flex flex-col gap-1">
                            <KotakCentang
                                label="Tolak absen di luar radius outlet"
                                nilai={formulir.data.TolakDiLuarRadius}
                                saatBerubah={(nilai) => formulir.setData('TolakDiLuarRadius', nilai)}
                                disabled={!Izin.Kelola}
                            />
                            <p className="text-keterangan text-teks-sekunder">
                                Dimatikan (bawaan): absen tetap tercatat dan ditandai &ldquo;perlu dicek&rdquo; bila
                                lokasinya jauh dari outlet atau sinyal GPS lemah, supaya karyawan tidak tertolak hanya
                                karena GPS meleset. Dinyalakan: absen di luar radius ditolak.
                            </p>
                        </div>
                    </div>
                </Panel>
                <Panel
                    judul="Absen mengikuti jadwal"
                    keterangan="Berlaku untuk absen dari HP pribadi (ditolak langsung). Absen di aplikasi kasir tetap diterima karena bisa dilakukan offline, tetapi ditandai Di luar jadwal untuk ditinjau."
                >
                    <div className="flex flex-col gap-4">
                        <KotakCentang
                            label="Wajib punya jadwal kerja untuk absen masuk"
                            nilai={formulir.data.WajibJadwal}
                            saatBerubah={(nilai) => formulir.setData('WajibJadwal', nilai)}
                        />
                        {Menit(
                            'MasukPalingAwalMenit',
                            'Absen masuk dibuka sebelum shift',
                            'Absen masuk lebih awal dari ini ditolak. Absen setelah jam selesai shift juga ditolak.',
                        )}
                    </div>
                </Panel>

                <Panel judul="Menilai terlambat, pulang cepat, dan lembur">
                    <div className="grid gap-4 sm:grid-cols-3">
                        {Menit(
                            'ToleransiTerlambatMenit',
                            'Toleransi terlambat',
                            'Terlambat dihitung bila masuk lewat batas ini. Nilainya selisih penuh dari jam mulai.',
                        )}
                        {Menit(
                            'ToleransiPulangCepatMenit',
                            'Toleransi pulang cepat',
                            'Pulang cepat dihitung bila keluar lebih awal dari batas ini.',
                        )}
                        {Menit(
                            'LemburSetelahMenit',
                            'Lembur dihitung setelah',
                            'Keluar lebih lama dari batas ini setelah jam selesai dihitung lembur penuh. Isi 0 untuk menghitung semua kelebihan.',
                        )}
                    </div>
                    <p className="mt-3 text-keterangan text-teks-sekunder">
                        Tarif lembur dan potongan terlambat/tidak masuk diatur per karyawan di daftar karyawan, lalu
                        dipakai rekap gaji.
                    </p>
                </Panel>

                <Panel
                    judul="Lupa absen keluar"
                    keterangan="Karyawan yang lupa absen keluar tidak boleh membuat jam kerjanya menyambung ke hari berikutnya. Absensinya ditutup otomatis sesuai jam selesai jadwal."
                >
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <KotakCentang
                                label="Tutup otomatis absensi yang lupa keluar"
                                nilai={formulir.data.TutupOtomatisAktif}
                                saatBerubah={(nilai) => formulir.setData('TutupOtomatisAktif', nilai)}
                                disabled={!Izin.Kelola}
                            />
                            <p className="text-keterangan text-teks-sekunder">
                                Jam keluar yang dicatat adalah jam selesai jadwal, bukan jam sistem menutupnya. Tanpa
                                jadwal, dicatat pukul 23.59 di hari masuk. Absensi itu ditandai &ldquo;perlu
                                dicek&rdquo; dan muncul di Kotak Tindakan, supaya jam pulangnya bisa Anda koreksi.
                            </p>
                        </div>
                        {Menit(
                            'TutupOtomatisSetelahMenit',
                            'Tunggu setelah jam selesai',
                            'Selama batas ini karyawan yang masih bekerja (lembur) tidak ditutup. Isi 0 untuk menutup tepat setelah jam selesai.',
                        )}
                    </div>
                </Panel>

                <Panel
                    judul="Notifikasi WhatsApp"
                    keterangan="Pengingat dikirim ke nomor HP akun karyawan. Peringatan dikirim ke anggota yang boleh melihat karyawan di outlet itu."
                >
                    <div className="flex flex-col gap-4">
                        {!WhatsappAktif ? (
                            <Pemberitahuan jenis="info" judul="WhatsApp belum aktif untuk usaha ini">
                                Notifikasi baru terkirim setelah pengiriman WhatsApp aktif. Pengaturannya tersimpan.
                            </Pemberitahuan>
                        ) : null}
                        <KotakCentang
                            label="Ingatkan karyawan sebelum shift dimulai"
                            nilai={formulir.data.PengingatShiftAktif}
                            saatBerubah={(nilai) => formulir.setData('PengingatShiftAktif', nilai)}
                        />
                        {Menit(
                            'PengingatShiftMenitSebelum',
                            'Kirim pengingat sebelum jam mulai',
                            'Tidak dikirim bila karyawan sudah absen. Karyawan tanpa akun atau nomor HP dilewati.',
                        )}
                        <KotakCentang
                            label="Beritahu pengelola bila karyawan terlambat atau belum masuk"
                            nilai={formulir.data.PeringatanPengelolaAktif}
                            saatBerubah={(nilai) => formulir.setData('PeringatanPengelolaAktif', nilai)}
                        />
                        {Menit(
                            'PeringatanPengelolaSetelahMenit',
                            'Kirim peringatan setelah jam mulai',
                            'Tidak boleh lebih awal dari toleransi terlambat. Satu pesan per kejadian.',
                        )}
                    </div>
                </Panel>

                {Izin.Kelola ? (
                    <BilahAksiForm>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan aturan kehadiran
                        </Tombol>
                    </BilahAksiForm>
                ) : null}
            </form>
        </TataLetakAplikasi>
    );
}
