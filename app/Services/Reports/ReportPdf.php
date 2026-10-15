<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Turns a rendered report into a PDF. Nothing is fetched from outside, and the file carries no author, creator, producer or dates. */
final class ReportPdf
{
    public static function make(string $html, bool $landscape): string
    {
        $o = new Options;
        $o->set('isRemoteEnabled', false);
        $o->set('isPhpEnabled', false);
        $o->set('isHtml5ParserEnabled', true);
        $o->set('defaultFont', 'DejaVu Sans');
        $o->set('chroot', base_path('vendor/dompdf'));   // it may read its own fonts and nothing else
        $d = new Dompdf($o);
        $d->loadHtml($html, 'UTF-8');
        $d->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $d->render();

        return self::stripMetadata($d->output());
    }

    /**
     * The PDF engine writes who made the file and when. Those entries are blanked, with the same number of characters so the file's byte offsets
     * (which the reader uses to find its parts) stay right, and the title is the page title the report already shows.
     */
    private static function stripMetadata(string $pdf): string
    {
        foreach (['Producer', 'Creator', 'CreationDate', 'ModDate', 'Author', 'Subject', 'Keywords'] as $key) {
            $pdf = preg_replace_callback('/\/'.$key.' \((.*?)\)/s', fn ($m) => '/'.$key.' ('.str_repeat(' ', strlen($m[1])).')', $pdf) ?? $pdf;
        }

        return $pdf;
    }
}
