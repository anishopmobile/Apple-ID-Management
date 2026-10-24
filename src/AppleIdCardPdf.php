<?php
declare(strict_types=1);

require_once __DIR__ . '/RecordManager.php';

/**
 * Single-page 85 x 55 mm print-ready PDF built with the already-installed mPDF.
 *
 * Only record ID enters from the request; every value comes from the existing
 * record/record_values tables. This class is not a public HTTP controller.
 */
final class AppleIdCardPdf
{
    public const WIDTH_MM = 85.0;
    public const HEIGHT_MM = 55.0;

    private RecordManager $records;

    public function __construct(?RecordManager $records = null)
    {
        $this->records = $records ?? new RecordManager(db(), config()['DB_PREFIX']);
        $this->records->migrate();
    }

    /** @return array<string,string> */
    public function dataForRecord(int $id): array
    {
        if ($id < 1) throw new DomainException('شناسه رکورد نامعتبر است.');
        $record = $this->records->record($id);
        if ($record === null) throw new DomainException('رکورد موردنظر وجود ندارد یا حذف شده است.');

        $data = [
            'record_id' => (string)$id,
            'apple_id' => (string)($record['apple_email'] ?? ''),
            'full_name' => (string)($record['full_name'] ?? ''),
            'mobile' => (string)($record['mobile'] ?? ''),
            'password' => '',
            'birthday' => '',
            'first_name' => '',
            'last_name' => '',
        ];
        for ($i=1; $i<=3; $i++) {
            $data['question'.$i] = '';
            $data['answer'.$i] = '';
        }

        foreach ($this->records->fields() as $field) {
            $key = (string)$field['field_key'];
            $value = $record['values'][(int)$field['id']] ?? null;
            if ($value === null || trim((string)$value) === '') continue;
            $value = trim((string)$value);
            $name = self::normalizedLabel((string)$field['label']);
            $keyName = self::normalizedLabel($key);

            if ($key==='customer_name') $data['full_name']=$value;
            if ($key==='apple_email') $data['apple_id']=$value;
            if ($key==='mobile') $data['mobile']=$value;

            if (self::isPassword($keyName,$name)) $data['password']=$value;
            if (self::isBirthday($keyName,$name)) {
                $data['birthday'] = $field['type']==='date'
                    ? RecordManager::dateLabel($value) : $value;
            }
            if (self::isFirstName($keyName,$name)) $data['first_name']=$value;
            if (self::isLastName($keyName,$name)) $data['last_name']=$value;

            for ($i=1; $i<=3; $i++) {
                if (self::isQuestion($keyName,$name,$i)) $data['question'.$i]=$value;
                if (self::isAnswer($keyName,$name,$i)) $data['answer'.$i]=$value;
            }
        }

        // The existing schema requires one customer_name field, not separate
        // first/last names. Only derive them from the real persisted full name
        // when no dedicated custom fields exist (never invent sample data).
        if ($data['first_name']==='' || $data['last_name']==='') {
            $parts = preg_split('/\s+/u',trim($data['full_name'])) ?: [];
            $first = (string)array_shift($parts);
            $last = implode(' ',$parts);
            if ($data['first_name']==='') $data['first_name']=$first;
            if ($data['last_name']==='') $data['last_name']=$last;
        }
        return $data;
    }

    private static function normalizedLabel(string $text): string
    {
        $text = mb_strtolower(RecordManager::digits(trim($text)),'UTF-8');
        $text = strtr($text, ['ي'=>'ی','ك'=>'ک','ة'=>'ه','‌'=>'','ـ'=>'']);
        return preg_replace('/[\s\p{P}\p{S}_]+/u','',$text) ?? $text;
    }

    private static function isPassword(string $key,string $label): bool
    {
        // Mail pass is not the Apple ID password and must not be substituted.
        if (str_contains($label,'mailpass') || str_contains($label,'رمزایمیل')) return false;
        return in_array($key,['password','applepassword','appleidpassword'],true)
            || in_array($label,['پسورد','رمزعبور','رمزاپلآیدی','رمزappleid',
                'password','applepassword','appleidpassword'],true);
    }

    private static function isBirthday(string $key,string $label): bool
    {
        return in_array($key,['birthday','birthdate','dateofbirth','dob'],true)
            || in_array($label,['تاریختولد','تولد','birthday','birthdate','dateofbirth','dob'],true);
    }

