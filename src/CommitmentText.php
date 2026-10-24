<?php
declare(strict_types=1);

/**
 * Small, deterministic subset of rich HTML suitable for admin editing and mPDF.
 * Never trust HTML from the editor: form POST can bypass client-side validation.
 */
final class CommitmentText
{
    public const MAX_BYTES = 48000;

    public static function clean(string $html): string
    {
        if (strlen($html) > self::MAX_BYTES || !mb_check_encoding($html,'UTF-8')) {
            throw new DomainException('متن تعهدنامه نامعتبر یا بیش از حد طولانی است.');
        }
        if (trim($html)==='') return '';
        $dom=new DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        try {
            $encoded='<?xml encoding="utf-8"?><div id="commitment-root">'.$html.'</div>';
            if (!$dom->loadHTML($encoded, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new DomainException('متن تعهدنامه قابل پردازش نیست.');
            }
            $root=$dom->getElementById('commitment-root');
            if (!$root) {
                foreach ($dom->getElementsByTagName('div') as $element) {
                    if ($element->getAttribute('id')==='commitment-root') {$root=$element;break;}
                }
            }
            if (!$root) throw new DomainException('قالب متن تعهدنامه نامعتبر است.');
            $output='';
            foreach ($root->childNodes as $child) $output.=self::node($child);
            if (strlen($output)>self::MAX_BYTES) {
                throw new DomainException('متن تعهدنامه بیش از حد مجاز است.');
            }
            return trim($output);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function node(DOMNode $node): string
    {
        if ($node->nodeType===XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        }
        if ($node->nodeType!==XML_ELEMENT_NODE) return '';
        $tag=strtolower($node->nodeName);
        // Never preserve contents of executable, external or media elements.
        if (in_array($tag,['script','style','iframe','object','embed','svg','math',
            'noscript','form','input','button','textarea','img','video','audio',
            'picture','meta','link','base','template'],true)) return '';
        $allowed=['p','div','br','strong','b','em','i','u','ul','ol','li',
            'h2','h3','h4','blockquote','span'];
        $children='';
        foreach ($node->childNodes as $child) $children.=self::node($child);
        if (!in_array($tag,$allowed,true)) return $children;
        if ($tag==='br') return '<br>';
        $attrs='';
        if (in_array($tag,['p','div','h2','h3','h4','blockquote'],true)) {
            $style=$node instanceof DOMElement ? strtolower($node->getAttribute('style')) : '';
            if (preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/D',$style,$m)) {
                $attrs=' style="text-align:'.$m[1].'"';
            }
        }
        return '<'.$tag.$attrs.'>'.$children.'</'.$tag.'>';
    }

    public static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(
            ['</p>','</div>','</li>','<br>'],["\n","\n","\n","\n"],$html
        )),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    }
}
