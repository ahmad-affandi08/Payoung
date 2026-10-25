{{--
    Blok kutipan untuk teks yang ditulis orang lain (balasan petugas dukungan, pesan alert, ringkasan
    perubahan dokumen legal). Digarisi kiri agar terbaca sebagai kutipan walau warnanya dihilangkan
    (checklist DesainUi: layar tetap bisa dipahami jika semua warna dihapus).

    `nl2br` memakai `e()` lebih dulu supaya isi dari pengguna tidak pernah dirender sebagai HTML.

    Dipakai: @include('Surel.Komponen.Kutipan', ['Teks' => $Isi])
--}}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%; margin:0 0 20px 0;">
    <tr>
        <td style="padding:4px 0 4px 16px; border-left:3px solid #d2dcd6; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">{!! nl2br(e($Teks)) !!}</td>
    </tr>
</table>
