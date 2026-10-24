<?php
declare(strict_types=1);
require_once __DIR__.'/AppleIdCardPdf.php';
require_once __DIR__.'/SiteSettings.php';
require_once __DIR__.'/CommitmentText.php';

/**
 * A4 portrait commitment certificate. The existing 85x55 mm card is imported
 * directly as a PDF XObject, retaining all its original vector text and lines.
 */
final class CommitmentPdf
{
    public function generate(int $recordId,bool $showSecrets=false):string
    {
        if ($recordId<1) throw new DomainException('شناسه رکورد نامعتبر است.');
        $raw=setting('commitment_html','');
        $html=CommitmentText::clean($raw);
        if (CommitmentText::plain($html)==='') {
            throw new DomainException('ابتدا متن تعهدنامه را در تنظیمات سایت ثبت کنید.');
        }
        // This also validates the record's existence and soft-delete state.
        $card=(new AppleIdCardPdf())->generate($recordId,$showSecrets);
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $fontPath=STORAGE_PATH.'/fonts';
        $fontDirs=(new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $fontData=(new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $pdf=new \Mpdf\Mpdf([
            'mode'=>'utf-8',
            'format'=>'A4',
            'orientation'=>'P',
            'margin_left'=>18,'margin_right'=>18,'margin_top'=>14,'margin_bottom'=>16,
            'margin_header'=>0,'margin_footer'=>0,
            'fontDir'=>array_merge($fontDirs,[$fontPath]),
            'fontdata'=>array_merge($fontData,[
                'vazirmatn'=>['R'=>'Vazirmatn-Regular.ttf',
                    'B'=>'Vazirmatn-Bold.ttf','useOTL'=>0xFF,'useKashida'=>0],
            ]),
            'default_font'=>'vazirmatn',
            'tempDir'=>sys_get_temp_dir(),
        ]);
        $pdf->SetTitle('Commitment Report #'.$recordId);
        $pdf->SetAuthor('Apple ID Management');
        $pdf->SetDirectionality('rtl');
        $pdf->AddPage('P');

        // No rasterizing/rescaling: 85 mm x 55 mm imported at exactly 85x55.
        $reader=\setasign\Fpdi\PdfParser\StreamReader::createByString($card);
        $count=$pdf->setSourceFile($reader);
        if ($count!==1) throw new RuntimeException('کارت اصلی باید یک صفحه داشته باشد.');
        $template=$pdf->importPage(1);
        $pdf->useTemplate($template,62.5,13,85,55);

        $logo=SiteSettings::logoFile();
        if ($logo) {
            $dimensions=@getimagesize($logo['path']);
            if (is_array($dimensions) && $dimensions[0]>0 && $dimensions[1]>0) {
                $scale=min(27/$dimensions[0],20/$dimensions[1]);
                $width=$dimensions[0]*$scale;
                $height=$dimensions[1]*$scale;
                // Top-right, outside the central 85mm card.
                $pdf->Image($logo['path'],210-14-$width,15,$width,$height);
            }
        }

        $pdf->SetDrawColor(224,230,237);
        $pdf->SetLineWidth(.22);
        $pdf->Line(18,78,192,78);
        $pdf->SetXY(18,84);
        // Sanitized supported HTML only; mixed-language text flows and may
        // continue onto another A4 page instead of being clipped or overlaid.
        $style='<style>
          body { font-family:vazirmatn; direction:rtl; text-align:right; }
          .commitment { direction:rtl; text-align:right; color:#233044;
                        font-family:vazirmatn; font-size:11pt; line-height:1.75; }
          .commitment p, .commitment div { margin:0 0 4mm 0; }
          .commitment h2 { font-size:15pt; margin:0 0 5mm 0; }
          .commitment h3 { font-size:13pt; margin:3mm 0; }
          .commitment ul, .commitment ol { margin:2mm 0 3mm 0; }
        </style>';
        $pdf->WriteHTML($style.'<div class="commitment" dir="rtl">'.$html.'</div>');
        return $pdf->Output('',\Mpdf\Output\Destination::STRING_RETURN);
    }

    public static function send(int $id,bool $includeSensitive):never
    {
        $bytes=(new self())->generate($id,$includeSensitive);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="commitment-'.$id.'.pdf"');
        header('Content-Length: '.strlen($bytes));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo $bytes;
        exit;
    }
}
