<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Support/ArticleMarcXml.php';
use App\Plugins\Emeroteca\Support\ArticleMarcXml;
$count = 0;
function verify(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException($label); }
    $count++; echo "OK $label\n";
}
$row = ['id'=>12,'reference_key'=>'probe','titolo'=>'På sporet & <test>','sottotitolo'=>'Solidaritet',
    'autori'=>'Petersen, Hans Uwe; Sørensen, Åse','contenitore_titolo'=>'Arbejderhistorie',
    'issn'=>'0107-8461','volume'=>'12','numero'=>'31','anno_pubblicazione'=>1988,'pagine'=>'18–38',
    'note_private'=>'PRIVATE','pdf_path'=>'SECRET.pdf','risorsa_url'=>'PRIVATE-URL','risorsa_pubblica'=>0];
$parse = static function(string $xml): DOMXPath {
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml, LIBXML_NONET)) { throw new RuntimeException('Invalid XML'); }
    $xp = new DOMXPath($doc); $xp->registerNamespace('m','http://www.loc.gov/MARC21/slim'); return $xp;
};
$xml = ArticleMarcXml::format($row,'https://example.org/article/12','periodical:4');
$xp = $parse($xml);
verify(strlen($xp->evaluate('string(//m:controlfield[@tag="008"])'))===40, 'fixed fields have forty positions');
$value = static fn(string $tag,string $code): string => $xp->evaluate("string(//m:datafield[@tag='$tag']/m:subfield[@code='$code'])");
$leader = $xp->evaluate('string(//m:leader)');
verify(strlen($leader)===24 && $leader[6]==='a' && $leader[7]==='a', 'single article is monographic component at Leader/07');
verify($value('100','a')==='Petersen, Hans Uwe' && $value('700','a')==='Sørensen, Åse', 'separate authors retain Unicode and inverted names');
verify($value('245','a')===$row['titolo'] && $value('245','b')==='Solidaritet', 'title and subtitle round trip escaped XML');
verify($value('300','a')==='18–38', 'extent retains exact pagination');
verify($value('773','t')==='Arbejderhistorie' && $value('773','x')==='0107-8461', 'host title and ISSN');
verify($value('773','g')==='Vol. 12, No. 31, 1988, pp. 18–38', 'host enumeration chronology and pages');
verify($value('773','w')==='periodical:4', 'linked host has stable local identifier');
verify(!str_contains($xml,'PRIVATE') && !str_contains($xml,'SECRET'), 'public export excludes private metadata');
$sparse = $parse(ArticleMarcXml::format(['titolo'=>'Only title']));
verify($sparse->evaluate('count(//m:datafield[@tag="773"])')===0.0, 'sparse article invents no host');
verify($sparse->evaluate('count(//m:datafield[@tag="024"])')===0.0, 'missing DOI emits no identifier field');
$standalone = $parse(ArticleMarcXml::format($row));
verify($standalone->evaluate('count(//m:datafield[@tag="773"]/m:subfield[@code="w"])')===0.0, 'standalone citation needs no owned masthead');
echo "SUCCESS $count MARCXML checks\n";
