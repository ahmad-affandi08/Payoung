{{--
    Tombol aksi utama email. Dirakit dari tabel, bukan `<a>` ber-padding, karena Outlook mengabaikan
    padding pada tautan sehingga tombolnya menyusut jadi teks biasa.

    Dipakai: @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Verifikasi email saya'])
--}}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:24px 0;">
    <tr>
        <td align="center" bgcolor="#3b5b5d" style="background-color:#3b5b5d; border-radius:8px; mso-padding-alt:14px 28px;">
            <a href="{{ $Url }}" style="display:inline-block; padding:14px 28px; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:20px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $Label }}</a>
        </td>
    </tr>
</table>