    private static function isFirstName(string $key,string $label): bool
    {
        return in_array($key,['firstname','givenname'],true)
            || in_array($label,['نام','نامکوچک','firstname','givenname'],true);
    }

    private static function isLastName(string $key,string $label): bool
    {
        return in_array($key,['lastname','familyname','surname'],true)
            || in_array($label,['نامخانوادگی','فامیلی','lastname','surname','familyname'],true);
    }

    private static function isQuestion(string $key,string $label,int $index): bool
    {
        return in_array($key,['securityquestion'.$index,'question'.$index],true)
            || in_array($label,[
                'سوالامنیتی'.$index,'سؤالامنیتی'.$index,'securityquestion'.$index,
                'question'.$index,'پرسشامنیتی'.$index,
            ],true);
    }

    private static function isAnswer(string $key,string $label,int $index): bool
    {
        return in_array($key,['securityanswer'.$index,'answer'.$index],true)
            || in_array($label,[
                'پاسخسوالامنیتی'.$index,'پاسخسؤالامنیتی'.$index,
                'جوابسوالامنیتی'.$index,'securityanswer'.$index,'answer'.$index,
                'پاسخ'.$index,
            ],true);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function shorten(string $value, int $max): string
    {
        // Printed business-card dimensions impose real physical limits.
        // Clip only the exceptional overly long values, at Unicode boundaries.
        $value=trim(preg_replace('/\s+/u',' ',$value) ?? $value);
        return mb_strlen($value,'UTF-8')>$max
            ? rtrim(mb_substr($value,0,$max-1,'UTF-8')).'…'
            : $value;
    }

    private static function block(
        \Mpdf\Mpdf $pdf, string $text, float $x, float $y, float $w, float $h,
        float $size=7.1, bool $bold=false, ?bool $rtl=null, string $color='#1C2A3A',
        int $maxChars=70
    ): void {
        $text = self::shorten($text!==''?$text:'—',$maxChars);
        $isRtl = $rtl ?? (bool)preg_match('/[\x{0600}-\x{06FF}]/u',$text);
        $direction = $isRtl ? 'rtl':'ltr';
        $align = $isRtl ? 'right':'left';
        $html='<div dir="'.$direction.'" style="direction:'.$direction.';text-align:'.$align.';'
            .'font-family:vazirmatn;font-size:'.$size.'pt;'
            .'line-height:1.22;color:'.$color.';font-weight:'.($bold?'bold':'normal').';'
            .'padding:0;margin:0;">'.self::escape($text).'</div>';
        $pdf->WriteFixedPosHTML($html,$x,$y,$w,$h,'auto');
    }

    /** Render an 85×55 mm one-page PDF directly into memory. */
    public function generate(int $recordId, bool $includeSensitive=false): string
    {
        $data=$this->dataForRecord($recordId);
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $fontPath=STORAGE_PATH.'/fonts';
        foreach (['Vazirmatn-Regular.ttf','Vazirmatn-Bold.ttf'] as $font) {
            if (!is_file($fontPath.'/'.$font)) {
                throw new RuntimeException('فونت فارسی PDF موجود نیست؛ فایل‌های fonts بسته نصب را بازیابی کنید.');
            }
        }
        $fontDirectories=(new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $fontData=(new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

        $pdf=new \Mpdf\Mpdf([
            'mode'=>'utf-8',
            'format'=>[self::WIDTH_MM,self::HEIGHT_MM],
            'orientation'=>'L',
            'margin_left'=>0,'margin_right'=>0,'margin_top'=>0,'margin_bottom'=>0,
            'margin_header'=>0,'margin_footer'=>0,
            'fontDir'=>array_merge($fontDirectories,[$fontPath]),
            'fontdata'=>array_merge($fontData,[
                'vazirmatn'=>['R'=>'Vazirmatn-Regular.ttf',
                    'B'=>'Vazirmatn-Bold.ttf','useOTL'=>0xFF,'useKashida'=>0],
            ]),
            'default_font'=>'vazirmatn',
            'tempDir'=>sys_get_temp_dir(),
        ]);
        $pdf->SetDirectionality('ltr');
        $pdf->SetTitle('Account Card #'.$recordId);
        $pdf->SetAuthor('Apple ID Management');
        $pdf->SetDisplayMode('real');
        $pdf->AddPage('L');

        // True vector shapes: precise border radius, fine strokes, no invented
        // QR codes / official Apple marks / pixels to blur on print.
        $pdf->SetDrawColor(216,225,235);
        $pdf->SetLineWidth(0.22);
        $pdf->RoundedRect(1.3,1.3,82.4,52.4,3.5,'D');
        $pdf->SetFillColor(247,249,252);
        $pdf->RoundedRect(53.2,3.2,28.3,48.6,3.2,'F');
        $pdf->SetDrawColor(235,239,244);
        $pdf->Line(52.1,4.0,52.1,51.0);

        self::block($pdf,'SECURITY QUESTIONS',4.4,3.8,46.5,3.2,8.4,true,false,'#14263B',50);
        for ($i=1;$i<=3;$i++) {
            $top=7.6+($i-1)*8.0;
            self::block($pdf,$i.'. '.($data['question'.$i]!==''?$data['question'.$i]:'—'),
                4.4,$top,46.6,4.15,7.1,false,null,'#27394C',95);
            $answer = $includeSensitive ? $data['answer'.$i] : '********';
            self::block($pdf,'Answer: '.($answer!==''?$answer:'—'),
                5.0,$top+4.05,45.8,2.8,6.75,false,null,'#52677C',62);
            $pdf->SetDrawColor(220,228,237);
            $pdf->SetLineWidth(0.13);
            $pdf->Line(4.4,$top+7.38,50.7,$top+7.38);
        }

        self::block($pdf,'APPLE ID',4.4,32.05,46.5,2.35,6.85,true,false,'#5F738A',25);
        self::block($pdf,$data['apple_id'],4.4,34.25,46.5,3.8,7.8,true,null,'#162B42',72);
        $pdf->SetDrawColor(224,231,240);$pdf->Line(4.4,38.25,50.7,38.25);

        self::block($pdf,'PASSWORD',4.4,38.5,46.5,2.3,6.85,true,false,'#5F738A',25);
        self::block($pdf,$includeSensitive?$data['password']:'********',
            4.4,40.8,46.5,3.2,7.2,false,null,'#162B42',72);

        $pdf->SetDrawColor(224,231,240);$pdf->Line(4.4,44.5,50.7,44.5);
        foreach ([
            ['FIRST NAME',$data['first_name'],4.4,15.6,24],
            ['LAST NAME',$data['last_name'],20.6,16.8,28],
            ['BIRTHDAY',$data['birthday'],38.3,12.3,24],
        ] as [$label,$value,$x,$w,$max]) {
            self::block($pdf,$label,$x,45.1,$w,2.25,6.5,true,false,'#6B8096',18);
            self::block($pdf,$value,$x,47.65,$w,3.3,7.2,true,null,'#1C2A3A',$max);
        }

        // Graphic panel inspired by the white space of the supplied image,
        // not Apple's official trademark or a fake QR code.
        self::block($pdf,'ACCOUNT CARD',55.3,5.1,23.5,4.2,9.8,true,false,'#1B2E45',23);
        self::block($pdf,'PRIVATE  /  PRINT',55.3,9.6,24.4,3,6.15,false,false,'#8093A6',35);
        $pdf->SetDrawColor(207,219,231);
        $pdf->SetLineWidth(0.6);
        $pdf->Circle(67.2,26,10.4,'D');
        $pdf->SetDrawColor(225,232,240);
        $pdf->SetLineWidth(0.3);
        $pdf->Circle(67.2,26,7.8,'D');
        $pdf->SetFillColor(226,233,241);
        $pdf->Circle(67.2,26,4.9,'F');
        self::block($pdf,'ID',62.9,23.75,8.6,5.0,13.7,true,false,'#607895',6);
        $pdf->SetDrawColor(211,222,235);
        $pdf->Line(58.0,40.0,76.8,40.0);
        self::block($pdf,'SECURE RECORD',57,41,22,3.6,7.7,true,false,'#536981',25);
        self::block($pdf,'Keep this card private',56.1,45.4,24.2,3.6,6.2,false,false,'#71859A',38);
        // The record ID is not confidential and distinguishes otherwise
        // identical records. No secret is placed in document metadata.
        self::block($pdf,'#'.$recordId,75.2,49.5,4.6,1.9,6.1,false,false,'#7B8A9A',14);

        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public static function send(int $id, bool $includeSensitive): never
    {
        $bytes=(new self())->generate($id,$includeSensitive);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="account-card-'.$id.'.pdf"');
        header('Content-Length: '.strlen($bytes));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo $bytes;
        exit;
    }
}
